<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Katalog paket berlangganan Toya Radius yang bisa DIPILIH calon
     * pelanggan waktu daftar mandiri — beda dari `subscription_plans`
     * (yang hidup di DALAM database masing-masing tenant, dipakai
     * FreeRADIUS buat menegakkan batas). Tabel ini cuma "menu harga",
     * dikelola owner dari /vpn-owner.
     */
    public function up(): void
    {
        Schema::connection('central')->create('plan_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // "Bronze", "Gold", dst
            $table->unsignedInteger('max_concurrent_online');
            $table->decimal('price_per_month', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('plan_catalog');
    }
};
