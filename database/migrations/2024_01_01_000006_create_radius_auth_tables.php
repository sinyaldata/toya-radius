<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel radcheck, radreply, radgroupcheck, radgroupreply, radusergroup
     * mengikuti skema resmi FreeRADIUS (schema.sql) agar server radius bisa
     * langsung membaca tabel ini tanpa konfigurasi tambahan di raddb/mods-available/sql.
     */
    public function up(): void
    {
        Schema::create('radcheck', function (Blueprint $table) {
            $table->id('id');
            $table->string('username', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->string('op', 2)->default('==');
            $table->string('value', 253)->default('');
            $table->index('username');
            $table->unsignedBigInteger('voucher_id')->nullable(); // FK ditambahkan setelah tabel vouchers dibuat
        });

        Schema::create('radreply', function (Blueprint $table) {
            $table->id('id');
            $table->string('username', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->string('op', 2)->default('=');
            $table->string('value', 253)->default('');
            $table->index('username');
            $table->unsignedBigInteger('voucher_id')->nullable();
        });

        Schema::create('radgroupcheck', function (Blueprint $table) {
            $table->id('id');
            $table->string('groupname', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->string('op', 2)->default('==');
            $table->string('value', 253)->default('');
            $table->index('groupname');
        });

        Schema::create('radgroupreply', function (Blueprint $table) {
            $table->id('id');
            $table->string('groupname', 64)->default('');
            $table->string('attribute', 64)->default('');
            $table->string('op', 2)->default('=');
            $table->string('value', 253)->default('');
            $table->index('groupname');
        });

        Schema::create('radusergroup', function (Blueprint $table) {
            $table->id('id');
            $table->string('username', 64)->default('');
            $table->string('groupname', 64)->default('');
            $table->integer('priority')->default(1);
            $table->index('username');
            $table->unsignedBigInteger('voucher_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radusergroup');
        Schema::dropIfExists('radgroupreply');
        Schema::dropIfExists('radgroupcheck');
        Schema::dropIfExists('radreply');
        Schema::dropIfExists('radcheck');
    }
};
