<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel `nas` PINDAH ke database PUSAT (dipakai bersama SEMUA tenant)
     * — soalnya FreeRADIUS itu SATU proses tunggal yang cuma baca DARI
     * SATU database (dikonfigurasi statis di /etc/freeradius, tidak bisa
     * "ikut" pindah-pindah database per tenant seperti aplikasi Laravel
     * ini). Kolom `tenant_id` dipakai APLIKASI (bukan FreeRADIUS) buat
     * mem-filter NAS mana yang boleh dilihat/diedit tenant mana.
     */
    public function up(): void
    {
        Schema::connection('central')->create('nas', function (Blueprint $table) {
            $table->id('id');
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('nasname', 128);
            $table->string('shortname', 32);
            $table->string('type', 30)->default('other');
            $table->string('connection_type', 20)->default('public_ip');
            $table->string('vpn_type')->nullable();
            $table->string('api_username')->nullable();
            $table->text('api_password')->nullable();
            $table->integer('ports')->nullable();
            $table->string('secret', 60);
            $table->integer('auth_port')->default(1812);
            $table->integer('acct_port')->default(1813);
            $table->string('timezone')->nullable();
            $table->string('server', 64)->nullable();
            $table->string('community', 50)->nullable();
            $table->string('description', 200)->nullable()->default('');

            // router_id — sekarang BOLEH pakai foreign key beneran,
            // soalnya tabel `routers` juga dipindah ke database pusat
            // (lihat migration terpisah), tidak lagi lintas-database.
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('nas');
    }
};
