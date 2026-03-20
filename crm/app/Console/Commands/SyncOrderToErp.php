<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\ErpOrderSyncService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class SyncOrderToErp extends Command
{
    protected $signature = 'crm:sync-order {orderId}';
    protected $description = 'Send one CRM order to ERP via synchronous REST call';

    public function handle(ErpOrderSyncService $erpSync): int
    {
        $orderId = (int) $this->argument('orderId');
        $order = Order::with(['customer', 'items'])->find($orderId);

        if (!$order) {
            $this->error("order {$orderId} not found");
            return self::FAILURE;
        }

        $orderNumber = trim((string) $order->number);
        $payload = $erpSync->buildPayload($order);

        try {
            $res = $erpSync->sendPayload(
                $payload,
                [
                    'source' => 'p2p',
                    'order_id' => $order->id,
                    'order_number' => $orderNumber,
                ],
                fn (int $attempt, int $attempts) => $this->warn(
                    "Attempt {$attempt}/{$attempts} failed for order {$orderNumber}; retrying..."
                )
            );
        } catch (ConnectionException|RequestException $exception) {
            $this->reportFinalFailure($orderNumber, $exception);
            return self::FAILURE;
        }

        $salesOrderId = $res->json('sales_order_id');

        $order->synced_at = now();
        $order->erp_reference = (string) $salesOrderId;
        $order->save();

        $this->info("Synced order {$orderNumber} -> ERP sales_order_id={$salesOrderId}");
        return self::SUCCESS;
    }

    protected function reportFinalFailure(string $orderNumber, Throwable $exception): void
    {
        if ($exception instanceof ConnectionException) {
            $message = trim($exception->getMessage());

            if (str_contains($message, 'not configured')) {
                $this->error($message);

                return;
            }

            $attempts = max(1, (int) config('services.erp.retry_attempts', 3));
            $this->error("ERP unavailable after {$attempts} attempts for order {$orderNumber}.");
            $this->line($message);

            return;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            if (in_array($status, [502, 503, 504], true)) {
                $attempts = max(1, (int) config('services.erp.retry_attempts', 3));
                $this->error("ERP transient error after {$attempts} attempts: HTTP {$status}");
            } else {
                $this->error("ERP rejected request: HTTP {$status}");
            }

            $body = trim((string) $exception->response->body());

            if ($body !== '') {
                $this->line($body);
            }
        }
    }
}
