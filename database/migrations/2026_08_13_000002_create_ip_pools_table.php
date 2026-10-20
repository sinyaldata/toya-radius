<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
            $table->string('start_ip'); // mis. 10.10.10.2
            $table->string('end_ip');   // mis. 10.10.10.254
            $table->string('gateway')->nullable();   // Framed-IP-Netmask / Local-Address sisi router
            $table->unsignedTinyInteger('prefix_length')->default(24); // /24 dst
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // IP dari pool mana yang dipakai profile ini. Kalau kosong, IP
        // pelanggan tetap ditentukan MikroTik seperti sebelumnya (tidak
        // ada perubahan perilaku untuk profile lama yang belum diisi).
        Schema::table('service_packages', function (Blueprint $table) {
            $table->foreignId('ip_pool_id')->nullable()->after('router_id')->constrained('ip_pools')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ip_pool_id');
        });

        Schema::dropIfExists('ip_pools');
    }
};
