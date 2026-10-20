<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akun VPN yang SUDAH DISIAPKAN DULUAN di server VPN kita (dibuat
     * manual via vpncmd, atau nanti bisa diotomatiskan lewat API) —
     * tinggal PILIH dari dropdown pas nambah NAS, bukan isi username/
     * password/IP tunnel manual satu-satu tiap kali. Terinspirasi dari
     * pola layanan VPN pihak ketiga yang sudah kasih pool akun siap
     * pakai.
     */
    public function up(): void
    {
        Schema::create('vpn_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->text('password');
            $table->string('tunnel_ip'); // IP tetap di dalam tunnel VPN untuk akun ini
            $table->string('vpn_type'); // wireguard | sstp | pptp | l2tp
            $table->string('hub_name')->nullable(); // khusus SoftEther, kalau perlu format user@hub
            $table->foreignId('nas_id')->nullable()->constrained('nas')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_accounts');
    }
};
