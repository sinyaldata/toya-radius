<?php

namespace App\Services;

use App\Models\Radcheck;
use App\Models\Radreply;
use App\Models\Radusergroup;
use App\Models\Setting;
use App\Models\ServicePackage;
use App\Models\Voucher;
use App\Support\RadiusUsername;

/**
 * Menangani seluruh sinkronisasi data voucher ke tabel standar FreeRADIUS.
 * Setiap voucher = satu baris radcheck (Cleartext-Password), satu baris
 * radusergroup (grup profile), dan radreply bila ada atribut Mikrotik-Rate-Limit.
 *
 * PENTING: tabel radcheck/radreply/radusergroup sekarang DIPAKAI BERSAMA
 * semua tenant — username yang BENERAN ditulis ke situ dapat awalan
 * otomatis per tenant (lihat App\Support\RadiusUsername), supaya tidak
 * bentrok sesama tenant lain. Kolom `voucher->username` milik tenant
 * sendiri TETAP tidak berubah/tanpa awalan.
 */
class FreeRadiusService
{
    /**
     * Pesan yang ditampilkan di halaman login hotspot kalau voucher yang
     * sudah KADALUARSA dipakai login lagi. Bisa diganti per instalasi/tenant
     * lewat Setting `pesan_voucher_expired`.
     *
     * Tanpa karakter khusus/aksen: dikirim sebagai atribut Reply-Message
     * (maks. 253 byte) dan ditampilkan apa adanya oleh MikroTik.
     */
    public const PESAN_EXPIRED_BAWAAN = 'Voucher Anda sudah kadaluarsa (expired). Silakan beli voucher baru di outlet terdekat agar bisa internetan dengan lancar.';

    public static function pesanExpired(): string
    {
        $pesan = trim((string) Setting::get('pesan_voucher_expired', ''));

        // Atribut RADIUS maksimal 253 byte -- dipotong aman di batas karakter.
        return mb_strcut($pesan !== '' ? $pesan : self::PESAN_EXPIRED_BAWAAN, 0, 240);
    }

    /** Batas aman panjang Reply-Message (atribut RADIUS maks. 253 byte). */
    public const PESAN_MAKS_BYTE = 240;

    private const BULAN_SINGKAT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** "02 Okt 2026 12:40" -- nama bulan Indonesia, dalam zona waktu tanggal itu sendiri. */
    public static function tanggalPesan(?\Carbon\CarbonInterface $waktu): ?string
    {
        return $waktu ? sprintf('%02d %s %d %s', $waktu->day, self::BULAN_SINGKAT[$waktu->month - 1], $waktu->year, $waktu->format('H:i')) : null;
    }

