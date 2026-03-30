<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IntegrationBenchmarkService
{
    public function createSyncOrder(string $label, int $index = 1): Order
    {
        return $this->createOrder($label, $index, Order::SYNC_MODE_ASYNC, false);
    }

    public function createAsyncOrder(string $label, int $index = 1): Order
    {
        return $this->createOrder($label, $index, Order::SYNC_MODE_ASYNC, true);
    }

    public function createBatchOrder(string $label, int $index = 1): Order
    {
        return $this->createOrder($label, $index, Order::SYNC_MODE_BATCH_DEMO, true);
    }

    public function waitForOrdersSynced(array $orderIds, int $timeoutMs, int $pollMs): array
    {
        $startedAt = microtime(true);
        $deadline = $startedAt + ($timeoutMs / 1000);
        $pollUs = max(1, $pollMs) * 1000;

        do {
            $orders = Order::query()
                ->whereIn('id', $orderIds)
                ->get()
                ->keyBy('id');

            $syncedCount = collect($orderIds)
                ->filter(fn (int $orderId): bool => ! empty($orders->get($orderId)?->synced_at))
                ->count();

            if ($syncedCount === count($orderIds)) {
                return [
                    'completed' => true,
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                    'synced_count' => $syncedCount,
                ];
            }

            usleep($pollUs);
        } while (microtime(true) < $deadline);

        $finalOrders = Order::query()
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');

        $finalSyncedCount = collect($orderIds)
            ->filter(fn (int $orderId): bool => ! empty($finalOrders->get($orderId)?->synced_at))
            ->count();

        return [
            'completed' => false,
            'elapsed_ms' => $this->elapsedMs($startedAt),
            'synced_count' => $finalSyncedCount,
        ];
    }

    public function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    protected function createOrder(string $label, int $index, string $syncMode, bool $readyForSync): Order
    {
        $customer = Customer::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => "{$label} Customer {$index}",
            'email' => Str::slug($label)."-{$index}@example.com",
            'phone' => '+371 20000001',
        ]);

        $order = Order::query()->create([
            'external_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'number' => sprintf('%s-%s-%02d', Str::upper(Str::slug($label, '-')), now()->format('YmdHisv'), $index),
            'status' => 'draft',
            'sync_mode' => $syncMode,
            'currency' => 'EUR',
            'total' => '0.00',
            'issued_at' => now()->toDateString(),
        ]);

        foreach ($this->defaultItems() as $item) {
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

        if ($readyForSync) {
            $order->update(['status' => 'new']);
        }

        return $order->fresh(['customer', 'items']);
    }

    protected function defaultItems(): Collection
    {
        return collect([
            ['sku' => 'BENCH-SKU-01', 'name' => 'Benchmark Item One', 'qty' => 2, 'unit_price' => '10.50'],
            ['sku' => 'BENCH-SKU-02', 'name' => 'Benchmark Item Two', 'qty' => 1, 'unit_price' => '4.25'],
        ]);
    }
}
