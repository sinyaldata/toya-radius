<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah penanda "Khusus Member" pada Profile/ServicePackage.
 *
 * KENAPA PERLU: paket "Member Hotspot" (langganan bulanan, dikelola
 * lewat menu Member) dan paket "Voucher" (sekali pakai, dijual lewat
 * generate voucher di aplikasi maupun bot Telegram) SAMA-SAMA disimpan
 * di tabel service_packages, dengan type & is_active yang sama persis
 * -- sebelum ini TIDAK ADA cara membedakan keduanya secara otomatis,
 * jadi paket yang sebetulnya cuma untuk Member ikut muncul di daftar
 * pilihan generate voucher (permintaan user: itu seharusnya TIDAK
 * boleh tampil).
 *
 * Kolom ini defaultnya FALSE untuk semua paket lama (voucher seperti
 * biasa) -- admin tinggal AKTIFKAN toggle "Khusus Member" di paket-
 * paket yang memang cuma dipakai untuk Member lewat menu Profile
 * Hotspot, supaya otomatis hilang dari daftar pilihan generate voucher
 * (baik di aplikasi maupun bot Telegram) tapi tetap muncul normal di
 * form Member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->boolean('is_member_only')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropColumn('is_member_only');
        });
    }
};
