<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // dipakai job sinkronisasi radacct -> voucher, agar tidak scan ulang dari awal setiap jalan
            $table->timestamp('activation_checked_at')->nullable()->after('activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('activation_checked_at');
        });
    }
};
