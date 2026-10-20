<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jadwal jatuh tempo TAGIHAN TENANT KE OWNER (bukan tagihan
     * pelanggan-ke-ISP, itu urusan tabel `invoices` di dalam database
     * masing-masing tenant, beda konsep sama sekali). Tanggal ini yang
     * dipakai TenantBillingReminderWidget di dashboard tenant untuk
     * munculin pengingat "H-10 jatuh tempo, segera bayar".
     */
    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->date('billing_due_date')->nullable()->after('plan_catalog_id');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->dropColumn('billing_due_date');
        });
    }
};
