<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ErpOrderSyncService
{
    public function buildPayload(Order $order): array
    {
        $order->loadMissing(['customer', 'items']);

        $items = [];
        $totalCents = 0;

        foreach ($order->items as $item) {
            $qty = (int) $item->qty;
            $unitPriceCents = (int) round(((float) $item->unit_price) * 100);
            $lineTotalCents = $qty * $unitPriceCents;

            $items[] = [
                'sku' => strtoupper(trim((string) $item->sku)),
                'name' => trim((string) $item->name),
                'qty' => $qty,
                'unit_price' => number_format($unitPriceCents / 100, 2, '.', ''),
                'line_total' => number_format($lineTotalCents / 100, 2, '.', ''),
            ];

            $totalCents += $lineTotalCents;
        }

        $email = $order->customer->email;

        if ($email !== null) {
            $email = strtolower(trim($email));
            $email = $email !== '' ? $email : null;
        }

        $phone = $order->customer->phone;

        if ($phone !== null) {
            $phone = preg_replace('/[\s\-\(\)]/', '', trim($phone)) ?? '';

            if (str_starts_with($phone, '+371')) {
                $phone = substr($phone, 4);
            }

            $phone = $phone !== '' ? $phone : null;
        }

        return [
            'customer' => [
                'external_id' => $order->customer->external_id,
                'name' => trim((string) $order->customer->name),
                'email' => $email,
                'phone' => $phone,
            ],
            'order' => [
                'external_id' => $order->external_id,
                'number' => trim((string) $order->number),
                'currency' => strtoupper(trim((string) $order->currency)),
                'total' => number_format($totalCents / 100, 2, '.', ''),
                'issued_at' => optional($order->issued_at)->toDateString(),
            ],
            'items' => $items,
        ];
    }

    public function sendPayload(array $payload, array $context = [], ?callable $onRetry = null): Response
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
        $timeoutSeconds = max(1, (int) config('services.erp.timeout_seconds', 10));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout($timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->withHeaders(['X-INTEGRATION-KEY' => $integrationKey])
                ->post($url, $payload);

                if ($response->successful()) {
                    return $response;
                }

                $exception = $response->toException();
                $status = $response->status();
                $body = trim((string) $response->body());
                $message = $body !== '' ? "HTTP {$status}: {$body}" : "HTTP {$status}";

                Log::warning('CRM to ERP sync attempt failed', array_merge($context, [
                    'url' => $url,
                    'attempt' => $attempt,
                    'attempts' => $attempts,
                    'status' => $status,
                    'exception' => $exception::class,
                    'message' => $message,
                ]));

                $shouldRetry = in_array($status, [502, 503, 504], true);

                if (! $shouldRetry || $attempt === $attempts) {
                    throw $exception;
                }

                if ($onRetry !== null) {
                    $onRetry($attempt, $attempts, $exception);
                }
            } catch (ConnectionException $exception) {
                $message = trim($exception->getMessage());

                Log::warning('CRM to ERP sync attempt failed', array_merge($context, [
                    'url' => $url,
                    'attempt' => $attempt,
                    'attempts' => $attempts,
                    'status' => null,
                    'exception' => $exception::class,
                    'message' => $message,
                ]));

                if ($attempt === $attempts) {
                    throw $exception;
                }

                if ($onRetry !== null) {
                    $onRetry($attempt, $attempts, $exception);
                }
            }

            $delayMs = $backoffMs * max(1, ($attempt * 2) - 1);

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        throw new ConnectionException('ERP sync failed without a final response.');
    }
}
