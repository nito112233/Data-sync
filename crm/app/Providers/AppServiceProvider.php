<?php

namespace App\Providers;

use App\Jobs\ProcessOutboxMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OutboxMessage;
use App\Services\ErpOrderSyncService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Order::saved(function (Order $order): void {
            if ($order->sync_mode !== Order::SYNC_MODE_ASYNC || $order->status !== 'new' || ! $order->items()->exists()) {
                return;
            }

            $eventType = null;

            if ($order->wasRecentlyCreated || ($order->wasChanged('status') && $order->getOriginal('status') !== 'new')) {
                $eventType = 'order_ready_for_sync';
            } elseif ($order->getOriginal('status') === 'new' && $order->wasChanged([
                'customer_id',
                'number',
                'currency',
                'total',
                'issued_at',
            ])) {
                $eventType = 'order_updated_after_ready';
            }

            if ($eventType === null) {
                return;
            }

            // Save the exact ERP snapshot in CRM first. The queue worker will send this later.
            $outboxMessage = OutboxMessage::query()->create([
                'aggregate_type' => OutboxMessage::AGGREGATE_TYPE_ORDER,
                'aggregate_id' => $order->id,
                'event_type' => $eventType,
                'payload' => app(ErpOrderSyncService::class)->buildPayload($order),
                'status' => OutboxMessage::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now(),
            ]);

            ProcessOutboxMessage::dispatch($outboxMessage->id)
                ->onQueue((string) config('services.erp.outbox_queue', 'erp-sync'))
                ->afterCommit();
        });

        OrderItem::saved(function (OrderItem $item): void {
            $order = $item->order()->first();

            if (! $order) {
                return;
            }

            if ($order->sync_mode === Order::SYNC_MODE_BATCH_DEMO && $order->status === 'new') {
                Order::query()->whereKey($order->id)->update(['updated_at' => now()]);
            }

            if ($order->sync_mode !== Order::SYNC_MODE_ASYNC || $order->status !== 'new' || ! $order->items()->exists()) {
                return;
            }

            // Item changes are part of the order, so they also create a fresh async snapshot.
            $outboxMessage = OutboxMessage::query()->create([
                'aggregate_type' => OutboxMessage::AGGREGATE_TYPE_ORDER,
                'aggregate_id' => $order->id,
                'event_type' => 'order_updated_after_ready',
                'payload' => app(ErpOrderSyncService::class)->buildPayload($order),
                'status' => OutboxMessage::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now(),
            ]);

            ProcessOutboxMessage::dispatch($outboxMessage->id)
                ->onQueue((string) config('services.erp.outbox_queue', 'erp-sync'))
                ->afterCommit();
        });

        Customer::saved(function (Customer $customer): void {
            Order::query()
                ->where('customer_id', $customer->id)
                ->where('sync_mode', Order::SYNC_MODE_BATCH_DEMO)
                ->where('status', 'new')
                ->update(['updated_at' => now()]);
        });
    }
}
