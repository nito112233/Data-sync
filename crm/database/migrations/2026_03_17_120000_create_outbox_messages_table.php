<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
