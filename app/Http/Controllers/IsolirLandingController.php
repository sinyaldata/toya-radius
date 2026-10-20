<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Halaman "walled garden" — yang dilihat PELANGGAN saat internetnya
 * diisolir (bukan halaman admin; App\Filament\Pages\IsolirPage yang
 * itu). PUBLIK, tanpa login, karena yang membukanya adalah browser
 * pelanggan yang lalu lintasnya dialihkan MikroTik ke sini.
 *
 * DUA CARA halaman ini bisa dibuka (lihat routes/web.php):
 *  1. Subdomain khusus, mis. isolir.toyaqi.web.id -- ini yang dipasang
 *     di NAT MikroTik (lihat App\Support\IsolirScript).
 *  2. Jalur biasa /isolir di domain utama -- buat dicoba manual dari
 *     browser admin tanpa perlu DNS subdomain terpasang dulu.
 *
 * TIDAK BISA memastikan pelanggan yang mana yang membuka ini: MikroTik
 * meneruskan permintaan HTTP polos (bukan hotspot, tidak ada info
 * login yang ikut terbawa), jadi halaman ini SENGAJA generik -- sama
 * untuk semua pelanggan tenant itu. Kalau nanti mau dipersonalisasi
 * (nama pelanggan, jumlah tagihan), itu langkah lanjutan yang butuh
 * mencocokkan IP sumber permintaan ini ke pelanggan aktif -- belum
 * dikerjakan di sini karena IP publik pelanggan (di balik NAT
 * MikroTik) BUKAN IP yang sama dengan IP lokal yang tercatat di
 * radacct, jadi pencocokannya tidak sesederhana itu.
 */
class IsolirLandingController extends Controller
{
    public function show(Request $request): View
    {
        return view('isolir.show', [
            'namaIsp' => Setting::get('isp_name', config('app.name')),
            'judul' => Setting::get('isolir_judul') ?: 'Layanan Internet Anda Sedang Tidak Aktif',
            'pesan' => Setting::get('isolir_pesan') ?: 'Mohon maaf, layanan internet Anda saat ini dihentikan sementara karena tagihan yang belum dibayar. Silakan segera lakukan pembayaran agar internet Anda kembali aktif.',
            'wa' => Setting::get('isolir_wa') ?: Setting::get('isp_contact'),
            'rekening' => Setting::get('isolir_rekening'),
        ]);
    }
}
