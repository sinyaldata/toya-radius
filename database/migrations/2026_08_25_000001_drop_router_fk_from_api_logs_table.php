<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Router SEKARANG hidup di database PUSAT (lihat migration di
     * database/migrations/central/), bukan lagi di database masing-
     * masing tenant. Kolom `router_id` di `api_logs` (tabel LOG, tetap
     * di database tenant) sebelumnya terikat aturan kunci asing ke
     * tabel `routers` LOKAL tenant — sekarang jadi tidak masuk akal
     * (Router yang beneran dipakai ID-nya dari pusat, bukan lokal),
     * dan bikin INSERT log gagal total (error 1452) begitu ID dari
     * pusat itu tidak ketemu di tabel lokal yang sekarang cuma sisa
     * lama/kosong. Lepas aturan kunci asingnya — kolom `router_id`
     * TETAP ada (cuma jadi angka biasa, dicocokkan manual di kode
     * kalau perlu), cukup buat catatan log, tidak perlu integritas
     * ketat di sini.
     */
    public function up(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->dropForeign(['router_id']);
        });
    }

    public function down(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->foreign('router_id')->references('id')->on('routers')->nullOnDelete();
        });
    }
};
