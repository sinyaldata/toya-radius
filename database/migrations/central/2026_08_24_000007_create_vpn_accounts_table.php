<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akun VPN — PINDAH ke database pusat bareng `nas` (terikat lewat
     * nas_id). Owner tetap satu-satunya yang bisa provisioning (lihat
     * /vpn-owner, ada selektor tenant), TIDAK self-service oleh tenant.
     */
    public function up(): void
    {
        Schema::connection('central')->create('vpn_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('username')->unique();
            $table->text('password');
            $table->string('tunnel_ip');
            $table->string('vpn_type');
            $table->string('hub_name')->nullable();
            $table->foreignId('nas_id')->nullable()->constrained('nas')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('vpn_accounts');
    }
};
