<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "IP Pool" berganti nama jadi "Group User" di UI — sebelumnya
     * cuma dipakai PPPoE, sekarang bisa juga dipakai Hotspot (atau
     * dua-duanya), ditandai kolom ini.
     */
    public function up(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            $table->string('service_type')->default('pppoe')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            $table->dropColumn('service_type');
        });
    }
};
