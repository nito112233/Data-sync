<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmOrderImportController extends Controller
{
    public function store(Request $request)
    {
        $key = $request->header('X-INTEGRATION-KEY');
        abort_unless($key && $key === env('INTEGRATION_KEY'), 401, 'Unauthorized');

        $data = $request->validate([
            'customer.external_id' => ['required', 'uuid'],
            'customer.name'        => ['required', 'string', 'max:255'],
            'customer.email'       => ['nullable', 'email', 'max:255'],
            'customer.phone'       => ['nullable', 'string', 'max:50'],

            'order.external_id' => ['required', 'uuid'],
            'order.number'      => ['required', 'string', 'max:100'],
            'order.currency'    => ['required', 'string', 'size:3'],
            'order.total'       => ['required', 'numeric', 'min:0'],
            'order.issued_at'   => ['nullable', 'date'],

            'items'              => ['required', 'array', 'min:1'],
            'items.*.sku'        => ['required', 'string', 'max:100'],
            'items.*.name'       => ['required', 'string', 'max:255'],
            'items.*.qty'        => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.line_total' => ['required', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($data) {
            $customer = Customer::updateOrCreate(
                ['external_id' => $data['customer']['external_id']],
                [
                    'name'  => $data['customer']['name'],
                    'email' => $data['customer']['email'] ?? null,
                    'phone' => $data['customer']['phone'] ?? null,
                ]
            );

            $salesOrder = SalesOrder::updateOrCreate(
                ['external_id' => $data['order']['external_id']],
                [
                    'customer_id'  => $customer->id,
                    'order_number' => $data['order']['number'],
                    'status'       => 'received',
                    'currency'     => $data['order']['currency'],
                    'total'        => $data['order']['total'],
                    'issued_at'    => $data['order']['issued_at'] ?? null,
                    'received_at'  => now(),
                ]
            );

            // Replace items for simplicity (idempotent-ish)
            $salesOrder->items()->delete();

            $rows = array_map(fn($it) => [
                'sales_order_id' => $salesOrder->id,
                'sku'            => $it['sku'],
                'name'           => $it['name'],
                'qty'            => $it['qty'],
                'unit_price'     => $it['unit_price'],
                'line_total'     => $it['line_total'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ], $data['items']);

            SalesOrderItem::insert($rows);

            return response()->json([
                'ok' => true,
                'sales_order_id' => $salesOrder->id,
            ]);
        });
    }
}