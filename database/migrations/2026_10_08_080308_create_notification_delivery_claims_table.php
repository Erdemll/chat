<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_delivery_claims', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('channel', 32);
            $table->uuid('token');
            $table->dateTime('created_at');
            $table->primary(['user_id', 'type', 'channel'], 'notification_delivery_claims_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_claims');
    }
};
