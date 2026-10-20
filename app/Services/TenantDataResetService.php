<?php

namespace App\Services;

use App\Support\RadiusUsername;
use Illuminate\Support\Facades\DB;

/**
 * Hapus SEMUA data transaksi/percobaan tenant saat ini — dipakai kalau
 * mau "mulai dari nol" sebelum benar-benar dipakai komersial.
 *
 * YANG DIHAPUS: voucher, pelanggan, transaksi, invoice, pemasukan/
 * pengeluaran lain, log (aktivitas/login/API/pembayaran), komisi,
 * sesi RADIUS (radcheck/radreply/radusergroup/radacct/radpostauth
 * MILIK TENANT INI SAJA — tabel itu sekarang dipakai bersama semua
 * tenant, jadi WAJIB difilter pakai awalan username, TIDAK BOLEH
 * TRUNCATE polos).
 *
 * YANG DIPERTAHANKAN (pengaturan, bukan data percobaan): akun user
 * (SuperAdmin dkk), paket layanan, IP Pool, bandwidth, NAS/Router,
 * akun VPN, pengaturan aplikasi (Setting), outlet/reseller.
 *
 * SENGAJA TIDAK bisa dipanggil untuk instalasi utama (tanpa awalan
 * tenant) — terlalu berisiko salah hapus data tenant lain karena tidak
 * ada cara aman membedakan "punya siapa" tanpa awalan. Dicegah di
 * TenantDataResetPage, BUKAN di sini (biar service ini tetap bisa
 * dites/dipakai manual kalau benar-benar perlu, dengan hati-hati).
 */
class TenantDataResetService
{
    public function reset(): array
    {
        $counts = [];

        DB::connection('mysql')->transaction(function () use (&$counts) {
            // Urutan PENTING — hapus dulu yang "anak" (punya foreign key
            // ke tabel lain) sebelum yang "induk", biar tidak kena
            // constraint error.
            $counts['payment_logs'] = DB::table('payment_logs')->delete();
            $counts['biller_customer_commissions'] = DB::table('biller_customer_commissions')->delete();
            $counts['biller_outlet_commissions'] = DB::table('biller_outlet_commissions')->delete();
            $counts['invoices'] = DB::table('invoices')->delete();
            $counts['transactions'] = DB::table('transactions')->delete();
            $counts['voucher_logs'] = DB::table('voucher_logs')->delete();
            $counts['vouchers'] = DB::table('vouchers')->delete();
            $counts['customers'] = DB::table('customers')->delete();
            $counts['expenses'] = DB::table('expenses')->delete();
            $counts['other_incomes'] = DB::table('other_incomes')->delete();
            $counts['login_logs'] = DB::table('login_logs')->delete();
            $counts['activity_logs'] = DB::table('activity_logs')->delete();
            $counts['api_logs'] = DB::table('api_logs')->delete();
            $counts['radius_session_logs'] = DB::table('radius_session_logs')->delete();
        });

        // Tabel RADIUS di database PUSAT — WAJIB filter pakai awalan
        // username tenant ini, JANGAN TRUNCATE (itu bakal ikut hapus
        // punya tenant lain juga).
        $prefix = RadiusUsername::currentPrefix();

        if ($prefix) {
            $likePattern = $prefix.'_%';

            DB::connection('central')->transaction(function () use (&$counts, $likePattern) {
                $counts['radcheck'] = DB::connection('central')->table('radcheck')->where('username', 'like', $likePattern)->delete();
                $counts['radreply'] = DB::connection('central')->table('radreply')->where('username', 'like', $likePattern)->delete();
                $counts['radusergroup'] = DB::connection('central')->table('radusergroup')->where('username', 'like', $likePattern)->delete();
                $counts['radacct'] = DB::connection('central')->table('radacct')->where('username', 'like', $likePattern)->delete();
                $counts['radpostauth'] = DB::connection('central')->table('radpostauth')->where('username', 'like', $likePattern)->delete();
            });
        }

        return $counts;
    }
}
