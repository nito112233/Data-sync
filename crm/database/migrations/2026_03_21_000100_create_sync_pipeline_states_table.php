<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_pipeline_states', function (Blueprint $table) {
            $table->id();
            $table->string('pipeline')->unique();
            $table->timestamp('last_cursor_updated_at')->nullable();
            $table->unsignedBigInteger('last_cursor_id')->nullable();
            $table->timestamp('last_run_started_at')->nullable();
            $table->timestamp('last_run_finished_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_pipeline_states');
    }
};
