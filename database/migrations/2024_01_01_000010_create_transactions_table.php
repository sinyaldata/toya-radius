<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 30)->unique(); // auto: TRX-YYYYMMDD-XXXX
            $table->foreignId('voucher_id')->constrained('vouchers')->restrictOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete(); // kasir
            $table->decimal('price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->string('payment_method', 20); // tunai | transfer
            $table->string('payment_status', 20)->default('lunas'); // lunas | pending
            $table->timestamp('transaction_date');
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('transaction_date');
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
