<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateBatchDemoOrders extends Command
{
    protected $signature = 'crm:create-batch-demo-orders {count=3}';
    protected $description = 'Create test orders that are picked up by the batch CDC sync';

    public function handle(): int
    {
        $count = max(1, (int) $this->argument('count'));
        $createdOrders = [];

        for ($index = 1; $index <= $count; $index++) {
            $customer = Customer::query()->create([
                'external_id' => (string) Str::uuid(),
                'name' => "Batch Demo Customer {$index}",
                'email' => "batch-demo-{$index}@example.com",
                'phone' => '+371 20000001',
            ]);

            $order = Order::query()->create([
                'external_id' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'number' => sprintf('BATCH-Q-%s-%02d', now()->format('YmdHis'), $index),
                'status' => 'draft',
                'sync_mode' => Order::SYNC_MODE_BATCH_DEMO,
                'currency' => 'EUR',
                'total' => '0.00',
                'issued_at' => now()->toDateString(),
            ]);

            foreach ([
                ['sku' => 'BATCH-SKU-01', 'name' => 'Batch Item One', 'qty' => 2, 'unit_price' => '10.50'],
                ['sku' => 'BATCH-SKU-02', 'name' => 'Batch Item Two', 'qty' => 1, 'unit_price' => '4.25'],
            ] as $item) {
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

            $order->update(['status' => 'new']);

            $createdOrders[] = [
                'order_id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'sync_mode' => $order->sync_mode,
            ];
        }

        $this->info("Created {$count} batch-demo order(s).");
        $this->table(['order_id', 'number', 'status', 'sync_mode'], $createdOrders);
        $this->line('Run `php artisan crm:sync-orders-batch` or let the scheduler pick them up.');

        return self::SUCCESS;
    }
}
