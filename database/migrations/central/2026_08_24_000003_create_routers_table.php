<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel `routers` (kredensial API MikroTik) PINDAH ke database
     * PUSAT, bareng `nas` — soalnya keduanya terikat erat 1:1 (tiap NAS
     * VPN/API-managed punya 1 Router pasangannya). Kolom `tenant_id`
     * dipakai APLIKASI buat mem-filter router mana milik tenant mana.
     */
    public function up(): void
    {
        Schema::connection('central')->create('routers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('name');
            $table->string('ip_address', 45);
            $table->unsignedInteger('api_port')->default(8728);
            $table->string('api_mode')->default('legacy');
            $table->integer('www_port')->nullable();
            $table->string('username');
            $table->text('password');
            $table->string('secret')->nullable();
            $table->boolean('use_ssl')->default(false);
            $table->string('connection_status')->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->string('routeros_version')->nullable();
            $table->string('identity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('routers');
    }
};
