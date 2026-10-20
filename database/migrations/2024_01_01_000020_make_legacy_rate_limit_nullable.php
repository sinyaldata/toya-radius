<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Kolom `rate_limit` (legacy) di kedua tabel ini sengaja dipertahankan
     * demi kompatibilitas mundur, tapi sekarang sumber kebenarannya adalah
     * relasi `bandwidth_id` (lihat accessor effective_rate_limit di Model).
     * Form input sudah tidak lagi mengisi rate_limit, jadi kolom ini WAJIB
     * nullable supaya insert tidak gagal.
     *
     * Pakai SQL mentah (bukan ->change()) supaya TIDAK perlu install
     * package tambahan doctrine/dbal.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE service_packages MODIFY rate_limit VARCHAR(50) NULL');
        DB::statement('ALTER TABLE voucher_profiles MODIFY rate_limit VARCHAR(50) NULL');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE service_packages MODIFY rate_limit VARCHAR(50) NOT NULL DEFAULT ''");
        DB::statement("ALTER TABLE voucher_profiles MODIFY rate_limit VARCHAR(50) NOT NULL DEFAULT ''");
    }
};
