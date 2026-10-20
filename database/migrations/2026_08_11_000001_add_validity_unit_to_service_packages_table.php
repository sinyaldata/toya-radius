<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            // Satuan untuk validity_days: 'jam' atau 'hari'. Default 'hari' supaya
            // data lama (yang selama ini selalu berarti hari) tetap berperilaku sama.
            $table->string('validity_unit')->default('hari')->after('validity_days');
        });
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropColumn('validity_unit');
        });
    }
};
