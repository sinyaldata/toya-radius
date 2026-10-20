<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OLT (Optical Line Terminal) — perangkat induk jaringan FTTH di sisi
 * operator. Satu OLT menaungi banyak ODP.
 *
 * Koordinat disimpan sebagai decimal(10,7) / decimal(10,7): 7 angka di
 * belakang koma cukup untuk ketelitian ~1 cm, jauh lebih dari cukup
 * untuk menandai tiang/kotak. Sengaja BUKAN float — float menyimpan
 * angka desimal secara tidak persis, dan koordinat yang bergeser sedikit
 * demi sedikit setiap kali disimpan ulang adalah bug yang sangat sulit
 * disadari.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 30)->nullable();
            $table->string('brand', 50)->nullable();
            $table->string('model', 50)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedSmallInteger('pon_ports')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olts');
    }
};
