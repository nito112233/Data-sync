<?php

namespace Tests\Feature;

use App\Jobs\ProcessOutboxMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AsyncOrderOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.erp.url', 'http://erp.test');
        config()->set('services.erp.integration_key', 'test-key');
        config()->set('services.erp.timeout_seconds', 10);
        config()->set('services.erp.retry_attempts', 1);
        config()->set('services.erp.retry_backoff_ms', 1);
        config()->set('services.erp.outbox_max_attempts', 3);
        config()->set('services.erp.outbox_retry_delay_seconds', 5);
        config()->set('services.erp.outbox_queue', 'erp-sync');
    }

    public function test_draft_order_creation_does_not_create_outbox_activity(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();

        $order = Order::query()->create([
            'external_id' => '3cb7f850-a6be-4929-8c8d-ea6630a247d3',
            'customer_id' => $customer->id,
            'number' => 'DRAFT-001',
            'status' => 'draft',
            'currency' => 'EUR',
            'total' => '0.00',
            'issued_at' => '2026-03-17',
        ]);

        $this->logStep('START test_draft_order_creation_does_not_create_outbox_activity');
        $this->logOrderSnapshot('CRM draft order', $order->load('customer', 'items'));
        $this->logJson('Async sync result', [
            'outbox_messages' => 0,
            'queued_jobs' => 0,
        ]);

        $this->assertDatabaseCount('outbox_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_status_change_to_new_creates_one_outbox_message_and_dispatches_job(): void
    {
        Queue::fake();

        $order = $this->createDraftOrderWithItems();
        $this->logStep('START test_status_change_to_new_creates_one_outbox_message_and_dispatches_job');
        $this->logOrderSnapshot('CRM order before status change', $order);

        $order->update([
            'status' => 'new',
            'number' => 'INV-ASYNC-001',
        ]);

        $this->assertDatabaseCount('outbox_messages', 1);

        $outbox = OutboxMessage::query()->firstOrFail();
        $this->logOutboxSnapshot('Outbox after status change', $outbox);

        $this->assertSame(OutboxMessage::STATUS_PENDING, $outbox->status);
        $this->assertSame('order_ready_for_sync', $outbox->event_type);
        $this->assertSame('INV-ASYNC-001', $outbox->payload['order']['number']);
        $this->assertSame('20000001', $outbox->payload['customer']['phone']);
        $this->assertSame('SKU-01', $outbox->payload['items'][0]['sku']);

        Queue::assertPushed(ProcessOutboxMessage::class, function (ProcessOutboxMessage $job) use ($outbox) {
            return $job->outboxMessageId === $outbox->id;
        });
    }

    public function test_updating_new_order_item_creates_new_outbox_with_latest_snapshot(): void
    {
        Queue::fake();

        $order = $this->createDraftOrderWithItems();
        $order->update(['status' => 'new']);
        $this->logStep('START test_updating_new_order_item_creates_new_outbox_with_latest_snapshot');
        $this->logOrderSnapshot('CRM order before item update', $order);

        Queue::assertPushed(ProcessOutboxMessage::class, 1);

        $item = $order->items()->firstOrFail();
        $item->update([
            'name' => '  Updated Item One  ',
            'unit_price' => '12.00',
        ]);

        $this->assertDatabaseCount('outbox_messages', 2);

        $latestOutbox = OutboxMessage::query()->latest('id')->firstOrFail();
        $this->logOutboxSnapshot('Latest outbox after item update', $latestOutbox);

        $this->assertSame('order_updated_after_ready', $latestOutbox->event_type);
        $this->assertSame('Updated Item One', $latestOutbox->payload['items'][0]['name']);
        $this->assertSame('24.00', $latestOutbox->payload['items'][0]['line_total']);
        $this->assertSame('28.25', $latestOutbox->payload['order']['total']);

        Queue::assertPushed(ProcessOutboxMessage::class, 2);
    }

    public function test_processing_outbox_success_marks_message_synced_and_updates_order(): void
    {
        Queue::fake();
        $capturedPayload = [];

        $order = $this->createDraftOrderWithItems();
        $order->update(['status' => 'new']);

        $outbox = OutboxMessage::query()->firstOrFail();
        $this->logStep('START test_processing_outbox_success_marks_message_synced_and_updates_order');
        $this->logOrderSnapshot('CRM order before processing outbox', $order);
        $this->logOutboxState('Outbox before processing', $outbox);

        Http::fake(function (Request $request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'ok' => true,
                'sales_order_id' => 7001,
            ], 200);
        });

        $job = new ProcessOutboxMessage($outbox->id);
        $job->handle(app(\App\Services\ErpOrderSyncService::class));

        $outbox->refresh();
        $order->refresh();

        $this->logJson('ERP payload sent', $capturedPayload);
        $this->logJson('ERP result', [
            'result' => 'HTTP 200',
            'sales_order_id' => 7001,
        ]);
        $this->logOutboxState('Outbox after successful sync', $outbox);
        $this->logOrderSnapshot('CRM order after successful sync', $order);

        $this->assertSame(OutboxMessage::STATUS_SYNCED, $outbox->status);
        $this->assertSame(1, $outbox->attempts);
        $this->assertNotNull($outbox->processed_at);
        $this->assertSame('7001', $order->erp_reference);
        $this->assertNotNull($order->synced_at);

        Http::assertSentCount(1);
    }

    public function test_erp_offline_does_not_block_save_and_outbox_remains_retryable(): void
    {
        Queue::fake();
        $capturedPayload = [];

        $order = $this->createDraftOrderWithItems();
        $order->update(['status' => 'new']);

        $outbox = OutboxMessage::query()->firstOrFail();
        $this->logStep('START test_erp_offline_does_not_block_save_and_outbox_remains_retryable');
        $this->logOrderSnapshot('CRM order before processing outbox', $order);
        $this->logOutboxState('Outbox before processing', $outbox);

        Http::fake(function (Request $request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            throw new ConnectionException('ERP offline');
        });

        $job = new ProcessOutboxMessage($outbox->id);
        $job->handle(app(\App\Services\ErpOrderSyncService::class));

        $outbox->refresh();
        $order->refresh();

        $this->logJson('ERP payload sent', $capturedPayload);
        $this->logJson('ERP result', [
            'result' => 'connection_error',
            'message' => 'ERP offline',
        ]);
        $this->logOutboxState('Outbox after retry scheduling', $outbox);
        $this->logOrderSnapshot('CRM order after failed sync attempt', $order);

        $this->assertSame(OutboxMessage::STATUS_PENDING, $outbox->status);
        $this->assertSame(1, $outbox->attempts);
        $this->assertSame('ERP offline', $outbox->last_error);
        $this->assertNull($outbox->processed_at);
        $this->assertNotNull($outbox->available_at);
        $this->assertNull($order->synced_at);
        $this->assertNull($order->erp_reference);

        Queue::assertPushed(ProcessOutboxMessage::class, 2);
    }

    protected function createDraftOrderWithItems(): Order
    {
        $customer = $this->createCustomer();

        $order = Order::query()->create([
            'external_id' => '2d9354ca-45a8-4a7a-bde5-bbcdfd08f408',
            'customer_id' => $customer->id,
            'number' => '  INV-ASYNC-001  ',
            'status' => 'draft',
            'currency' => ' eur ',
            'total' => '999.99',
            'issued_at' => '2026-03-17',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'sku' => ' sku-01 ',
            'name' => '  Item One  ',
            'qty' => 2,
            'unit_price' => '10.50',
            'line_total' => '999.99',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'sku' => ' sku-02 ',
            'name' => ' Item Two ',
            'qty' => 1,
            'unit_price' => '4.25',
            'line_total' => '0.01',
        ]);

        return $order->load(['customer', 'items']);
    }

    protected function createCustomer(): Customer
    {
        return Customer::query()->create([
            'external_id' => 'f2bad4db-a140-49c9-9d21-7c4734545cc9',
            'name' => '  SIA Async  ',
            'email' => '  ASYNC@example.com  ',
            'phone' => '+371 20000001',
        ]);
    }

    protected function logOrderSnapshot(string $label, Order $order): void
    {
        $order->loadMissing(['customer', 'items']);

        $this->logJson($label, [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'status' => $order->status,
            'currency' => $order->currency,
            'stored_total' => (string) $order->total,
            'synced_at' => optional($order->synced_at)?->toDateTimeString(),
            'erp_reference' => $order->erp_reference,
            'customer' => [
                'external_id' => $order->customer->external_id,
                'name' => $order->customer->name,
                'email' => $order->customer->email,
                'phone' => $order->customer->phone,
            ],
            'items' => $order->items->map(fn (OrderItem $item) => [
                'sku' => $item->sku,
                'name' => $item->name,
                'qty' => $item->qty,
                'unit_price' => (string) $item->unit_price,
                'line_total' => (string) $item->line_total,
            ])->all(),
        ]);
    }

    protected function logOutboxSnapshot(string $label, OutboxMessage $outbox): void
    {
        $this->logJson($label, [
            'outbox_id' => $outbox->id,
            'aggregate_type' => $outbox->aggregate_type,
            'aggregate_id' => $outbox->aggregate_id,
            'event_type' => $outbox->event_type,
            'status' => $outbox->status,
            'attempts' => $outbox->attempts,
            'last_error' => $outbox->last_error,
            'available_at' => optional($outbox->available_at)?->toDateTimeString(),
            'processed_at' => optional($outbox->processed_at)?->toDateTimeString(),
            'payload' => $outbox->payload,
        ]);
    }

    protected function logOutboxState(string $label, OutboxMessage $outbox): void
    {
        $this->logJson($label, [
            'outbox_id' => $outbox->id,
            'event_type' => $outbox->event_type,
            'status' => $outbox->status,
            'attempts' => $outbox->attempts,
            'last_error' => $outbox->last_error,
            'available_at' => optional($outbox->available_at)?->toDateTimeString(),
            'processed_at' => optional($outbox->processed_at)?->toDateTimeString(),
        ]);
    }

    protected function logJson(string $label, array $payload): void
    {
        $this->logBlock($label, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function logStep(string $message): void
    {
        fwrite(STDOUT, "\n[AsyncOrderOutboxTest] {$message}\n");
    }

    protected function logBlock(string $label, string|false $content): void
    {
        $body = $content !== false ? $content : '[unable to encode]';

        fwrite(STDOUT, "\n--- {$label} ---\n{$body}\n");
    }
}
