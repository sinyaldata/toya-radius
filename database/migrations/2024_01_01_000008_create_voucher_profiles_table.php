<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mikrotik_profile'); // nama profile hotspot di mikrotik (rate-limit target)
            $table->string('rate_limit', 50); // format mikrotik: "2M/2M" dsb
            $table->unsignedInteger('session_timeout')->nullable(); // detik, 0/null = unlimited
            $table->unsignedInteger('idle_timeout')->nullable(); // detik
            $table->unsignedInteger('shared_users')->default(1);
            $table->unsignedInteger('validity_days'); // masa aktif voucher setelah login pertama
            $table->string('bandwidth_label')->nullable(); // label utk tampilan, mis. "10 Mbps"
            $table->decimal('cost_price', 12, 2)->default(0); // harga modal
            $table->decimal('sale_price', 12, 2); // harga jual
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_profiles');
    }
};
