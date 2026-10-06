<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->whereNotIn('role', ['admin', 'employee'])->exists()) {
            throw new RuntimeException('Tanımsız kullanıcı rolleri var. Mevcut rolleri eşleştirmeden foreign key eklenemez.');
        }

        /** Existing user role values must have parent records before adding the constraint. */
        DB::table('roles')->insertOrIgnore([
            ['name' => 'Yönetici', 'slug' => 'admin', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Çalışan', 'slug' => 'employee', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('role')->references('slug')->on('roles')->restrictOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['role']);
        });
    }
};
