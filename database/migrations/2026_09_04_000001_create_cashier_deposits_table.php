<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * KASIR / SETORAN MITRA.
     *
     * Mencatat uang yang DISETORKAN outlet/biller ke kantor, supaya
     * bisa diketahui siapa saja yang sudah menyetor dan siapa yang
     * belum.
     *
     * Kenapa tabel sendiri (bukan menumpang tabel yang sudah ada):
     * - `payment_logs` mencatat pembayaran PELANGGAN atas tagihannya
     *   (arah uang: pelanggan -> outlet/biller). Itu peristiwa yang
     *   BERBEDA dengan setoran (arah uang: outlet/biller -> kantor).
     *   Satu setoran biasanya merangkum banyak pembayaran pelanggan,
     *   jadi tidak bisa disamakan satu-lawan-satu.
     * - `topup_requests`/saldo outlet dipakai untuk MEMBELI stok
     *   voucher (uang masuk lebih dulu, baru dipakai generate) —
     *   juga peristiwa berbeda.
     * Dipisah supaya laporan keuangan tidak dobel hitung.
     *
     * outlet_id menunjuk ke tabel `outlets` yang sama untuk Outlet,
     * Reseller, maupun Biller — ketiganya dibedakan lewat kolom
     * partner_type di tabel itu, jadi tidak perlu kolom tambahan.
     */
    public function up(): void
    {
        Schema::create('cashier_deposits', function (Blueprint $table) {
            $table->id();

            // Yang MENYETOR (outlet / reseller / biller).
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();

            $table->decimal('amount', 14, 2);
            $table->date('deposit_date');

            // cash / transfer / lainnya — string biasa (bukan enum DB)
            // supaya metode baru bisa ditambah tanpa migration lagi.
            $table->string('payment_method', 30)->default('cash');

            // No. bukti transfer / no. kuitansi, opsional.
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();

            // KASIR yang menerima. nullOnDelete supaya riwayat setoran
            // TIDAK ikut terhapus kalau akun kasirnya dihapus.
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Laporan paling sering disaring per tanggal & per penyetor.
            $table->index(['deposit_date', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_deposits');
    }
};
