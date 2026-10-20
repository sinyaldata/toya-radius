<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            // Batas TOTAL bandwidth gabungan untuk semua pelanggan yang
            // dapat IP dari pool ini (Parent Queue). Kosong = tidak
            // dibatasi (tidak ada parent queue dibuat).
            $table->string('max_download')->nullable()->after('prefix_length'); // mis. "50M"
            $table->string('max_upload')->nullable()->after('max_download');    // mis. "50M"
        });
    }

    public function down(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            $table->dropColumn(['max_download', 'max_upload']);
        });
    }
};
