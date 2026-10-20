<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menu "Produk Voucher" (voucher_profiles) dihapus karena isinya sama
     * persis dengan "Profile Hotspot" (service_packages). Mulai sekarang
     * voucher.voucher_profile_id menunjuk ke service_packages, bukan lagi
     * voucher_profiles. Kolom & tabel voucher_profiles TIDAK dihapus (data
     * lama tetap aman, hanya sudah tidak dipakai relasi baru).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE vouchers DROP FOREIGN KEY vouchers_voucher_profile_id_foreign');

        // Sengaja TIDAK menambahkan FK constraint keras ke service_packages di sini.
        // Kalau ada voucher lama (data uji coba) yang voucher_profile_id-nya masih
        // menunjuk ke voucher_profiles lama, ADD CONSTRAINT akan gagal karena id-nya
        // tidak match di service_packages. Voucher baru akan selalu diisi dari
        // service_packages (dijamin oleh aplikasi, bukan DB), voucher lama biarkan
        // apa adanya (masih bisa dilihat riwayatnya, tidak dipaksa hilang).
        Schema::table('vouchers', function (Blueprint $table) {
            $table->index('voucher_profile_id');
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->boolean('auto_bind_mac')->default(false)->after('generated_by');
            $table->string('bound_mac_address', 20)->nullable()->after('auto_bind_mac');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn(['auto_bind_mac', 'bound_mac_address']);
            $table->dropIndex(['voucher_profile_id']);
        });

        DB::statement('ALTER TABLE vouchers ADD CONSTRAINT vouchers_voucher_profile_id_foreign FOREIGN KEY (voucher_profile_id) REFERENCES voucher_profiles(id)');
    }
};
