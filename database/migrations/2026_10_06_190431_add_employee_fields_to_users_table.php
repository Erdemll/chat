<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 30)->default('employee');
                $table->boolean('is_active')->default(true);
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('invitation_failed_at')->nullable();
                $table->timestamp('password_set_at')->nullable();
                $table->timestamp('last_login_at')->nullable();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['role', 'is_active', 'invited_at', 'invitation_failed_at', 'password_set_at', 'last_login_at']);
            });
        });
    }
};
