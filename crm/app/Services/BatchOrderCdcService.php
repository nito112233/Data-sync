<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SyncPipelineState;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Throwable;

class BatchOrderCdcService
{
    public function __construct(
        protected ErpOrderSyncService $erpSync
    ) {
    }

    public function run(?string $pipeline = null, ?int $limit = null): array
    {
        $pipeline = $pipeline ?: (string) config('services.erp.batch_pipeline', 'erp-order-batch-demo');
        $limit = max(1, $limit ?? (int) config('services.erp.batch_max_orders', 100));

        // Persist one row per pipeline so each batch flow keeps its own CDC bookmark.
        $state = SyncPipelineState::query()->firstOrCreate(
            ['pipeline' => $pipeline],
            ['last_status' => SyncPipelineState::STATUS_IDLE]
        );

        $state->forceFill([
            'last_run_started_at' => now(),
            'last_run_finished_at' => null,
            'last_error' => null,
        ])->save();

        $orders = $this->eligibleOrders($state, $limit);

        if ($orders->isEmpty()) {
            $state->forceFill([
                'last_run_finished_at' => now(),
                'last_status' => SyncPipelineState::STATUS_IDLE,
                'last_error' => null,
            ])->save();

            return [
                'pipeline' => $pipeline,
                'selected_count' => 0,
                'synced_count' => 0,
                'cursor_updated_at' => optional($state->last_cursor_updated_at)?->toDateTimeString(),
                'cursor_id' => $state->last_cursor_id,
            ];
        }

        $payload = $this->erpSync->buildBatchPayload($orders);

        try {
            $response = $this->erpSync->sendBatchPayload($payload, [
                'source' => 'batch_cdc',
                'pipeline' => $pipeline,
                'order_count' => $orders->count(),
            ]);

            $results = collect((array) $response->json('results'));

            if ($results->count() !== $orders->count()) {
                throw new RuntimeException('ERP batch response count did not match selected order count.');
            }

            $resultsByExternalId = $results->keyBy('external_id');
            $syncedAt = now();

            foreach ($orders as $order) {
                $result = $resultsByExternalId->get($order->external_id);

                if (! is_array($result) || ! array_key_exists('sales_order_id', $result)) {
                    throw new RuntimeException("ERP batch response was missing result for order {$order->external_id}.");
                }

                // Do not bump updated_at here, or the order would look like a fresh CDC change.
                $order->timestamps = false;
                $order->forceFill([
                    'synced_at' => $syncedAt,
                    'erp_reference' => (string) $result['sales_order_id'],
                ])->save();
                $order->timestamps = true;
            }

            $lastOrder = $orders->last();

            $state->forceFill([
                'last_cursor_updated_at' => $lastOrder->updated_at,
                'last_cursor_id' => $lastOrder->id,
                'last_run_finished_at' => now(),
                'last_status' => SyncPipelineState::STATUS_SUCCEEDED,
                'last_error' => null,
            ])->save();

            return [
                'pipeline' => $pipeline,
                'selected_count' => $orders->count(),
                'synced_count' => $orders->count(),
                'cursor_updated_at' => optional($lastOrder->updated_at)?->toDateTimeString(),
                'cursor_id' => $lastOrder->id,
            ];
        } catch (Throwable $exception) {
            // Keep the cursor unchanged on failure so the same batch window can be retried.
            $state->forceFill([
                'last_run_finished_at' => now(),
                'last_status' => SyncPipelineState::STATUS_FAILED,
                'last_error' => $this->formatErrorMessage($exception),
            ])->save();

            throw $exception;
        }
    }

    protected function eligibleOrders(SyncPipelineState $state, int $limit): Collection
    {
        $query = Order::query()
            ->with(['customer', 'items'])
            ->where('sync_mode', Order::SYNC_MODE_BATCH_DEMO)
            ->where('status', 'new')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit);

        if ($state->last_cursor_updated_at !== null) {
            // Use (updated_at, id) as a stable cursor so rows sharing the same timestamp are not skipped.
            $query->where(function ($builder) use ($state): void {
                $builder
                    ->where('updated_at', '>', $state->last_cursor_updated_at)
                    ->orWhere(function ($nested) use ($state): void {
                        $nested
                            ->where('updated_at', '=', $state->last_cursor_updated_at)
                            ->where('id', '>', (int) $state->last_cursor_id);
                    });
            });
        }

        return $query->get();
    }

    protected function formatErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof \Illuminate\Http\Client\RequestException) {
            $body = trim((string) $exception->response->body());

            return $body !== ''
                ? "HTTP {$exception->response->status()}: {$body}"
                : "HTTP {$exception->response->status()}";
        }

        return trim($exception->getMessage());
    }
}
