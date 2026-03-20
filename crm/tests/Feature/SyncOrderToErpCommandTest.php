<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncOrderToErpCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.erp.url', 'http://erp.test');
        config()->set('services.erp.integration_key', 'test-key');
        config()->set('services.erp.timeout_seconds', 10);
        config()->set('services.erp.retry_attempts', 3);
        config()->set('services.erp.retry_backoff_ms', 1);
    }

    public function test_it_transforms_payload_and_marks_order_as_synced(): void
    {
        $order = $this->createOrderWithMessyData();
        $capturedPayload = [];
        $attemptLog = [];

        $this->logStep('START test_it_transforms_payload_and_marks_order_as_synced');
        $this->logOrderSnapshot('CRM source order before sync', $order);

        Http::fake(function (Request $request) use (&$capturedPayload, &$attemptLog) {
            $capturedPayload = $request->data();
            $attemptLog[] = [
                'attempt' => 1,
                'result' => 'HTTP 200',
                'sales_order_id' => 321,
            ];

            return Http::response([
                'ok' => true,
                'sales_order_id' => 321,
            ], 200);
        });

        [$exitCode, $output] = $this->runSyncCommand($order);

        $order->refresh();
        $order->customer->refresh();

        $this->logErpExchange($capturedPayload, $attemptLog);
        $this->logCommandOutput($output);
        $this->logOrderSnapshot('CRM source order after sync', $order);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Synced order', $output);
        $this->assertNotNull($order->synced_at);
        $this->assertSame('321', $order->erp_reference);
        $this->assertSame('+371 20000001', $order->customer->phone);
        $this->assertSame('  TEST@Example.COM  ', $order->customer->email);

        $this->assertSame('SIA Alpha', $capturedPayload['customer']['name']);
        $this->assertSame('test@example.com', $capturedPayload['customer']['email']);
        $this->assertSame('20000001', $capturedPayload['customer']['phone']);
        $this->assertSame('INV-2026-001', $capturedPayload['order']['number']);
        $this->assertSame('EUR', $capturedPayload['order']['currency']);
        $this->assertSame('25.25', $capturedPayload['order']['total']);
        $this->assertSame('2026-03-12', $capturedPayload['order']['issued_at']);
        $this->assertSame('SKU-01', $capturedPayload['items'][0]['sku']);
        $this->assertSame('Item One', $capturedPayload['items'][0]['name']);
        $this->assertSame(2, $capturedPayload['items'][0]['qty']);
        $this->assertSame('10.50', $capturedPayload['items'][0]['unit_price']);
        $this->assertSame('21.00', $capturedPayload['items'][0]['line_total']);
        $this->assertSame('SKU-02', $capturedPayload['items'][1]['sku']);
        $this->assertSame('Item Two', $capturedPayload['items'][1]['name']);
        $this->assertSame(1, $capturedPayload['items'][1]['qty']);
        $this->assertSame('4.25', $capturedPayload['items'][1]['unit_price']);
        $this->assertSame('4.25', $capturedPayload['items'][1]['line_total']);
    }

    public function test_it_retries_transient_http_failures_and_eventually_succeeds(): void
    {
        $order = $this->createOrderWithMessyData();
        $attempts = 0;
        $capturedPayload = [];
        $attemptLog = [];

        $this->logStep('START test_it_retries_transient_http_failures_and_eventually_succeeds');
        $this->logOrderSnapshot('CRM source order before sync', $order);

        Http::fake(function (Request $request) use (&$attempts, &$capturedPayload, &$attemptLog) {
            $attempts++;

            if ($capturedPayload === []) {
                $capturedPayload = $request->data();
            }

            if ($attempts === 1) {
                $attemptLog[] = [
                    'attempt' => $attempts,
                    'result' => 'HTTP 503',
                    'message' => 'ERP temporarily unavailable',
                ];

                return Http::response(['message' => 'ERP temporarily unavailable'], 503);
            }

            $attemptLog[] = [
                'attempt' => $attempts,
                'result' => 'HTTP 200',
                'sales_order_id' => 654,
            ];

            return Http::response(['ok' => true, 'sales_order_id' => 654], 200);
        });

        [$exitCode, $output] = $this->runSyncCommand($order);

        $order->refresh();

        $this->logErpExchange($capturedPayload, $attemptLog);
        $this->logCommandOutput($output);
        $this->logOrderSnapshot('CRM source order after sync', $order);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('retrying...', $output);
        $this->assertStringContainsString('Synced order', $output);
        $this->assertSame('654', $order->erp_reference);
        $this->assertSame(2, $attempts);
    }

    public function test_it_retries_connection_failures_and_leaves_order_unsynced_on_final_failure(): void
    {
        $order = $this->createOrderWithMessyData();
        $attempts = 0;
        $capturedPayload = [];
        $attemptLog = [];

        $this->logStep('START test_it_retries_connection_failures_and_leaves_order_unsynced_on_final_failure');
        $this->logOrderSnapshot('CRM source order before sync', $order);

        Http::fake(function (Request $request) use (&$attempts, &$capturedPayload, &$attemptLog) {
            $attempts++;

            if ($capturedPayload === []) {
                $capturedPayload = $request->data();
            }

            $attemptLog[] = [
                'attempt' => $attempts,
                'result' => 'connection_error',
                'message' => 'ERP offline',
            ];

            throw new ConnectionException('ERP offline');
        });

        [$exitCode, $output] = $this->runSyncCommand($order);

        $order->refresh();

        $this->logErpExchange($capturedPayload, $attemptLog);
        $this->logCommandOutput($output);
        $this->logOrderSnapshot('CRM source order after failed sync', $order);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('ERP unavailable after 3 attempts', $output);
        $this->assertSame(3, $attempts);
        $this->assertNull($order->synced_at);
        $this->assertNull($order->erp_reference);
    }

    public function test_it_does_not_retry_non_retryable_validation_failures(): void
    {
        $order = $this->createOrderWithMessyData();
        $attempts = 0;
        $capturedPayload = [];
        $attemptLog = [];

        $this->logStep('START test_it_does_not_retry_non_retryable_validation_failures');
        $this->logOrderSnapshot('CRM source order before sync', $order);

        Http::fake(function (Request $request) use (&$attempts, &$capturedPayload, &$attemptLog) {
            $attempts++;

            $capturedPayload = $request->data();
            $attemptLog[] = [
                'attempt' => $attempts,
                'result' => 'HTTP 422',
                'message' => 'Validation failed',
            ];

            return Http::response([
                'message' => 'Validation failed',
            ], 422);
        });

        [$exitCode, $output] = $this->runSyncCommand($order);

        $order->refresh();

        $this->logErpExchange($capturedPayload, $attemptLog);
        $this->logCommandOutput($output);
        $this->logOrderSnapshot('CRM source order after validation failure', $order);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('ERP rejected request: HTTP 422', $output);
        $this->assertNull($order->synced_at);
        $this->assertNull($order->erp_reference);
        $this->assertSame(1, $attempts);
    }

    protected function createOrderWithMessyData(): Order
    {
        $customer = Customer::query()->create([
            'external_id' => 'b8d350f4-c13f-48c6-b1ca-0e3261e6138f',
            'name' => '  SIA Alpha  ',
            'email' => '  TEST@Example.COM  ',
            'phone' => '+371 20000001',
        ]);

        $order = Order::query()->create([
            'external_id' => '3e7f14d0-a96b-4675-9b72-6cd63c153654',
            'customer_id' => $customer->id,
            'number' => '  INV-2026-001  ',
            'currency' => ' eur ',
            'total' => '999.99',
            'issued_at' => '2026-03-12',
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

    protected function runSyncCommand(Order $order): array
    {
        $this->logStep('Running artisan command crm:sync-order '.$order->id);

        $exitCode = Artisan::call('crm:sync-order', ['orderId' => $order->id]);
        $output = Artisan::output();

        return [$exitCode, $output];
    }

    protected function logOrderSnapshot(string $label, Order $order): void
    {
        $order->loadMissing(['customer', 'items']);

        $this->logJson($label, [
            'order_id' => $order->id,
            'order_number' => $order->number,
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

    protected function logCommandOutput(string $output): void
    {
        $this->logBlock('Command output', trim($output) !== '' ? trim($output) : '[no command output]');
    }

    protected function logErpExchange(array $payload, array $attemptLog): void
    {
        $this->logJson('ERP payload sent', $payload);
        $this->logJson('ERP attempt summary', $attemptLog);
    }

    protected function logJson(string $label, array $payload): void
    {
        $this->logBlock($label, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function logStep(string $message): void
    {
        fwrite(STDOUT, "\n[SyncOrderToErpCommandTest] {$message}\n");
    }

    protected function logBlock(string $label, string|false $content): void
    {
        $body = $content !== false ? $content : '[unable to encode]';

        fwrite(STDOUT, "\n--- {$label} ---\n{$body}\n");
    }
}
