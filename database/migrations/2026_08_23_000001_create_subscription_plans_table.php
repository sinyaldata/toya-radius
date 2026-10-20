<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paket berlangganan APLIKASI ITU SENDIRI (bukan paket Hotspot/PPPoE
     * yang dijual ISP ke pelanggannya — itu tabel service_packages, beda
     * konsep sama sekali). Ini punya OWNER/DEV Toya Radius, batasi berapa
     * user online BERSAMAAN yang boleh dipakai instalasi ini.
     *
     * Untuk sekarang cuma 1 baris (belum multi-tenant beneran) — tapi
     * strukturnya sudah siap ditambah tenant_id kalau nanti multi-tenant
     * dibangun.
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // mis. "Paket Bronze", "Paket Gold"
            $table->unsignedInteger('max_concurrent_online')->default(50);
            $table->decimal('price_per_month', 12, 2)->nullable();
            $table->date('active_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
