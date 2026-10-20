<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambah kolom relasi `biller_id` (menunjuk ke `outlets`, dengan
     * partner_type = biller) di `payment_logs` dan `cashier_deposits`.
     *
     * Kenapa perlu kolom sendiri (bukan cukup catatan teks bebas di
     * `notes`): supaya bisa DIREKAP per-biller (mis. "biller Budi sudah
     * mencatat pembayaran berapa bulan ini") lewat query biasa, bukan
     * lewat cari-cari teks. Nullable karena sebagian besar baris tetap
     * dicatat admin/aplikasi langsung, tanpa biller sama sekali.
     */
    public function up(): void
    {
        Schema::table('payment_logs', function (Blueprint $table) {
            $table->foreignId('biller_id')->nullable()->after('recorded_by')
                ->constrained('outlets')->nullOnDelete();
        });

        Schema::table('cashier_deposits', function (Blueprint $table) {
            $table->foreignId('biller_id')->nullable()->after('received_by')
                ->constrained('outlets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('biller_id');
        });

        Schema::table('cashier_deposits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('biller_id');
        });
    }
};