    /** Buang karakter yang bisa merusak/diperluas FreeRADIUS (%{...}), garis miring, dan kontrol. */
    private static function bersihkanTeks(string $teks): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace(['%', '\\', '"', "'"], ['', '', '', ''], preg_replace('/[\x00-\x1F\x7F]/u', ' ', $teks))));
    }

    /**
     * Pesan LOGIN DITOLAK untuk SATU voucher yang sudah kadaluarsa -- memuat
     * paketnya, kapan diaktifkan, dan kapan kadaluarsa (permintaan user
     * 2026-10-03), di samping ajakan membeli voucher baru di outlet terdekat.
     *
     * Contoh:
     *  "Voucher Anda sudah kadaluarsa (expired). Paket: VOUCHER 2000 (6JAM),
     *   aktif: 02 Okt 2026 12:40, kadaluarsa: 02 Okt 2026 18:40. Silakan beli
     *   voucher baru di outlet terdekat agar bisa internetan dengan lancar."
     *
     * - Bagian yang datanya tidak ada (voucher lama tanpa tanggal aktif, tanpa
     *   paket) dilewati, bukan ditulis kosong.
     * - Teks di Setting `pesan_voucher_expired` boleh memakai {paket}, {aktif},
     *   {kadaluarsa}, {rincian}. Kalau tidak ada satu pun, rincian ditambahkan
     *   di akhir teksnya otomatis.
     * - Kalau terlalu panjang untuk atribut RADIUS, NAMA PAKET yang dipendekkan --
     *   bukan ajakan beli di ujung kalimat yang terpotong.
     */
    public static function pesanExpiredUntuk(Voucher $voucher): string
    {
        $paket = self::bersihkanTeks((string) ($voucher->profile?->name ?? ''));
        $aktif = self::tanggalPesan($voucher->activated_at);
        $kadaluarsa = self::tanggalPesan($voucher->expired_at);

        $susun = function (string $namaPaket) use ($aktif, $kadaluarsa): string {
            $template = trim((string) Setting::get('pesan_voucher_expired', ''));
            $bagian = array_filter([
                $namaPaket !== '' ? "Paket: {$namaPaket}" : null,
                $aktif ? "aktif: {$aktif}" : null,
                $kadaluarsa ? "kadaluarsa: {$kadaluarsa}" : null,
            ]);
            // Huruf pertama kapital supaya kalimat rincian tetap rapi walau bagian "Paket" tidak ada.
            $rincian = $bagian ? ucfirst(implode(', ', $bagian)).'.' : '';

            if ($template === '') {
                return implode(' ', array_filter([
                    'Voucher Anda sudah kadaluarsa (expired).',
                    $rincian,
                    'Silakan beli voucher baru di outlet terdekat agar bisa internetan dengan lancar.',
                ]));
            }

            $punyaPenanda = preg_match('/\{(paket|aktif|kadaluarsa|rincian)\}/', $template) === 1;
            $isi = strtr($template, [
                '{paket}' => $namaPaket,
                '{aktif}' => (string) $aktif,
                '{kadaluarsa}' => (string) $kadaluarsa,
                '{rincian}' => $rincian,
            ]);

            // Penanda yang datanya kosong meninggalkan spasi ganda -- dirapikan.
            return trim((string) preg_replace('/ {2,}/', ' ', $punyaPenanda || $rincian === '' ? $isi : $isi.' '.$rincian));
        };

        $pesan = $susun($paket);

        // Terlalu panjang -> pendekkan nama paket (satu per satu) sampai muat.
        while (strlen($pesan) > self::PESAN_MAKS_BYTE && mb_strlen($paket) > 8) {
            $lebih = strlen($pesan) - self::PESAN_MAKS_BYTE;
            $paket = rtrim(mb_substr($paket, 0, max(8, mb_strlen($paket) - max(1, $lebih + 2)))).'..';
            $pesan = $susun($paket);

            if (mb_strlen($paket) <= 10) {
                break;
            }
        }

        return mb_strcut($pesan, 0, self::PESAN_MAKS_BYTE);
    }

    public function provisionVoucher(Voucher $voucher, ServicePackage $profile): void
    {
        $username = RadiusUsername::forRadius($voucher->username);

        Radcheck::updateOrCreate(
            ['username' => $username, 'attribute' => 'Cleartext-Password'],
            ['op' => ':=', 'value' => $voucher->password, 'voucher_id' => $voucher->id]
        );

        // Session-Timeout WAJIB sebagai radREPLY, bukan radcheck.
        //
        // Session-Timeout itu atribut BALASAN (RFC 2865) -- FreeRADIUS
        // harus mengirimkannya BALIK ke NAS (Mikrotik) di dalam
        // Access-Accept, supaya Mikrotik sendiri yang menghitung mundur
        // dan memutus sesi begitu waktunya habis. radcheck TIDAK PERNAH
        // dikirim ke NAS -- itu atribut CEK yang cuma dibandingkan
        // internal oleh FreeRADIUS saat autentikasi.
        //
        // Sebelumnya atribut ini ditulis ke radcheck (dimaksudkan biar
        // "dibatasi RADIUS, bukan cuma Mikrotik" -- lihat riwayat kode).
        // Itu keliru: menaruhnya di radcheck bukan berarti "penegakan
        // ganda", tapi PENEGAKAN NIHIL -- Mikrotik tidak pernah tahu
        // batas waktunya sama sekali. Ini sebabnya kolom "Sisa Waktu" di
        // status.html/logout.html Hotspot ($(session-time-left), dihitung
        // Mikrotik dari atribut inilah) tidak pernah cocok dengan yang
        // diatur di paket -- Mikrotik memang tidak pernah menerimanya.
        if ($profile->session_timeout_seconds) {
            Radreply::updateOrCreate(
                ['username' => $username, 'attribute' => 'Session-Timeout'],
                ['op' => '=', 'value' => (string) $profile->session_timeout_seconds, 'voucher_id' => $voucher->id]
            );
        } else {
            // Paket tanpa batas waktu (unlimited) -- hapus baris lama
            // kalau sebelumnya paket ini PERNAH punya batas waktu lalu
            // diubah jadi unlimited, sisa baris lama tidak boleh nyangkut.
            Radreply::where('username', $username)->where('attribute', 'Session-Timeout')->delete();
        }

        // Bersihkan juga peninggalan radcheck Session-Timeout dari kode
        // lama (kalau voucher ini sempat ter-provisioning sebelum
        // perbaikan ini) -- supaya tidak ada dua baris berbeda tabel
        // untuk atribut yang sama.
        Radcheck::where('username', $username)->where('attribute', 'Session-Timeout')->delete();

        Radcheck::updateOrCreate(
            ['username' => $username, 'attribute' => 'Simultaneous-Use'],
            ['op' => ':=', 'value' => (string) $profile->shared_users, 'voucher_id' => $voucher->id]
        );

        // Mikrotik-Rate-Limit dikirim sebagai radreply, dibaca NAS mikrotik saat Access-Accept
        Radreply::updateOrCreate(
            ['username' => $username, 'attribute' => 'Mikrotik-Rate-Limit'],
            ['op' => '=', 'value' => $profile->effective_rate_limit, 'voucher_id' => $voucher->id]
        );

        Radusergroup::updateOrCreate(
            ['username' => $username],
            ['groupname' => $profile->mikrotik_profile, 'priority' => 1, 'voucher_id' => $voucher->id]
        );

        $this->syncExpiration($voucher);
    }

    /**
     * Kirim TANGGAL KADALUARSA voucher ke FreeRADIUS sebagai atribut
     * "Expiration" di radcheck.
     *
     * KENAPA PERLU: sebelum ini FreeRADIUS sama sekali TIDAK tahu soal
     * tanggal kadaluarsa — yang dikirim cuma password, batas sesi, jumlah
     * pemakai bersamaan, dan rate limit. Penegakan kadaluarsa 100%
     * bergantung tugas terjadwal `vouchers:mark-expired`, jadi kalau
     * cron-nya telat/mati, voucher yang sudah lewat tanggal MASIH BISA
     * dipakai login. Dengan atribut ini, FreeRADIUS sendiri yang menolak
     * begitu tanggalnya lewat — tanpa menunggu cron.
     *
     * Voucher yang tanggal kadaluarsanya KOSONG tidak diberi atribut ini
     * (dan kalau sebelumnya ada, dihapus) — jadi voucher tanpa tanggal
     * tetap berlaku selamanya seperti sebelumnya, tidak ikut terblokir.
     *
     * CATATAN: atribut ini dibaca modul `expiration` FreeRADIUS, yang di
     * konfigurasi bawaan FreeRADIUS 3 sudah aktif di bagian `authorize`.
     * Kalau di server modul itu dimatikan, penegakannya jatuh kembali ke
     * tugas terjadwal (yang juga sudah diperluas) — tetap terblokir, cuma
     * tidak seketika.
     */
    public function syncExpiration(Voucher $voucher): void
    {
        $username = RadiusUsername::forRadius($voucher->username);

        if (! $voucher->expired_at) {
            Radcheck::where('username', $username)
                ->where('attribute', 'Expiration')
                ->delete();

            return;
        }

        // Format yang dimengerti modul expiration FreeRADIUS, mis.
        // "31 Dec 2026 23:59:00". WAJIB nama bulan bahasa Inggris —
        // karena itu dipakai format() biasa (selalu Inggris), BUKAN
        // translatedFormat() yang ikut lokal Indonesia.
        Radcheck::updateOrCreate(
            ['username' => $username, 'attribute' => 'Expiration'],
            [
                'op' => ':=',
                'value' => $voucher->expired_at->format('d M Y H:i:s'),
                'voucher_id' => $voucher->id,
            ]
        );
    }

    public function deprovisionVoucher(Voucher $voucher): void
    {
        Radcheck::where('voucher_id', $voucher->id)->delete();
        Radreply::where('voucher_id', $voucher->id)->delete();
        Radusergroup::where('voucher_id', $voucher->id)->delete();
    }

    public function disableVoucher(Voucher $voucher): void
    {
        $username = RadiusUsername::forRadius($voucher->username);

        // Auth-Type := Reject membuat FreeRADIUS langsung menolak login tanpa menghapus data historis
        Radcheck::updateOrCreate(
            ['username' => $username, 'attribute' => 'Auth-Type'],
            ['op' => ':=', 'value' => 'Reject', 'voucher_id' => $voucher->id]
        );
    }

    /**
     * Blokir voucher KARENA KADALUARSA: sama seperti disableVoucher()
     * (Auth-Type := Reject), TAPI ditambah atribut Reply-Message di radreply.
     *
     * KENAPA: FreeRADIUS mempertahankan Reply-Message pada Access-Reject, dan
     * MikroTik menampilkannya di halaman login hotspot ($(error)). Tanpa ini,
     * pelanggan yang memakai voucher kadaluarsa hanya melihat "username atau
     * password salah" dan mengira vouchernya rusak. Dengan ini mereka tahu
     * persis: voucher sudah kadaluarsa, beli yang baru di outlet terdekat.
     */
    public function expireVoucher(Voucher $voucher): void
    {
        $this->disableVoucher($voucher);

        Radreply::updateOrCreate(
            ['username' => RadiusUsername::forRadius($voucher->username), 'attribute' => 'Reply-Message'],
            ['op' => '=', 'value' => static::pesanExpiredUntuk($voucher->loadMissing('profile')), 'voucher_id' => $voucher->id]
        );
    }

    public function enableVoucher(Voucher $voucher): void
    {
        $username = RadiusUsername::forRadius($voucher->username);

        Radcheck::where('username', $username)
            ->where('attribute', 'Auth-Type')
            ->delete();

        // Pesan "kadaluarsa" (dari expireVoucher) ikut dibersihkan -- kalau
        // tidak, ia akan ikut terkirim pada login yang BERHASIL.
        Radreply::where('username', $username)
            ->where('attribute', 'Reply-Message')
            ->delete();
    }

    /**
     * Kunci voucher ke MAC address tertentu (dipanggil otomatis saat terdeteksi
     * login pertama, kalau opsi "Auto-bind MAC" aktif). Setelah ini, FreeRADIUS
     * hanya akan menerima login dari MAC yang sama untuk username tsb.
     */
    public function bindMac(Voucher $voucher, string $macAddress): void
    {
        Radcheck::updateOrCreate(
            ['username' => RadiusUsername::forRadius($voucher->username), 'attribute' => 'Calling-Station-Id'],
            ['op' => ':=', 'value' => $macAddress, 'voucher_id' => $voucher->id]
        );
    }
}
