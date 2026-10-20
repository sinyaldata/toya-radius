<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->foreignId('bandwidth_id')->nullable()->after('router_id')
                ->constrained('bandwidths')->nullOnDelete();
            $table->string('limit_type', 20)->default('unlimited')->after('bandwidth_id'); // unlimited | waktu | kuota
            $table->unsignedInteger('time_limit_value')->nullable()->after('limit_type');
            $table->string('time_limit_unit', 10)->nullable()->after('time_limit_value'); // menit | jam | hari | bulan
            $table->unsignedInteger('quota_limit_mb')->nullable()->after('time_limit_unit');
            $table->unsignedInteger('validity_days')->default(30)->after('quota_limit_mb'); // Masa Berlaku
            $table->unsignedTinyInteger('priority')->default(8)->after('validity_days');
            $table->unsignedInteger('shared_users')->default(1)->after('priority');
            $table->decimal('cost_price', 12, 2)->default(0)->after('price'); // Harga Modal (price lama jadi Harga Jual)
            $table->text('description')->nullable()->after('cost_price');
        });

        // "price" yang lama sekarang berperan sebagai "Harga Jual" — beri komentar via rename kolom
        Schema::table('service_packages', function (Blueprint $table) {
            $table->renameColumn('price', 'sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->renameColumn('sale_price', 'price');
        });

        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bandwidth_id');
            $table->dropColumn([
                'limit_type', 'time_limit_value', 'time_limit_unit', 'quota_limit_mb',
                'validity_days', 'priority', 'shared_users', 'cost_price', 'description',
            ]);
        });
    }
};
