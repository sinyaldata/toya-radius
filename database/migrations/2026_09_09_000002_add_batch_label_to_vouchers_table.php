<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom "keterangan" batch voucher (mis. "Voucher 2000") —
 * TERPISAH dari batch_code (kode acak internal, mis.
 * "BATCH-20260909123456-AB12") yang memang tidak enak dibaca manusia.
 *
 * batch_label diisi OTOMATIS saat generate (lihat
 * VoucherGeneratorService::generate()) dengan format "Voucher {jumlah}"
 * -- supaya kalau nanti di-scroll di riwayat (perintah /batch di bot
 * Telegram), langsung kelihatan itu batch yang mana tanpa perlu buka
 * satu-satu (permintaan user).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('batch_label')->nullable()->after('batch_code');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('batch_label');
        });
    }
};
