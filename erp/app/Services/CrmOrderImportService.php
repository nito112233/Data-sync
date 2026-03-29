<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;

class CrmOrderImportService
{
    public function importOrders(array $orders): array
    {
        $results = [];

        foreach ($orders as $order) {
            $results[] = $this->importOrder($order);
        }

        return $results;
    }

    public function importOrder(array $data): array
    {
        $customer = Customer::updateOrCreate(
            ['external_id' => $data['customer']['external_id']],
            [
                'name' => $data['customer']['name'],
                'email' => $data['customer']['email'] ?? null,
                'phone' => $data['customer']['phone'] ?? null,
            ]
        );

        $salesOrder = SalesOrder::updateOrCreate(
            ['external_id' => $data['order']['external_id']],
            [
                'customer_id' => $customer->id,
                'order_number' => $data['order']['number'],
                'status' => 'received',
                'currency' => $data['order']['currency'],
                'total' => $data['order']['total'],
                'issued_at' => $data['order']['issued_at'] ?? null,
                'received_at' => now(),
            ]
        );

        $salesOrder->items()->delete();

        $rows = [];

        foreach ($data['items'] as $item) {
            $rows[] = [
                'sales_order_id' => $salesOrder->id,
                'sku' => $item['sku'],
                'name' => $item['name'],
                'qty' => $item['qty'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        SalesOrderItem::insert($rows);

        return [
            'external_id' => $data['order']['external_id'],
            'sales_order_id' => $salesOrder->id,
        ];
    }
}
