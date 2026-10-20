<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengeluaran SEBELUMNYA cuma dicatat lewat aplikasi (Owner/Admin),
 * jadi tidak pernah butuh tahu itu pengeluaran OUTLET mana. Sekarang
 * outlet bisa lapor pengeluaran mereka sendiri lewat bot Telegram
 * (perintah /pengeluaran) -- perlu kolom `outlet_id` supaya laporan
 * pengeluaran per outlet bisa dipisahkan dari pengeluaran pusat.
 *
 * Nullable: pengeluaran yang dicatat lewat APLIKASI (pusat/Owner)
 * tetap boleh tanpa outlet, seperti sebelumnya -- kolom ini hanya
 * DIISI kalau pengeluaran datang dari outlet tertentu.
 *
 * `source` menandai ASAL pencatatan (app|telegram), pola yang sama
 * dengan kolom `source` di tabel vouchers (lihat VoucherSource) --
 * cuma buat keterangan kecil di tabel Pengeluaran, tidak dipakai
 * logika apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('source', 20)->default('app')->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outlet_id');
            $table->dropColumn('source');
        });
    }
};
