<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OutboxMessage;
use App\Services\IntegrationBenchmarkService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BenchmarkSyncMethodsThroughput extends Command
{
    protected $signature = 'crm:benchmark-throughput {count=10} {--timeout-ms=240000} {--poll-ms=200}';
    protected $description = 'Compare total completion time for syncing multiple orders with sync, async, and batch methods';

    public function handle(IntegrationBenchmarkService $bench): int
    {
        $count = max(1, (int) $this->argument('count'));
        $timeoutMs = max(1000, (int) $this->option('timeout-ms'));
        $pollMs = max(50, (int) $this->option('poll-ms'));
        $label = 'Throughput '.Str::upper(Str::random(4));

        $this->info("Running throughput benchmark: {$label} ({$count} orders per method)");
        $this->printPreflightNotes();

        $rows = [];

        $syncStartedAt = microtime(true);
        $syncOrderIds = [];

        for ($index = 1; $index <= $count; $index++) {
            $order = $bench->createSyncOrder($label.' Sync', $index);
            $syncOrderIds[] = $order->id;
        }

        foreach ($syncOrderIds as $orderId) {
            $exitCode = $this->callSilent('crm:sync-order', ['orderId' => $orderId]);

            if ($exitCode !== self::SUCCESS) {
                $rows[] = [
                    'sync',
                    $count,
                    $bench->elapsedMs($syncStartedAt),
                    '-',
                    'failed',
                    "sync command failed on order {$orderId}",
                ];

                $this->newLine();
                $this->table(['method', 'orders', 'total_ms', 'avg_ms_per_order', 'completed', 'notes'], $rows);

                return self::FAILURE;
            }
        }

        $syncTotalMs = $bench->elapsedMs($syncStartedAt);
        $rows[] = [
            'sync',
            $count,
            $syncTotalMs,
            (int) round($syncTotalMs / $count),
            'yes',
            'pasūtījumi izveidoti, tad secīgi nosūtīti, izmantojot sync komandu',
        ];

        $asyncStartedAt = microtime(true);
        $asyncOrderIds = [];

        for ($index = 1; $index <= $count; $index++) {
            $asyncOrderIds[] = $bench->createAsyncOrder($label.' Async', $index)->id;
        }

        $asyncWait = $bench->waitForOrdersSynced($asyncOrderIds, $timeoutMs, $pollMs);
        $asyncTotalMs = $bench->elapsedMs($asyncStartedAt);
        $rows[] = [
            'async',
            $count,
            $asyncTotalMs,
            (int) round($asyncTotalMs / $count),
            $asyncWait['completed'] ? 'yes' : 'timeout',
            $asyncWait['completed']
                ? 'niepieciešams ieslēgt queue worker'
                : "synced {$asyncWait['synced_count']}/{$count}; queue worker may not be running",
        ];

        $batchStartedAt = microtime(true);
        $batchOrderIds = [];

        for ($index = 1; $index <= $count; $index++) {
            $batchOrderIds[] = $bench->createBatchOrder($label.' Batch', $index)->id;
        }

        $batchWait = $bench->waitForOrdersSynced($batchOrderIds, $timeoutMs, $pollMs);
        $batchTotalMs = $bench->elapsedMs($batchStartedAt);
        $rows[] = [
            'batch',
            $count,
            $batchTotalMs,
            (int) round($batchTotalMs / $count),
            $batchWait['completed'] ? 'yes' : 'timeout',
            $batchWait['completed']
                ? 'apstrādāts paketes pieprasījumā'
                : "synced {$batchWait['synced_count']}/{$count}; scheduler may not be running",
        ];

        $this->newLine();
        $this->table(['method', 'orders', 'total_ms', 'avg_ms_per_order', 'completed', 'notes'], $rows);

        return self::SUCCESS;
    }

    protected function printPreflightNotes(): void
    {
        $pendingOutbox = OutboxMessage::query()
            ->whereIn('status', [OutboxMessage::STATUS_PENDING, OutboxMessage::STATUS_PROCESSING])
            ->count();

        $eligibleBatchOrders = Order::query()
            ->where('sync_mode', Order::SYNC_MODE_BATCH_DEMO)
            ->where('status', 'new')
            ->whereNull('synced_at')
            ->count();

        if ($pendingOutbox > 0) {
            $this->warn("Preflight note: {$pendingOutbox} async outbox message(s) are already pending/processing.");
        }

        if ($eligibleBatchOrders > 0) {
            $this->warn("Preflight note: {$eligibleBatchOrders} batch-demo order(s) are already eligible for batch sync.");
        }

    }
}
