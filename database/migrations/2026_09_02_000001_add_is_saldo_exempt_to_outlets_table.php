<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            // Outlet/reseller dengan flag ini AKTIF boleh generate voucher
            // tanpa perlu saldo cukup (saldo TIDAK dipotong sama sekali).
            // Outlet lain (flag OFF, default) tetap wajib top up saldo
            // dulu sebelum bisa generate -- lihat GenerateVoucherBatchAction.
            $table->boolean('is_saldo_exempt')->default(false)->after('saldo');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn('is_saldo_exempt');
        });
    }
};
