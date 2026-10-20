<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Username & password API MikroTik untuk NAS ini — di-generate
     * OTOMATIS oleh aplikasi (lihat Nas::boot() creating()), supaya
     * user awam tidak perlu mikirin sendiri. Dipakai di script setup
     * MikroTik yang bisa di-copy-paste (tombol "Panduan Setup MikroTik"
     * di NasResource).
     */
    public function up(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->string('api_username')->nullable()->after('connection_type');
            $table->text('api_password')->nullable()->after('api_username');
        });
    }

    public function down(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->dropColumn(['api_username', 'api_password']);
        });
    }
};
