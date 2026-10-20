<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lapis pengaman kedua: kolom mikrotik_profile di service_packages
     * (dan voucher_profiles peninggalan lama) sebelumnya NOT NULL TANPA
     * default — kalau ada jalur simpan yang lupa mengisinya (mis. bug form
     * di masa depan), langsung error 500 di database, bukan pesan error
     * yang jelas. Sekarang dikasih default 'DEFAULT' (nama profile bawaan
     * MikroTik) supaya gagal dengan aman, bukan meledak.
     */
    public function up(): void
    {
        if (Schema::hasColumn('service_packages', 'mikrotik_profile')) {
            DB::statement("ALTER TABLE service_packages MODIFY mikrotik_profile VARCHAR(255) NOT NULL DEFAULT 'DEFAULT'");
        }

        if (Schema::hasColumn('voucher_profiles', 'mikrotik_profile')) {
            DB::statement("ALTER TABLE voucher_profiles MODIFY mikrotik_profile VARCHAR(255) NOT NULL DEFAULT 'DEFAULT'");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_packages', 'mikrotik_profile')) {
            DB::statement("ALTER TABLE service_packages MODIFY mikrotik_profile VARCHAR(255) NOT NULL");
        }

        if (Schema::hasColumn('voucher_profiles', 'mikrotik_profile')) {
            DB::statement("ALTER TABLE voucher_profiles MODIFY mikrotik_profile VARCHAR(255) NOT NULL");
        }
    }
};
