<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OutboxMessage;
use App\Services\ErpOrderSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOutboxMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $outboxMessageId
    ) {
        $this->onQueue((string) config('services.erp.outbox_queue', 'erp-sync'));
    }

    public function handle(ErpOrderSyncService $erpSync): void
    {
        $outboxMessage = OutboxMessage::query()->find($this->outboxMessageId);

        if (! $outboxMessage || ! in_array($outboxMessage->status, [
            OutboxMessage::STATUS_PENDING,
            OutboxMessage::STATUS_PROCESSING,
        ], true)) {
            return;
        }

        $outboxMessage->forceFill([
            'status' => OutboxMessage::STATUS_PROCESSING,
            'last_error' => null,
        ])->save();

        $attempt = $outboxMessage->attempts + 1;

        try {
            $response = $erpSync->sendPayload(
                $outboxMessage->payload,
                [
                    'source' => 'outbox',
                    'outbox_message_id' => $outboxMessage->id,
                    'aggregate_type' => $outboxMessage->aggregate_type,
                    'aggregate_id' => $outboxMessage->aggregate_id,
                ]
            );

            $salesOrderId = (string) $response->json('sales_order_id');

            $outboxMessage->forceFill([
                'status' => OutboxMessage::STATUS_SYNCED,
                'attempts' => $attempt,
                'last_error' => null,
                'processed_at' => now(),
                'available_at' => now(),
            ])->save();

            if ($outboxMessage->aggregate_type === OutboxMessage::AGGREGATE_TYPE_ORDER) {
                $order = Order::query()->find($outboxMessage->aggregate_id);

                if ($order) {
                    $order->forceFill([
                        'synced_at' => now(),
                        'erp_reference' => $salesOrderId,
                    ])->save();
                }
            }
        } catch (Throwable $exception) {
            $message = trim($exception->getMessage());

            if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                $body = trim((string) $exception->response->body());
                $message = $body !== ''
                    ? "HTTP {$exception->response->status()}: {$body}"
                    : "HTTP {$exception->response->status()}";
            }

            $maxAttempts = max(1, (int) config('services.erp.outbox_max_attempts', 3));
            $delaySeconds = max(1, (int) config('services.erp.outbox_retry_delay_seconds', 30));
            $status = $attempt >= $maxAttempts
                ? OutboxMessage::STATUS_FAILED
                : OutboxMessage::STATUS_PENDING;

            $outboxMessage->forceFill([
                'status' => $status,
                'attempts' => $attempt,
                'last_error' => $message,
                'available_at' => now()->addSeconds($delaySeconds * $attempt),
                'processed_at' => $status === OutboxMessage::STATUS_FAILED ? now() : null,
            ])->save();

            Log::warning('Outbox message processing failed', [
                'outbox_message_id' => $outboxMessage->id,
                'aggregate_type' => $outboxMessage->aggregate_type,
                'aggregate_id' => $outboxMessage->aggregate_id,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'status' => $status,
                'message' => $message,
            ]);

            if ($status === OutboxMessage::STATUS_PENDING) {
                static::dispatch($outboxMessage->id)
                    ->onQueue((string) config('services.erp.outbox_queue', 'erp-sync'))
                    ->delay($outboxMessage->available_at);
            }
        }
    }
}
