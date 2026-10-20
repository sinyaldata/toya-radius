<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Struktur tabel `nas` mengikuti skema standar FreeRADIUS (raddb/mods-config/sql/main/mysql/schema.sql)
     * agar 100% kompatibel dengan FreeRADIUS tanpa modifikasi module SQL.
     */
    public function up(): void
    {
        Schema::create('nas', function (Blueprint $table) {
            $table->id('id');
            $table->string('nasname', 128);
            $table->string('shortname', 32);
            $table->string('type', 30)->default('other');
            $table->integer('ports')->nullable();
            $table->string('secret', 60);
            $table->string('server', 64)->nullable();
            $table->string('community', 50)->nullable();
            $table->string('description', 200)->nullable()->default('');

            // Kolom tambahan non-standar utk relasi ke aplikasi (aman, tidak dipakai FreeRADIUS core)
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nas');
    }
};
