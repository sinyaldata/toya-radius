<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sebelumnya nama Parent Queue di MikroTik SELALU dibuat otomatis
     * ("toya-parent-{slug}") oleh aplikasi. Kolom ini membuka opsi
     * memakai NAMA QUEUE YANG SUDAH ADA DI MIKROTIK (dibuat manual oleh
     * admin di router) — kalau diisi, aplikasi akan mengarah ke queue
     * itu (bukan bikin baru), supaya user PPPoE/Hotspot yang konek bisa
     * "bernaung" di bawah parent queue yang sudah disiapkan di router.
     */
    public function up(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            $table->string('parent_queue_name')->nullable()->after('max_upload');
        });

        Schema::table('bandwidths', function (Blueprint $table) {
            $table->string('parent_queue_name')->nullable()->after('max_download');
        });
    }

    public function down(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            $table->dropColumn('parent_queue_name');
        });

        Schema::table('bandwidths', function (Blueprint $table) {
            $table->dropColumn('parent_queue_name');
        });
    }
};
