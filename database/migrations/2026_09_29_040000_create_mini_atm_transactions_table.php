<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mini_atm_transactions')) {
            return;
        }

        Schema::create('mini_atm_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('number', 40);
            $table->string('type', 20); // tarik, setor, transfer, isi_saldo, tarik_saldo, koreksi
            $table->string('bank_name', 60)->nullable();
            $table->string('account_number', 40)->nullable();
            $table->string('account_name', 100)->nullable();
            $table->decimal('amount', 15, 2)->default(0);      // nominal transaksi
            $table->decimal('fee', 15, 2)->default(0);         // jasa (pendapatan)
            $table->decimal('bank_fee', 15, 2)->default(0);    // biaya admin bank (pengurang)
            $table->decimal('cash_effect', 15, 2)->default(0); // pengaruh ke saldo cash (+/-)
            $table->decimal('bank_effect', 15, 2)->default(0); // pengaruh ke saldo rekening (+/-)
            $table->text('notes')->nullable();
            $table->boolean('is_void')->default(false);
            $table->timestamp('voided_at')->nullable();
            $table->dateTime('transacted_at')->index();
            $table->timestamps();

            $table->unique(['store_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mini_atm_transactions');
    }
};
