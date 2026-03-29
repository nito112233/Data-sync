<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('sync_mode')->default('async')->after('status');
            $table->index(['sync_mode', 'status', 'updated_at', 'id'], 'orders_sync_mode_status_updated_idx');
        });

        DB::table('orders')->update(['sync_mode' => 'async']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_sync_mode_status_updated_idx');
            $table->dropColumn('sync_mode');
        });
    }
};
