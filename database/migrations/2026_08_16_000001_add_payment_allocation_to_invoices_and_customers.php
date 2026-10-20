<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Berapa yang SUDAH dibayar untuk invoice ini (bisa sebagian,
            // tidak harus lunas sekaligus). 'amount' tetap total tagihan
            // bulan itu; 'amount_paid' yang menentukan status Paid/Unpaid.
            $table->decimal('amount_paid', 15, 2)->default(0)->after('amount');
        });

        Schema::table('customers', function (Blueprint $table) {
            // Saldo lebih bayar (kalau pelanggan bayar melebihi total
            // tagihan yang ada saat itu — dipakai OTOMATIS untuk melunasi
            // invoice bulan berikutnya begitu invoice itu dibuat).
            $table->decimal('credit_balance', 15, 2)->default(0)->after('billing_day');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('credit_balance');
        });
    }
};
