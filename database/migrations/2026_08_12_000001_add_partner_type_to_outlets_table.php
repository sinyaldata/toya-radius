<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            // Semua data lama (dan default untuk baru) = 'outlet' (konsinyasi),
            // karena itu memang cara kerja outlet yang sudah ada selama ini.
            $table->string('partner_type')->default('outlet')->after('code');
            $table->decimal('saldo', 12, 2)->default(0)->after('commission_percent');
            $table->decimal('commission_flat_amount', 12, 2)->nullable()->after('saldo');
        });

        // Pivot: seorang Biller bisa menagih beberapa Outlet, dengan nominal
        // komisi berbeda per Outlet (bukan persentase, sesuai keputusan Owner).
        Schema::create('biller_outlet_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biller_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['biller_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biller_outlet_commissions');

        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['partner_type', 'saldo', 'commission_flat_amount']);
        });
    }
};
