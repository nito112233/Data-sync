<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmOrderBatchImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_endpoint_imports_multiple_orders(): void
    {
        putenv('INTEGRATION_KEY=test-key');
        $_ENV['INTEGRATION_KEY'] = 'test-key';
        $_SERVER['INTEGRATION_KEY'] = 'test-key';

        $payload = [
            'orders' => [
                $this->orderPayload('11111111-1111-1111-1111-111111111111', 'BATCH-ERP-001', 'customer-001@example.com'),
                $this->orderPayload('22222222-2222-2222-2222-222222222222', 'BATCH-ERP-002', 'customer-002@example.com'),
            ],
        ];

        $response = $this->postJson('/api/crm/orders/batch', $payload, [
            'X-INTEGRATION-KEY' => 'test-key',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'processed' => 2,
            ]);

        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('sales_orders', 2);
        $this->assertDatabaseCount('sales_order_items', 4);
        $this->assertDatabaseHas('sales_orders', [
            'external_id' => '11111111-1111-1111-1111-111111111111',
            'order_number' => 'BATCH-ERP-001',
        ]);
        $this->assertCount(2, $response->json('results'));
    }

    public function test_batch_endpoint_is_all_or_nothing_when_validation_fails(): void
    {
        putenv('INTEGRATION_KEY=test-key');
        $_ENV['INTEGRATION_KEY'] = 'test-key';
        $_SERVER['INTEGRATION_KEY'] = 'test-key';

        $payload = [
            'orders' => [
                $this->orderPayload('33333333-3333-3333-3333-333333333333', 'BATCH-ERP-003', 'customer-003@example.com'),
                array_replace_recursive(
                    $this->orderPayload('44444444-4444-4444-4444-444444444444', 'BATCH-ERP-004', 'customer-004@example.com'),
                    ['items' => [['sku' => null]]]
                ),
            ],
        ];

        $response = $this->postJson('/api/crm/orders/batch', $payload, [
            'X-INTEGRATION-KEY' => 'test-key',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('sales_order_items', 0);
    }

    protected function orderPayload(string $externalId, string $number, string $email): array
    {
        return [
            'customer' => [
                'external_id' => $externalId,
                'name' => 'ERP Batch Customer',
                'email' => $email,
                'phone' => '20000001',
            ],
            'order' => [
                'external_id' => $externalId,
                'number' => $number,
                'currency' => 'EUR',
                'total' => '25.25',
                'issued_at' => '2026-03-21',
            ],
            'items' => [
                [
                    'sku' => 'SKU-01',
                    'name' => 'Item One',
                    'qty' => 2,
                    'unit_price' => '10.50',
                    'line_total' => '21.00',
                ],
                [
                    'sku' => 'SKU-02',
                    'name' => 'Item Two',
                    'qty' => 1,
                    'unit_price' => '4.25',
                    'line_total' => '4.25',
                ],
            ],
        ];
    }
}
