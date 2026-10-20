<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migration ADITIF & AMAN — kolom lama (rate_limit, bandwidth_label) TIDAK dihapus,
     * supaya data profile voucher yang sudah ada tidak rusak. Kolom baru nullable dulu,
     * admin tinggal edit tiap profile lewat form untuk pilih Bandwidth-nya.
     */
    public function up(): void
    {
        Schema::table('voucher_profiles', function (Blueprint $table) {
            $table->foreignId('bandwidth_id')->nullable()->after('router_id')
                ->constrained('bandwidths')->nullOnDelete();
            $table->string('limit_type', 20)->default('waktu')->after('bandwidth_id'); // unlimited | waktu | kuota
            $table->unsignedInteger('quota_limit_mb')->nullable()->after('idle_timeout'); // batas kuota data, null = unlimited
            $table->unsignedTinyInteger('priority')->default(8)->after('shared_users'); // 1 (tinggi) - 8 (rendah), default mikrotik
            $table->text('description')->nullable()->after('bandwidth_label');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bandwidth_id');
            $table->dropColumn(['limit_type', 'quota_limit_mb', 'priority', 'description']);
        });
    }
};
