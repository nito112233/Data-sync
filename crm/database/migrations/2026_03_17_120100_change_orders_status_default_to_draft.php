<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY status VARCHAR(255) NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY status VARCHAR(255) NOT NULL DEFAULT 'new'");
        }
    }
};
