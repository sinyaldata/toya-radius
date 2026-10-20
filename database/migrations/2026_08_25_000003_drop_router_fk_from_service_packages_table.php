<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sama seperti perbaikan api_logs & ip_pools — Router sekarang
     * hidup di database PUSAT, jadi kunci asing `router_id` di
     * `service_packages` (tabel yang TETAP di database tenant) ke
     * tabel `routers` LOKAL (sekarang usang) harus dilepas.
     */
    public function up(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropForeign(['router_id']);
        });
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->foreign('router_id')->references('id')->on('routers')->nullOnDelete();
        });
    }
};
