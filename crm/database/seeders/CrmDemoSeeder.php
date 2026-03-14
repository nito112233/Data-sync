<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;


class CrmDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clean (optional for repeatable demo runs)
        DB::table('order_items')->truncate();
        DB::table('orders')->truncate();
        DB::table('customers')->truncate();

        $customers = [
            ['name' => 'SIA Alpha', 'email' => 'alpha@example.com', 'phone' => '+37120000001'],
            ['name' => 'SIA Beta',  'email' => 'beta@example.com',  'phone' => '+37120000002'],
            ['name' => 'SIA Gamma', 'email' => 'gamma@example.com', 'phone' => '+37120000003'],
        ];

        foreach ($customers as $i => $c) {
            $customerId = DB::table('customers')->insertGetId([
                'external_id' => (string) Str::uuid(),
                'name' => $c['name'],
                'email' => $c['email'],
                'phone' => $c['phone'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // One order per customer (total 3)
            $orderExternal = (string) Str::uuid();
            $orderId = DB::table('orders')->insertGetId([
                'external_id' => $orderExternal,
                'customer_id' => $customerId,
                'number' => 'INV-2026-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT),
                'status' => 'new',
                'currency' => 'EUR',
                'total' => 0,
                'issued_at' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $total = 0;

            // 5 items each
            for ($k = 1; $k <= 5; $k++) {
                $qty = 1 + ($k % 3);
                $unit = 10 + ($k * 3) + ($i * 2); // deterministic-ish
                $line = $qty * $unit;
                $total += $line;

                DB::table('order_items')->insert([
                    'order_id' => $orderId,
                    'sku' => "SKU-{$i}{$k}",
                    'name' => "Item {$k}",
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $line,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('orders')->where('id', $orderId)->update([
                'total' => $total,
                'updated_at' => now(),
            ]);
        }
    }
}
