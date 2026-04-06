<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOutboxMessage;
use App\Models\OutboxMessage;
use App\Models\SyncPipelineState;
use App\Services\BatchOrderCdcService;
use App\Services\IntegrationBenchmarkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

class BenchmarkErpUnavailable extends Command
{
    protected $signature = 'crm:benchmark-erp-unavailable {--pipeline=}';
    protected $description = 'Show how sync, async, and batch methods behave when ERP is unavailable';

    public function handle(
        IntegrationBenchmarkService $bench,
        BatchOrderCdcService $batchSync
    ): int {
        $originalConfig = [
            'url' => config('services.erp.url'),
            'retry_attempts' => config('services.erp.retry_attempts'),
            'retry_backoff_ms' => config('services.erp.retry_backoff_ms'),
            'timeout_seconds' => config('services.erp.timeout_seconds'),
            'outbox_retry_delay_seconds' => config('services.erp.outbox_retry_delay_seconds'),
        ];

        config([
            'services.erp.url' => 'http://127.0.0.1:65535',
            'services.erp.retry_attempts' => 3,
            'services.erp.retry_backoff_ms' => 200,
            'services.erp.timeout_seconds' => 1,
            'services.erp.outbox_retry_delay_seconds' => 1,
        ]);

        Queue::fake();

        try {
            $label = 'Unavailable '.Str::upper(Str::random(4));
            $pipeline = (string) ($this->option('pipeline') ?: 'erp-unavailable-'.Str::lower(Str::random(8)));

            $this->info("Running ERP-unavailable diagnostic: {$label}");
            $this->line('ERP URL is temporarily pointed to an unavailable local port for this command only.');
            $this->newLine();

            $this->renderSyncSection($bench, $label);
            $this->renderAsyncSection($bench, $label);
            $this->renderBatchSection($bench, $batchSync, $label, $pipeline);

            return self::SUCCESS;
        } finally {
            config([
                'services.erp.url' => $originalConfig['url'],
                'services.erp.retry_attempts' => $originalConfig['retry_attempts'],
                'services.erp.retry_backoff_ms' => $originalConfig['retry_backoff_ms'],
                'services.erp.timeout_seconds' => $originalConfig['timeout_seconds'],
                'services.erp.outbox_retry_delay_seconds' => $originalConfig['outbox_retry_delay_seconds'],
            ]);
        }
    }

    protected function renderSyncSection(IntegrationBenchmarkService $bench, string $label): void
    {
        $this->info('Sync method');

        $order = $bench->createSyncOrder($label.' Sync');
        $startedAt = microtime(true);
        $exitCode = Artisan::call('crm:sync-order', ['orderId' => $order->id]);
        $durationMs = $bench->elapsedMs($startedAt);
        $output = trim(Artisan::output());

        $this->table(
            ['exit_code', 'duration_ms', 'order_synced', 'notes'],
            [[
                $exitCode,
                $durationMs,
                $order->fresh()->synced_at ? 'yes' : 'no',
                'Retries happen only inside the HTTP request loop; no persisted retry state is stored for sync.',
            ]]
        );

        if ($output !== '') {
            $this->line('Captured command output:');
            $this->line($output);
        }

        $this->newLine();
    }

    protected function renderAsyncSection(IntegrationBenchmarkService $bench, string $label): void
    {
        $this->info('Async method');

        $order = $bench->createAsyncOrder($label.' Async');
        $outboxMessage = $order->outboxMessages()->latest('id')->first();

        if (! $outboxMessage) {
            $this->error('No outbox message was created for async benchmark order.');
            $this->newLine();

            return;
        }

        $rows = [];
        $maxAttempts = max(1, (int) config('services.erp.outbox_max_attempts', 3));

        for ($run = 1; $run <= $maxAttempts; $run++) {
            $jobStartedAt = microtime(true);
            (new ProcessOutboxMessage($outboxMessage->id))->handle(app(\App\Services\ErpOrderSyncService::class));
            $jobElapsedMs = $bench->elapsedMs($jobStartedAt);
            $outboxMessage->refresh();

            $rows[] = [
                $outboxMessage->aggregate_id,
                $run,
                $jobElapsedMs,
                $outboxMessage->status,
                $outboxMessage->attempts,
                $this->truncate((string) $outboxMessage->last_error, 90),
                optional($outboxMessage->available_at)?->toDateTimeString(),
                optional($outboxMessage->processed_at)?->toDateTimeString(),
            ];

            if ($outboxMessage->status === OutboxMessage::STATUS_FAILED) {
                break;
            }

            if ($outboxMessage->available_at !== null && $outboxMessage->available_at->isFuture()) {
                $sleepMs = now()->diffInMilliseconds($outboxMessage->available_at, false);

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        $this->table(
            ['order_id', 'job_run', 'duration_ms', 'outbox_status', 'attempts', 'last_error', 'available_at', 'processed_at'],
            $rows
        );
        $this->line('HTTP retries happen inside each job execution. Queue-level retries are persisted in `outbox_messages` via status, attempts, last_error, and available_at.');
        $this->newLine();
    }

    protected function renderBatchSection(
        IntegrationBenchmarkService $bench,
        BatchOrderCdcService $batchSync,
        string $label,
        string $pipeline
    ): void {
        $this->info('Batch method');

        $bench->createBatchOrder($label.' Batch');
        $rows = [];

        for ($run = 1; $run <= 2; $run++) {
            $startedAt = microtime(true);

            try {
                $batchSync->run($pipeline, 100);
                $exceptionMessage = '';
            } catch (Throwable $exception) {
                $exceptionMessage = trim($exception->getMessage());
            }

            $elapsedMs = $bench->elapsedMs($startedAt);
            $state = SyncPipelineState::query()->where('pipeline', $pipeline)->first();

            $rows[] = [
                $run,
                $elapsedMs,
                $state?->last_status ?? 'missing',
                $state?->last_cursor_updated_at?->toDateTimeString() ?? '-',
                $state?->last_cursor_id ?? '-',
                $this->truncate((string) ($state?->last_error ?? $exceptionMessage), 90),
            ];
        }

        $this->table(
            ['batch_run', 'duration_ms', 'pipeline_status', 'cursor_updated_at', 'cursor_id', 'last_error'],
            $rows
        );
        $this->line('HTTP retries happen inside the batch request call. There is no outbox row here; retry across runs happens because the pipeline cursor is not advanced on failure.');
        $this->newLine();
    }

    protected function truncate(string $value, int $limit): string
    {
        $value = trim($value);

        if ($value === '') {
            return '-';
        }

        return Str::limit($value, $limit);
    }
}
