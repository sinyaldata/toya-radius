<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom tambahan non-standar (aman, tidak dipakai FreeRADIUS core) —
     * menandai apakah NAS ini dijangkau lewat VPN (mis. WireGuard/OpenVPN
     * ke router pelanggan) atau langsung lewat IP Public.
     */
    public function up(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->string('connection_type', 20)->default('public_ip')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('nas', function (Blueprint $table) {
            $table->dropColumn('connection_type');
        });
    }
};
