<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom baru untuk pelanggan tipe Static IP (DHCP Lease MikroTik) --
 * lihat CustomerType::StaticIp. Pelanggan tipe ini TIDAK login pakai
 * username/password seperti PPPoE/Hotspot; identitasnya di jaringan
 * cuma lewat MAC Address perangkat, yang diikat ke IP tetap (kolom
 * `static_ip` yang SUDAH ADA) lewat DHCP Static Lease di MikroTik.
 *
 * Sengaja NULLABLE dan TANPA unique constraint di level database
 * (beda dengan `username`) -- supaya migration ini aman dijalankan
 * di instalasi yang sudah berjalan lama (pelanggan PPPoE/Hotspot lama
 * otomatis NULL di kolom ini, tidak masalah). Keunikannya divalidasi
 * di form Filament saja (StaticIpUserResource), bukan di database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('mac_address', 20)->nullable()->after('static_ip');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('mac_address');
        });
    }
};
