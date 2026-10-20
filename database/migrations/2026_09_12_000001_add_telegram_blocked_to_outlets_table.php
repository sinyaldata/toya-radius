<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            // Beda dengan sekadar "telegram_chat_id kosong" (yang cuma
            // berarti belum pernah/sedang tidak terhubung) -- kolom ini
            // MEMBLOKIR permanen supaya outlet/reseller/biller yang
            // sudah berhenti kerja sama tidak bisa terhubung LAGI ke bot
            // Telegram, walau mereka masih pegang kode /link lama atau
            // minta kode baru. Permintaan user.
            $table->boolean('telegram_blocked')->default(false)->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn('telegram_blocked');
        });
    }
};
