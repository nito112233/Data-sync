<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAsyncSyncOrders extends Command
{
    protected $signature = 'crm:create-async-orders {count=3}';
    protected $description = 'Create test orders that enter the async ERP outbox queue';

    public function handle(): int
    {
        $count = max(1, (int) $this->argument('count'));
        $createdOrders = [];

        for ($index = 1; $index <= $count; $index++) {
            $customer = $this->createCustomer($index);
            $order = $this->createDraftOrder($customer, $index);

            $this->createItems($order);

            // Flip to "new" only after items exist so exactly one outbox message is created.
            $order->update(['status' => 'new']);
            $order->refresh();

            $createdOrders[] = [
                'order_id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'outbox_messages' => $order->outboxMessages()->count(),
            ];
        }

        $this->info("Created {$count} async test order(s).");
        $this->table(
            ['order_id', 'number', 'status', 'outbox_messages'],
            $createdOrders
        );
        return self::SUCCESS;
    }

    protected function createCustomer(int $index): Customer
    {
        return Customer::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => "Async Queue Customer {$index}",
            'email' => "async-queue-{$index}@example.com",
            'phone' => '+371 20000001',
        ]);
    }

    protected function createDraftOrder(Customer $customer, int $index): Order
    {
        return Order::query()->create([
            'external_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'number' => sprintf('ASYNC-Q-%s-%02d', now()->format('YmdHis'), $index),
            'status' => 'draft',
            'currency' => 'EUR',
            'total' => '0.00',
            'issued_at' => now()->toDateString(),
        ]);
    }

    protected function createItems(Order $order): void
    {
        $items = [
            [
                'sku' => 'ASYNC-SKU-01',
                'name' => 'Async Item One',
                'qty' => 2,
                'unit_price' => '10.50',
            ],
            [
                'sku' => 'ASYNC-SKU-02',
                'name' => 'Async Item Two',
                'qty' => 1,
                'unit_price' => '4.25',
            ],
        ];

        foreach ($items as $item) {
            $qty = (int) $item['qty'];
            $unitPrice = (float) $item['unit_price'];

            OrderItem::query()->create([
                'order_id' => $order->id,
                'sku' => $item['sku'],
                'name' => $item['name'],
                'qty' => $qty,
                'unit_price' => number_format($unitPrice, 2, '.', ''),
                'line_total' => number_format($qty * $unitPrice, 2, '.', ''),
            ]);
        }
    }
}
