<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 30)->unique(); // auto: INV-YYYYMM-XXXX
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->unsignedTinyInteger('period_month'); // 1-12
            $table->unsignedSmallInteger('period_year');
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->string('status', 20)->default('unpaid'); // unpaid | paid | overdue (enum InvoiceStatus)
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 20)->nullable(); // tunai | transfer
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['customer_id', 'period_month', 'period_year']); // 1 pelanggan hanya 1 invoice per periode
            $table->index('status');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
