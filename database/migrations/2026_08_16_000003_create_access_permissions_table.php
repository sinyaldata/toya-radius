<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_permissions', function (Blueprint $table) {
            $table->id();
            // role_key: 'user' (staff/Admin biasa), 'reseller', 'outlet',
            // 'biller' — 3 terakhir dibedakan lewat partner_type Outlet
            // (login-nya sendiri semua pakai RoleType::Outlet yang sama,
            // jadi TIDAK bisa dibedakan lewat role — makanya pakai
            // partner_type sebagai sumber pembeda yang sebenarnya).
            $table->string('role_key')->unique();
            // Daftar nama grup menu (NavigationGroup) yang boleh diakses
            // role ini, mis. ["Mitra","Hotspot","Invoice"].
            $table->json('allowed_groups')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_permissions');
    }
};
