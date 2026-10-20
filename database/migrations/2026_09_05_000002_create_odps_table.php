<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ODP (Optical Distribution Point) — kotak pembagi di lapangan, tempat
 * kabel ke rumah pelanggan disambungkan.
 *
 * total_ports diisi manual (kapasitas fisik kotaknya). Jumlah port
 * TERPAKAI SENGAJA TIDAK disimpan sebagai kolom: dihitung langsung dari
 * jumlah pelanggan yang menunjuk ke ODP ini. Kolom hitungan yang
 * disimpan terpisah pasti cepat atau lambat tidak sinkron dengan
 * kenyataan (pelanggan dipindah/dihapus tanpa lewat jalur yang meng-
 * update kolomnya), dan angka kapasitas yang salah di lapangan jauh
 * lebih merepotkan daripada satu query hitung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->nullable()->constrained('olts')->nullOnDelete();
            $table->string('name', 100);
            $table->string('code', 30)->nullable();
            $table->unsignedSmallInteger('total_ports')->default(8);
            $table->string('pon_port', 30)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('olt_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odps');
    }
};
