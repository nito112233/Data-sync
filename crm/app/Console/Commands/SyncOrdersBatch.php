<?php

namespace App\Console\Commands;

use App\Services\BatchOrderCdcService;
use Illuminate\Console\Command;
use Throwable;

class SyncOrdersBatch extends Command
{
    protected $signature = 'crm:sync-orders-batch {--limit=} {--pipeline=}';
    protected $description = 'Run the batch CDC sync for batch-demo CRM orders';

    public function handle(BatchOrderCdcService $batchSync): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $pipeline = $this->option('pipeline') !== null ? (string) $this->option('pipeline') : null;

        try {
            $result = $batchSync->run($pipeline, $limit);
        } catch (Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->error($message !== '' ? $message : 'Batch CDC sync failed.');

            return self::FAILURE;
        }

        if ($result['selected_count'] === 0) {
            $this->info("No eligible batch-demo orders found for pipeline {$result['pipeline']}.");

            return self::SUCCESS;
        }

        $this->info("Synced {$result['synced_count']} batch-demo order(s) for pipeline {$result['pipeline']}.");
        $this->table(
            ['selected_count', 'synced_count', 'cursor_updated_at', 'cursor_id'],
            [[
                $result['selected_count'],
                $result['synced_count'],
                $result['cursor_updated_at'],
                $result['cursor_id'],
            ]]
        );

        return self::SUCCESS;
    }
}
