<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->decimal('amount', 15, 2); // total uang yang diterima saat itu
            $table->string('payment_method');
            $table->text('notes')->nullable();
            // Invoice mana saja yang KENA alokasi dari pembayaran ini,
            // beserta berapa yang dialokasikan ke masing-masing — dipakai
            // untuk BISA DIBATALKAN (kurangi lagi amount_paid invoice
            // terkait) kalau pembayaran ini di-edit/hapus.
            $table->json('allocations'); // [{invoice_id, amount}, ...]
            $table->decimal('credit_used', 15, 2)->default(0); // saldo lama yang ikut kepakai
            $table->decimal('credit_added', 15, 2)->default(0); // kelebihan yang jadi saldo baru
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};
