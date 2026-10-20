<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batasan paket ke outlet tertentu — kalau paket TIDAK PUNYA baris
     * apapun di tabel ini, artinya boleh dipakai SEMUA outlet (perilaku
     * lama, tetap dipertahankan). Kalau ADA minimal 1 baris, paket itu
     * HANYA boleh dipakai outlet yang tercantum di sini.
     */
    public function up(): void
    {
        Schema::create('service_package_outlet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['service_package_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_outlet');
    }
};
