<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BARU (permintaan user: komisi Outlet/Reseller tanpa persentase).
     * Kolom commission_flat_amount SUDAH ADA dari sebelumnya (tidak
     * dibuat lagi di sini) -- yang BARU cuma kolom penanda "yang
     * dipakai untuk outlet ini yang mana" (percent atau flat), supaya
     * tidak ambigu. Default 'percent' -- SEMUA outlet yang sudah ada
     * sekarang TIDAK BERUBAH perilakunya sama sekali (tetap pakai
     * commission_percent seperti biasa) sampai admin SENGAJA
     * menggantinya ke 'flat' lewat form Outlet/Reseller.
     */
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('commission_type', 20)->default('percent')->after('commission_percent');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn('commission_type');
        });
    }
};
