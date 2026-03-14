<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncOrderToErp extends Command
{
    protected $signature = 'crm:sync-order {orderId}';
    protected $description = 'Send one CRM order to ERP via synchronous REST call';

    public function handle(): int
    {
        $orderId = (int) $this->argument('orderId');

        $order = Order::with(['customer', 'items'])->find($orderId);

        if (!$order) {
            $this->error("order {$orderId} not found");
            return self::FAILURE;
        }

        $payload = [
            'customer' => [
                'external_id' => $order->customer->external_id,
                'name' => $order->customer->name,
                'email' => $order->customer->email,
                'phone' => $order->customer->phone,
            ],
            'order' => [
                'external_id' => $order->external_id,
                'number' => $order->number,
                'currency' => $order->currency,
                'total' => $order->total,
                'issued_at' => optional($order->issued_at)->toDateString(),
            ],
            'items' => $order->items->map(fn($it) => [
                'sku' => $it->sku,
                'name' => $it->name,
                'qty' => $it->qty,
                'unit_price' => $it->unit_price,
                'line_total' => $it->line_total,
            ])->values()->all(),
        ];

        $url = rtrim(env('ERP_URL'), '/') . '/api/crm/orders';

        $res = Http::timeout(10)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-INTEGRATION-KEY' => env('INTEGRATION_KEY')])
            ->post($url, $payload);

        if (!$res->successful()) {
            $this->error("ERP error: HTTP {$res->status()}");
            $this->line($res->body());
            return self::FAILURE;
        }

        $salesOrderId = $res->json('sales_order_id');

        $order->synced_at = now();
        $order->erp_reference = (string) $salesOrderId;
        $order->save();

        $this->info("Synced order {$order->number} -> ERP sales_order_id={$salesOrderId}");
        return self::SUCCESS;
    }
}