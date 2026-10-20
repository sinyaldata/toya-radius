<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log login/logout pengguna jaringan (Voucher, User Hotspot tetap, PPPoE).
     *
     * Kenapa tidak langsung baca radacct saja? Karena radacct itu tabel SESI
     * (satu baris = satu sesi yang di-update terus oleh FreeRADIUS), bukan
     * jejak peristiwa. Tabel ini menyimpan peristiwa LOGIN dan LOGOUT sebagai
     * baris terpisah yang tidak pernah berubah, jadi riwayatnya tetap utuh
     * walau baris radacct-nya sudah di-rotate/dibersihkan.
     */
    public function up(): void
    {
        Schema::create('radius_session_logs', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->index();
            $table->string('user_type', 20); // voucher | hotspot | pppoe
            $table->string('event', 10); // login | logout
            $table->string('acctsessionid', 64)->nullable();
            $table->string('framedipaddress', 45)->nullable();
            $table->string('callingstationid', 50)->nullable(); // MAC
            $table->string('nasipaddress', 45)->nullable();
            $table->unsignedBigInteger('session_time')->nullable(); // detik, diisi saat logout
            $table->unsignedBigInteger('input_octets')->nullable();
            $table->unsignedBigInteger('output_octets')->nullable();
            $table->string('terminate_cause', 32)->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('event_at');
            $table->timestamps();

            $table->index(['event', 'event_at']);
            // Kunci anti-duplikat: satu sesi hanya boleh punya satu baris login
            // dan satu baris logout, biar job sinkronisasi aman dijalankan ulang.
            $table->unique(['acctsessionid', 'event'], 'radius_session_logs_session_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_session_logs');
    }
};
