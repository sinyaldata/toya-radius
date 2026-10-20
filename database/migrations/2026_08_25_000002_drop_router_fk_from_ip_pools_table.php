<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sama seperti perbaikan di api_logs — Router sekarang hidup di
     * database PUSAT, jadi kunci asing `router_id` di `ip_pools`
     * (tabel yang TETAP di database tenant) ke tabel `routers` LOKAL
     * (sekarang usang/kosong) harus dilepas.
     */
    public function up(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            $table->dropForeign(['router_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            $table->foreign('router_id')->references('id')->on('routers')->nullOnDelete();
        });
    }
};
