<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sama seperti biller_id di payment_logs/cashier_deposits (lihat
     * migration add_biller_id_to_payment_logs_and_cashier_deposits),
     * tapi ditaruh juga LANGSUNG di invoices supaya kolom "Biller" bisa
     * ditampilkan di tabel Tagihan tanpa perlu menelusuri PaymentLog
     * (yang menyimpan cakupan invoice-nya lewat kolom `allocations`
     * berbentuk JSON — merepotkan untuk ditampilkan per-baris invoice).
     *
     * Diisi cuma saat invoice LUNAS PENUH lewat bot Telegram oleh
     * Biller (lihat InvoiceService::recordPayment()) -- null untuk
     * semua cara pembayaran lain (dicatat admin dari aplikasi, dsb).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('biller_id')->nullable()->after('notes')
                ->constrained('outlets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('biller_id');
        });
    }
};
