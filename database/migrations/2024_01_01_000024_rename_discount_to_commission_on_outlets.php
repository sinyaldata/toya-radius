<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Istilah "Diskon" diganti "Komisi" untuk Outlet/Reseller — lebih tepat
     * secara bisnis: outlet mendapat komisi dari tiap voucher yang laku
     * (sistem titip voucher), bukan potongan harga beli.
     *
     * Pakai SQL mentah supaya tidak perlu doctrine/dbal.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE outlets CHANGE discount_percent commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE outlets CHANGE commission_percent discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0');
    }
};
