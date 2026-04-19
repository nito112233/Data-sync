<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OutboxMessage;
use App\Services\IntegrationBenchmarkService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BenchmarkSyncMethodsLatency extends Command
{
    protected $signature = 'crm:benchmark-latency {--timeout-ms=120000} {--poll-ms=200}';
    protected $description = 'Compare source-side and end-to-end latency for sync, async, and batch methods';

    public function handle(IntegrationBenchmarkService $bench): int
    {
        $timeoutMs = max(1000, (int) $this->option('timeout-ms'));
        $pollMs = max(50, (int) $this->option('poll-ms'));
        $label = 'Latency '.Str::upper(Str::random(4));

        $this->info("Running latency benchmark: {$label}");
        $this->printPreflightNotes();

        $rows = [];

        $syncStartedAt = microtime(true);
        $syncOrder = $bench->createSyncOrder($label.' Sync');
        $syncExitCode = $this->callSilent('crm:sync-order', ['orderId' => $syncOrder->id]);
        $syncDurationMs = $bench->elapsedMs($syncStartedAt);
        $syncOrder->refresh();

        $rows[] = [
            'sync',
            $syncDurationMs,
            $syncDurationMs,
            $syncOrder->synced_at ? 'yes' : 'no',
            $syncExitCode === self::SUCCESS ? 'bloķējošs process' : 'sync command failed',
        ];

        $asyncStartedAt = microtime(true);
        $asyncOrder = $bench->createAsyncOrder($label.' Async');
        $asyncSourceMs = $bench->elapsedMs($asyncStartedAt);
        $asyncWait = $bench->waitForOrdersSynced([$asyncOrder->id], $timeoutMs, $pollMs);
        $asyncEndToEndMs = $bench->elapsedMs($asyncStartedAt);

        $rows[] = [
            'async',
            $asyncSourceMs,
            $asyncEndToEndMs,
            $asyncWait['completed'] ? 'yes' : 'timeout',
            $asyncWait['completed']
                ? 'nepieciešams ieslēgst queue worker'
                : 'queue worker may not be running, or ERP is unavailable',
        ];

        $batchStartedAt = microtime(true);
        $batchOrder = $bench->createBatchOrder($label.' Batch');
        $batchSourceMs = $bench->elapsedMs($batchStartedAt);
        $batchWait = $bench->waitForOrdersSynced([$batchOrder->id], $timeoutMs, $pollMs);
        $batchEndToEndMs = $bench->elapsedMs($batchStartedAt);

        $rows[] = [
            'batch',
            $batchSourceMs,
            $batchEndToEndMs,
            $batchWait['completed'] ? 'yes' : 'timeout',
            $batchWait['completed']
                ? 'iekļauj gaidīšanu līdz nākamajam intervālam'
                : 'scheduler may not be running, or ERP is unavailable',
        ];

        $this->newLine();
        $this->table(
            ['method', 'source_side_ms', 'end_to_end_ms', 'synced', 'notes'],
            $rows
        );

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
