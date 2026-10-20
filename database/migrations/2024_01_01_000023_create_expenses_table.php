<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number', 30)->unique(); // auto: EXP-YYYYMM-XXXX
            $table->date('expense_date');
            $table->string('category', 30); // gaji | internet | barang | listrik | sewa | perawatan | lainnya
            $table->string('title'); // mis. "Gaji Karyawan Agustus"
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 20)->default('tunai'); // tunai | transfer
            $table->string('recipient')->nullable(); // penerima / vendor
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('expense_date');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
