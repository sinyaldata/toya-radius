<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // Asal voucher dibuat: 'app' (lewat aplikasi/panel) atau
            // 'telegram' (lewat bot Telegram). Sengaja NULLABLE tanpa
            // default supaya voucher LAMA (yang dibuat sebelum fitur ini
            // ada) tetap kosong/"-" — bukan diklaim dibuat lewat aplikasi,
            // karena datanya memang tidak pernah dicatat.
            $table->string('generated_via', 20)->nullable()->after('generated_by')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex(['generated_via']);
            $table->dropColumn('generated_via');
        });
    }
};
