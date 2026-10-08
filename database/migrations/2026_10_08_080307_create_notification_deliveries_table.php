<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('channel', 32);
            $table->string('reference_type', 32);
            $table->unsignedBigInteger('reference_id');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'type', 'channel', 'reference_type', 'reference_id'], 'notification_deliveries_reference_unique');
            $table->index(['status', 'attempt_count'], 'notification_deliveries_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
