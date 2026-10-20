<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_number', 30)->unique(); // auto: CUST-0001
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('type', 20); // hotspot | pppoe (enum CustomerType)
            $table->string('username', 64)->unique(); // sama dgn radcheck.username
            $table->string('password', 64);
            $table->foreignId('service_package_id')->constrained('service_packages')->restrictOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('status', 20)->default('active'); // active | suspended | isolir (enum CustomerStatus)
            $table->unsignedTinyInteger('billing_day')->default(1); // tanggal jatuh tempo tiap bulan (1-28)
            $table->string('static_ip', 45)->nullable(); // khusus PPPoE dgn IP statis, kosongkan utk dynamic pool
            $table->date('installation_date')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
