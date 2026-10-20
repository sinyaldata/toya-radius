<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // mis. "Rumahan 5Mbps"
            $table->string('type', 20); // hotspot | pppoe | both (enum ServicePackageType)
            $table->string('rate_limit', 50); // format mikrotik: "5M/5M"
            $table->string('bandwidth_label')->nullable(); // label tampilan, mis. "5 Mbps"
            $table->decimal('price', 12, 2); // harga langganan per bulan
            $table->string('mikrotik_profile'); // nama ppp profile / hotspot user profile di router
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_packages');
    }
};
