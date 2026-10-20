<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daftar SEMUA tenant (pelanggan Toya Radius) — hidup di database
     * PUSAT ('central'), terpisah dari database masing-masing tenant.
     * Jalankan migration ini KHUSUS ke koneksi central:
     *   php artisan migrate --database=central --path=database/migrations/central
     */
    public function up(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // nama ISP/pelanggan, mis. "Ijoe Net"
            $table->string('subdomain')->unique(); // "ijoe" → ijoe.sinyaldata.web.id
            $table->string('database_name')->unique(); // nama database MySQL khusus tenant ini
            $table->string('owner_email')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('address')->nullable();
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
            $table->timestamp('provisioned_at')->nullable(); // kapan database-nya selesai dibuat
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenants');
    }
};
