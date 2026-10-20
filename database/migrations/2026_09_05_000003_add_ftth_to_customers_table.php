<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom FTTH pada pelanggan.
 *
 * latitude/longitude BOLEH kosong: kalau kosong, peta memakai koordinat
 * ODP pelanggan itu sebagai cadangan (keputusan user). Jadi pelanggan
 * lama tetap muncul di peta begitu ODP-nya diberi titik, tanpa harus
 * mengisi koordinat satu per satu lebih dulu.
 *
 * acs_device_id menyimpan _id perangkat di GenieACS. Sengaja disimpan di
 * sini, BUKAN tabel perangkat tersendiri: daftar ONU-nya selalu dibaca
 * langsung (live) dari GenieACS, yang perlu disimpan aplikasi ini
 * hanyalah "ONU mana milik pelanggan mana". Menyalin seluruh data
 * perangkat ke sini cuma akan jadi salinan basi yang harus disinkronkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('odp_id')->nullable()->after('service_package_id')
                ->constrained('odps')->nullOnDelete();
            $table->string('odp_port', 20)->nullable()->after('odp_id');
            $table->decimal('latitude', 10, 7)->nullable()->after('odp_port');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('acs_device_id')->nullable()->after('longitude');

            $table->index('odp_id');
            $table->index('acs_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('odp_id');
            $table->dropIndex(['acs_device_id']);
            $table->dropColumn(['odp_port', 'latitude', 'longitude', 'acs_device_id']);
        });
    }
};
