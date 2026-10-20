<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel radcheck/radreply/radgroupcheck/radgroupreply/radusergroup
     * PINDAH ke database PUSAT — FreeRADIUS itu SATU proses yang cuma
     * baca dari SATU database (statis, tidak bisa "ikut" ganti-ganti
     * tenant). Struktur kolom TETAP mengikuti skema resmi FreeRADIUS
     * (schema.sql) — jangan diubah sembarangan, biar FreeRADIUS tetap
     * bisa langsung baca tanpa konfigurasi tambahan.
     *
     * Username SEKARANG WAJIB unik secara GLOBAL (lintas tenant) — lihat
     * FreeRadiusService, tenant selain "sinyaldata" (utama) dapat awalan
     * otomatis di depan username (mis. "ijoenet26_A3F9K2") biar tidak
     * bentrok sesama tenant lain yang berbagi tabel yang sama ini.
     */
    public function up(): void
    {
        Schema::connection('central')->create('radcheck', function (Blueprint $table) {
            $table->id('id');
            $table->string('username', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->string('op', 2)->default('==');
            $table->string('value', 253)->default('');
            $table->index('username');
            // voucher_id/customer_id SENGAJA TIDAK di-foreign-key — tabel
            // `vouchers`/`customers` tetap hidup DI DALAM database
            // masing-masing tenant (data bisnis, bukan data RADIUS), jadi
            // ini murni angka biasa, dicocokkan manual oleh kode aplikasi
            // tiap tenant.
            $table->unsignedBigInteger('voucher_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
        });

        Schema::connection('central')->create('radreply', function (Blueprint $table) {
            $table->id('id');
            $table->string('username', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->string('op', 2)->default('=');
            $table->string('value', 253)->default('');
            $table->index('username');
            $table->unsignedBigInteger('voucher_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
        });

        Schema::connection('central')->create('radgroupcheck', function (Blueprint $table) {
            $table->id('id');
            $table->string('groupname', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->string('op', 2)->default('==');
            $table->string('value', 253)->default('');
            $table->index('groupname');
        });

        Schema::connection('central')->create('radgroupreply', function (Blueprint $table) {
            $table->id('id');
            $table->string('groupname', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->string('op', 2)->default('=');
            $table->string('value', 253)->default('');
            $table->index('groupname');
        });

        Schema::connection('central')->create('radusergroup', function (Blueprint $table) {
            $table->id('id');
            $table->string('username', 64)->default('');
            $table->string('groupname', 64)->default('');
            $table->integer('priority')->default(1);
            $table->index('username');
            $table->unsignedBigInteger('voucher_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('radusergroup');
        Schema::connection('central')->dropIfExists('radgroupreply');
        Schema::connection('central')->dropIfExists('radgroupcheck');
        Schema::connection('central')->dropIfExists('radreply');
        Schema::connection('central')->dropIfExists('radcheck');
    }
};
