<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_incomes', function (Blueprint $table) {
            $table->id();
            $table->string('income_number', 30)->unique(); // auto: INC-YYYYMM-XXXX
            $table->date('income_date');
            $table->string('category', 30); // instalasi | penjualan_alat | jasa | sewa | lainnya
            $table->string('title'); // mis. "Biaya Pasang Baru - Budi"
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 20)->default('tunai'); // tunai | transfer
            $table->string('source')->nullable(); // dari siapa / pelanggan
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('income_date');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_incomes');
    }
};
