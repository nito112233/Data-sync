<?php

namespace App\Http\Controllers;

use App\Services\CrmOrderImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmOrderImportController extends Controller
{
    public function __construct(
        protected CrmOrderImportService $crmOrderImport
    ) {
    }

    public function store(Request $request)
    {
        $this->authorizeRequest($request);

        $data = $request->validate($this->orderRules());

        return DB::transaction(function () use ($data) {
            $result = $this->crmOrderImport->importOrder($data);

            return response()->json([
                'ok' => true,
                'sales_order_id' => $result['sales_order_id'],
            ]);
        });
    }

    public function storeBatch(Request $request)
    {
        $this->authorizeRequest($request);

        $data = $request->validate(
            ['orders' => ['required', 'array', 'min:1']] + $this->orderRules('orders.*.')
        );

        return DB::transaction(function () use ($data) {
            $results = $this->crmOrderImport->importOrders($data['orders']);

            return response()->json([
                'ok' => true,
                'processed' => count($results),
                'results' => $results,
            ]);
        });
    }

    protected function authorizeRequest(Request $request): void
    {
        $key = $request->header('X-INTEGRATION-KEY');

        abort_unless($key && $key === env('INTEGRATION_KEY'), 401, 'Unauthorized');
    }

    protected function orderRules(string $prefix = ''): array
    {
        return [
            $prefix.'customer.external_id' => ['required', 'uuid'],
            $prefix.'customer.name' => ['required', 'string', 'max:255'],
            $prefix.'customer.email' => ['nullable', 'email', 'max:255'],
            $prefix.'customer.phone' => ['nullable', 'string', 'max:50'],

            $prefix.'order.external_id' => ['required', 'uuid'],
            $prefix.'order.number' => ['required', 'string', 'max:100'],
            $prefix.'order.currency' => ['required', 'string', 'size:3'],
            $prefix.'order.total' => ['required', 'numeric', 'min:0'],
            $prefix.'order.issued_at' => ['nullable', 'date'],

            $prefix.'items' => ['required', 'array', 'min:1'],
            $prefix.'items.*.sku' => ['required', 'string', 'max:100'],
            $prefix.'items.*.name' => ['required', 'string', 'max:255'],
            $prefix.'items.*.qty' => ['required', 'integer', 'min:1'],
            $prefix.'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            $prefix.'items.*.line_total' => ['required', 'numeric', 'min:0'],
        ];
    }
}
