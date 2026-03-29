<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SyncPipelineState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BatchOrderSyncCommandTest extends TestCase
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
        config()->set('services.erp.batch_pipeline', 'erp-order-batch-demo');
        config()->set('services.erp.batch_max_orders', 100);
        config()->set('services.erp.batch_sync_interval_seconds', 10);
    }

    public function test_batch_sync_command_sends_batch_demo_orders_and_advances_cursor(): void
    {
        Queue::fake();
        $capturedPayload = [];

        $orderA = $this->createOrderWithItems('BATCH-DEMO-001', Order::SYNC_MODE_BATCH_DEMO);
        $orderB = $this->createOrderWithItems('BATCH-DEMO-002', Order::SYNC_MODE_BATCH_DEMO);
        $asyncOrder = $this->createOrderWithItems('ASYNC-ONLY-001', Order::SYNC_MODE_ASYNC);

        Http::fake(function (Request $request) use (&$capturedPayload, $orderA, $orderB) {
            $capturedPayload = $request->data();

            return Http::response([
                'ok' => true,
                'processed' => 2,
                'results' => [
                    ['external_id' => $orderA->external_id, 'sales_order_id' => 8101],
                    ['external_id' => $orderB->external_id, 'sales_order_id' => 8102],
                ],
            ], 200);
        });

        $exitCode = Artisan::call('crm:sync-orders-batch');
        $output = Artisan::output();

        $orderA->refresh();
        $orderB->refresh();
        $asyncOrder->refresh();

        $state = SyncPipelineState::query()->firstOrFail();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Synced 2 batch-demo order(s)', $output);
        $this->assertCount(2, $capturedPayload['orders']);
        $this->assertSame($orderA->external_id, $capturedPayload['orders'][0]['order']['external_id']);
        $this->assertSame($orderB->external_id, $capturedPayload['orders'][1]['order']['external_id']);
        $this->assertNotNull($orderA->synced_at);
        $this->assertNotNull($orderB->synced_at);
        $this->assertSame('8101', $orderA->erp_reference);
        $this->assertSame('8102', $orderB->erp_reference);
        $this->assertNull($asyncOrder->synced_at);
        $this->assertSame(SyncPipelineState::STATUS_SUCCEEDED, $state->last_status);
        $this->assertSame($orderB->id, $state->last_cursor_id);
    }

    public function test_batch_sync_command_uses_timestamp_and_id_cursor_tiebreaker(): void
    {
        $capturedPayload = [];
        $orderA = $this->createOrderWithItems('BATCH-TIE-001', Order::SYNC_MODE_BATCH_DEMO);
        $orderB = $this->createOrderWithItems('BATCH-TIE-002', Order::SYNC_MODE_BATCH_DEMO);
        $cursorTime = Carbon::parse('2026-03-21 12:00:00');

        Order::query()->whereKey($orderA->id)->update(['updated_at' => $cursorTime]);
        Order::query()->whereKey($orderB->id)->update(['updated_at' => $cursorTime]);

        SyncPipelineState::query()->create([
            'pipeline' => 'erp-order-batch-demo',
            'last_cursor_updated_at' => $cursorTime,
            'last_cursor_id' => $orderA->id,
            'last_status' => SyncPipelineState::STATUS_IDLE,
        ]);

        Http::fake(function (Request $request) use (&$capturedPayload, $orderB) {
            $capturedPayload = $request->data();

            return Http::response([
                'ok' => true,
                'processed' => 1,
                'results' => [
                    ['external_id' => $orderB->external_id, 'sales_order_id' => 8202],
                ],
            ], 200);
        });

        $exitCode = Artisan::call('crm:sync-orders-batch');

        $state = SyncPipelineState::query()->firstOrFail();

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $capturedPayload['orders']);
        $this->assertSame($orderB->external_id, $capturedPayload['orders'][0]['order']['external_id']);
        $this->assertSame($orderB->id, $state->last_cursor_id);
    }

    public function test_batch_sync_command_failure_keeps_cursor_unchanged(): void
    {
        $order = $this->createOrderWithItems('BATCH-FAIL-001', Order::SYNC_MODE_BATCH_DEMO);

        SyncPipelineState::query()->create([
            'pipeline' => 'erp-order-batch-demo',
            'last_status' => SyncPipelineState::STATUS_IDLE,
        ]);

        Http::fake([
            'http://erp.test/api/crm/orders/batch' => Http::response([
                'message' => 'ERP unavailable',
            ], 503),
        ]);

        $exitCode = Artisan::call('crm:sync-orders-batch');
        $output = Artisan::output();

        $state = SyncPipelineState::query()->firstOrFail();
        $order->refresh();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('status code 503', $output);
        $this->assertSame(SyncPipelineState::STATUS_FAILED, $state->last_status);
        $this->assertNull($state->last_cursor_updated_at);
        $this->assertNull($state->last_cursor_id);
        $this->assertNull($order->synced_at);
    }

    public function test_updating_batch_demo_item_touches_parent_order(): void
    {
        $order = $this->createOrderWithItems('BATCH-ITEM-001', Order::SYNC_MODE_BATCH_DEMO);
        $originalUpdatedAt = $order->updated_at->copy();

        Carbon::setTestNow($originalUpdatedAt->copy()->addSeconds(10));

        $order->items()->firstOrFail()->update([
            'name' => 'Updated Batch Item',
        ]);

        Carbon::setTestNow();

        $order->refresh();

        $this->assertTrue($order->updated_at->gt($originalUpdatedAt));
    }

    public function test_updating_customer_touches_related_batch_demo_order(): void
    {
        $order = $this->createOrderWithItems('BATCH-CUSTOMER-001', Order::SYNC_MODE_BATCH_DEMO);
        $originalUpdatedAt = $order->updated_at->copy();

        Carbon::setTestNow($originalUpdatedAt->copy()->addSeconds(10));

        $order->customer->update([
            'phone' => '+371 29990001',
        ]);

        Carbon::setTestNow();

        $order->refresh();

        $this->assertTrue($order->updated_at->gt($originalUpdatedAt));
    }

    protected function createOrderWithItems(string $number, string $syncMode): Order
    {
        $customer = Customer::query()->create([
            'external_id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Batch Test Customer',
            'email' => strtolower($number).'@example.com',
            'phone' => '+371 20000001',
        ]);

        $order = Order::query()->create([
            'external_id' => (string) \Illuminate\Support\Str::uuid(),
            'customer_id' => $customer->id,
            'number' => $number,
            'status' => 'draft',
            'sync_mode' => $syncMode,
            'currency' => 'EUR',
            'total' => '0.00',
            'issued_at' => '2026-03-21',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'sku' => 'SKU-01',
            'name' => 'Item One',
            'qty' => 2,
            'unit_price' => '10.50',
            'line_total' => '21.00',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'sku' => 'SKU-02',
            'name' => 'Item Two',
            'qty' => 1,
            'unit_price' => '4.25',
            'line_total' => '4.25',
        ]);

        $order->update(['status' => 'new']);

        return $order->load(['customer', 'items']);
    }
}
