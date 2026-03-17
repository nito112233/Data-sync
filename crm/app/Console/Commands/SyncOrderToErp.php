<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncOrderToErp extends Command
{
    protected $signature = 'crm:sync-order {orderId}';
    protected $description = 'Send one CRM order to ERP via synchronous REST call';

    public function handle(): int
    {
        $orderId = (int) $this->argument('orderId');

        $order = $this->loadOrder($orderId);

        if (!$order) {
            $this->error("order {$orderId} not found");
            return self::FAILURE;
        }

        $payload = $this->buildPayload($order);

        try {
            $res = $this->sendToErp($order, $payload);
        } catch (ConnectionException|RequestException $exception) {
            $this->reportFinalFailure($order, $exception);
            return self::FAILURE;
        }

        $salesOrderId = $res->json('sales_order_id');

        $order->synced_at = now();
        $order->erp_reference = (string) $salesOrderId;
        $order->save();

        $this->info('Synced order '.$this->displayOrderNumber($order)." -> ERP sales_order_id={$salesOrderId}");
        return self::SUCCESS;
    }

    protected function loadOrder(int $orderId): ?Order
    {
        return Order::with(['customer', 'items'])->find($orderId);
    }

    protected function buildPayload(Order $order): array
    {
        $items = [];
        $totalCents = 0;

        foreach ($order->items as $item) {
            $qty = (int) $item->qty;
            $unitPriceCents = $this->toCents($item->unit_price);
            $lineTotalCents = $qty * $unitPriceCents;

            $items[] = [
                'sku' => strtoupper(trim((string) $item->sku)),
                'name' => trim((string) $item->name),
                'qty' => $qty,
                'unit_price' => $this->formatMoney($unitPriceCents),
                'line_total' => $this->formatMoney($lineTotalCents),
            ];

            $totalCents += $lineTotalCents;
        }

        return [
            'customer' => [
                'external_id' => $order->customer->external_id,
                'name' => trim((string) $order->customer->name),
                'email' => $this->normalizeEmail($order->customer->email),
                'phone' => $this->normalizePhone($order->customer->phone),
            ],
            'order' => [
                'external_id' => $order->external_id,
                'number' => trim((string) $order->number),
                'currency' => strtoupper(trim((string) $order->currency)),
                'total' => $this->formatMoney($totalCents),
                'issued_at' => optional($order->issued_at)->toDateString(),
            ],
            'items' => $items,
        ];
    }

    protected function sendToErp(Order $order, array $payload): Response
    {
        $baseUrl = rtrim((string) config('services.erp.url'), '/');
        $integrationKey = (string) config('services.erp.integration_key');

        if ($baseUrl === '') {
            throw new ConnectionException('ERP_URL is not configured.');
        }

        if ($integrationKey === '') {
            throw new ConnectionException('INTEGRATION_KEY is not configured.');
        }

        $url = $baseUrl.'/api/crm/orders';
        $attempts = max(1, (int) config('services.erp.retry_attempts', 3));
        $backoffMs = max(0, (int) config('services.erp.retry_backoff_ms', 500));
        $currentAttempt = 0;
        $loggedAttempts = [];

        try {
            return Http::timeout(max(1, (int) config('services.erp.timeout_seconds', 10)))
                ->acceptJson()
                ->asJson()
                ->withHeaders(['X-INTEGRATION-KEY' => $integrationKey])
                ->beforeSending(function () use (&$currentAttempt): void {
                    $currentAttempt++;
                })
                ->retry(
                    $attempts,
                    fn (int $attempt) => $this->retryDelayForAttempt($attempt, $backoffMs),
                    function (Throwable $exception) use ($order, $url, $attempts, &$currentAttempt, &$loggedAttempts): bool {
                        $attemptNumber = max(1, $currentAttempt);
                        $this->logFailedAttempt($order, $url, $attemptNumber, $attempts, $exception);
                        $loggedAttempts[$attemptNumber] = true;

                        $shouldRetry = $this->shouldRetry($exception);

                        if ($shouldRetry && $attemptNumber < $attempts) {
                            $this->warn(
                                "Attempt {$attemptNumber}/{$attempts} failed for order ".$this->displayOrderNumber($order).'; retrying...'
                            );
                        }

                        return $shouldRetry;
                    }
                )
                ->post($url, $payload);
        } catch (ConnectionException|RequestException $exception) {
            $attemptNumber = max(1, $currentAttempt);

            if (!isset($loggedAttempts[$attemptNumber])) {
                $this->logFailedAttempt($order, $url, $attemptNumber, $attempts, $exception);
            }

            throw $exception;
        }
    }

    protected function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            return in_array($exception->response->status(), [502, 503, 504], true);
        }

        return false;
    }

    protected function retryDelayForAttempt(int $attempt, int $baseDelayMs): int
    {
        return $baseDelayMs * max(1, ($attempt * 2) - 1);
    }

    protected function logFailedAttempt(Order $order, string $url, int $attempt, int $attempts, Throwable $exception): void
    {
        $status = $exception instanceof RequestException
            ? $exception->response->status()
            : null;

        $message = $exception instanceof RequestException
            ? trim((string) $exception->response->body())
            : $exception->getMessage();

        Log::warning('CRM to ERP sync attempt failed', [
            'order_id' => $order->id,
            'order_number' => $this->displayOrderNumber($order),
            'url' => $url,
            'attempt' => $attempt,
            'attempts' => $attempts,
            'status' => $status,
            'exception' => $exception::class,
            'message' => $message,
        ]);
    }

    protected function reportFinalFailure(Order $order, Throwable $exception): void
    {
        if ($exception instanceof ConnectionException) {
            $message = trim($exception->getMessage());

            if (str_contains($message, 'not configured')) {
                $this->error($message);

                return;
            }

            $attempts = max(1, (int) config('services.erp.retry_attempts', 3));
            $this->error("ERP unavailable after {$attempts} attempts for order ".$this->displayOrderNumber($order).'.');
            $this->line($message);

            return;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            if ($this->shouldRetry($exception)) {
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

    protected function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $normalized = preg_replace('/[\s\-\(\)]/', '', trim($phone)) ?? '';

        if (str_starts_with($normalized, '+371')) {
            $normalized = substr($normalized, 4);
        }

        return $normalized !== '' ? $normalized : null;
    }

    protected function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = strtolower(trim($email));

        return $normalized !== '' ? $normalized : null;
    }

    protected function toCents(string|int|float|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    protected function formatMoney(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }

    protected function displayOrderNumber(Order $order): string
    {
        return trim((string) $order->number);
    }
}
