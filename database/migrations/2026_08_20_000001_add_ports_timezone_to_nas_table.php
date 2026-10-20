<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Field tambahan buat form NAS yang lebih lengkap (terinspirasi
     * referensi aplikasi lain) — auth_port/acct_port sifatnya CATATAN
     * REFERENSI (port RADIUS yang sebenarnya tetap diatur global lewat
     * konfigurasi FreeRADIUS server, bukan per-NAS — tapi berguna buat
     * dicatat kalau suatu saat port-nya memang custom/beda).
     */
    public function up(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->integer('auth_port')->default(1812)->after('secret');
            $table->integer('acct_port')->default(1813)->after('auth_port');
            $table->string('timezone')->nullable()->after('acct_port');
        });
    }

    public function down(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->dropColumn(['auth_port', 'acct_port', 'timezone']);
        });
    }
};
