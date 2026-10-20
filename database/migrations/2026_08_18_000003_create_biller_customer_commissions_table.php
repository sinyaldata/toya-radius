<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sebelumnya Biller cuma bisa ditugaskan menagih OUTLET (lihat
     * biller_outlet_commissions). Tabel ini menambah opsi menagih
     * PELANGGAN LANGSUNG (PPPoE maupun Hotspot — dua-duanya sama-sama
     * tabel `customers`, dibedakan lewat kolom `type`), dengan komisi
     * tetap (nominal rupiah) per pelanggan, sama seperti pola outlet.
     */
    public function up(): void
    {
        Schema::create('biller_customer_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biller_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['biller_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biller_customer_commissions');
    }
};
