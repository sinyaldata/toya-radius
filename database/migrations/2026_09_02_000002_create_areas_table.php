<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master data Area/Wilayah — dipakai untuk mengelompokkan
        // Outlet/Reseller/Biller berdasarkan wilayah, supaya laporan
        // bisa disaring per wilayah (mis. "Aikmel", "Masbagik", dst).
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 20)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->foreignId('area_id')
                ->nullable()
                ->after('partner_type')
                ->constrained('areas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropForeign(['area_id']);
            $table->dropColumn('area_id');
        });

        Schema::dropIfExists('areas');
    }
};
