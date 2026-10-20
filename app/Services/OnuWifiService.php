<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Wi-Fi ONU (SSID & kata sandi): tampilkan dan ubah, dipakai halaman GenieACS
 * (aplikasi) dan perintah /wifi (bot Telegram). Datanya dibaca LANGSUNG dari
 * GenieACS; perubahan dikirim sebagai task setParameterValues.
 *
 * KEAMANAN:
 *  - Jalur parameter yang ditulis SELALU ditentukan ulang di server dari
 *    data segar GenieACS (dicocokkan lewat $dasar yang sah), BUKAN diterima
 *    mentah dari tombol/form -- jadi tidak ada cara menyuruh aplikasi
 *    menulis parameter sembarang.
 *  - Kata sandi TIDAK PERNAH masuk ke log aktivitas.
 */
class OnuWifiService
{
    public function __construct(protected GenieAcsService $acs) {}

    protected function h(?string $teks): string
    {
        return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Data segar perangkat + daftar Wi-Fi-nya.
     *
     * @return array{ok:bool, pesan:?string, device:?array, wlan:array, klien_waktu:?string}
     */
    public function muat(string $deviceId): array
    {
        if (! $this->acs->terkonfigurasi()) {
            return ['ok' => false, 'pesan' => 'GenieACS belum dikonfigurasi (Pengaturan → GenieACS).', 'device' => null, 'wlan' => [], 'klien_waktu' => null];
        }

        $res = $this->acs->devicePerId($deviceId);

        if (! $res['ok']) {
            return ['ok' => false, 'pesan' => (string) $res['pesan'], 'device' => null, 'wlan' => [], 'klien_waktu' => null];
        }

        if (! $res['data']) {
            return ['ok' => false, 'pesan' => 'Perangkat tidak ditemukan di GenieACS (mungkin sudah dihapus atau ID-nya berubah).', 'device' => null, 'wlan' => [], 'klien_waktu' => null];
        }

        return [
            'ok' => true,
            'pesan' => null,
            'device' => $res['data'],
            'wlan' => $this->acs->daftarWifi($res['data']),
            'klien_waktu' => $this->acs->waktuKlien($res['data']),
        ];
    }

    /**
     * Ubah SSID dan/atau kata sandi satu antarmuka Wi-Fi.
     *
     * @param  string  $dasar  jalur dasar antarmuka (mis. ...WLANConfiguration.1) -- HARUS ada di data segar
     * @param  string  $oleh   keterangan pelaku untuk log aktivitas (mis. "Bot Telegram chat 123")
     * @return array{ok:bool, pesan:?string, langsung:bool}
     */
    public function terapkan(string $deviceId, string $dasar, ?string $ssidBaru, ?string $sandiBaru, string $oleh, ?int $userId = null, ?Customer $pelanggan = null): array
    {
        if ($ssidBaru === null && $sandiBaru === null) {
            return ['ok' => false, 'pesan' => 'Tidak ada yang diubah.', 'langsung' => false];
        }

        if ($ssidBaru !== null && ($e = $this->acs->validasiSsid($ssidBaru))) {
            return ['ok' => false, 'pesan' => $e, 'langsung' => false];
        }

        if ($sandiBaru !== null && ($e = $this->acs->validasiKataSandi($sandiBaru))) {
            return ['ok' => false, 'pesan' => $e, 'langsung' => false];
        }

        $data = $this->muat($deviceId);

        if (! $data['ok']) {
            return ['ok' => false, 'pesan' => $data['pesan'], 'langsung' => false];
        }

        $wlan = collect($data['wlan'])->firstWhere('dasar', $dasar);

        if (! $wlan) {
            return ['ok' => false, 'pesan' => 'Antarmuka Wi-Fi itu tidak ada di ONU ini (data mungkin sudah berubah). Buka ulang dan coba lagi.', 'langsung' => false];
        }

        $pasangan = [];

        if ($ssidBaru !== null && $ssidBaru !== $wlan['ssid']) {
            $pasangan[] = [$wlan['jalur_ssid'], $ssidBaru];
        }

        if ($sandiBaru !== null) {
            if (! $wlan['jalur_password']) {
                return ['ok' => false, 'pesan' => 'Jalur kata sandi Wi-Fi ONU ini belum dikenal -- muat data Wi-Fi dari ONU dulu.', 'langsung' => false];
            }

            $pasangan[] = [$wlan['jalur_password'], $sandiBaru];
        }

        if (! $pasangan) {
            return ['ok' => false, 'pesan' => 'SSID yang dimasukkan sama dengan yang sekarang -- tidak ada yang diubah.', 'langsung' => false];
        }

        $hasil = $this->acs->ubahParameter($deviceId, $pasangan);

        if ($hasil['ok']) {
            // Kata sandi SENGAJA tidak dicatat.
            ActivityLog::create([
                'user_id' => $userId,
                'log_name' => 'wifi',
                'description' => 'Ubah Wi-Fi ONU'.($pelanggan ? ' pelanggan '.$pelanggan->name : '').' ('.($ssidBaru !== null && $sandiBaru !== null ? 'SSID & kata sandi' : ($ssidBaru !== null ? 'SSID' : 'kata sandi')).') oleh '.$oleh,
                'subject_type' => $pelanggan ? Customer::class : null,
                'subject_id' => $pelanggan?->id,
                'properties' => array_filter([
                    'perangkat' => $deviceId,
                    'antarmuka' => $dasar,
                    'ssid_lama' => $ssidBaru !== null ? $wlan['ssid'] : null,
                    'ssid_baru' => $ssidBaru,
                    'kata_sandi_diubah' => $sandiBaru !== null ? true : null,
                    'dijalankan_langsung' => $hasil['langsung'],
                ], fn ($v) => $v !== null),
            ]);
        }

        return $hasil;
    }

    /** "3 menit yang lalu (21-09-2026 19:29)" dari waktu ISO GenieACS, sesuai zona waktu pengaturan. */
    public function waktuIso(?string $iso): ?string
    {
        if (! $iso) {
            return null;
        }

        try {
            $t = Carbon::parse($iso)->timezone((string) (Setting::get('timezone') ?: 'Asia/Jakarta'));
        } catch (\Throwable) {
            return null;
        }

        return $this->h($t->copy()->locale('id')->diffForHumans().' ('.$t->format('d-m-Y H:i').')');
    }

    /**
     * Pesan Telegram + tombol untuk melihat Wi-Fi seorang pelanggan.
     *
     * @return array{pesan:string, tombol:array, ada_wifi:bool}
     */
    public function pesanTampil(Customer $c): array
    {
        $nama = $this->h($c->name).' ('.$this->h($c->username).')';
        $judul = "📡 <b>Wi-Fi ONU</b>\nPelanggan: {$nama}";

        if (! $c->acs_device_id) {
            return ['pesan' => "{$judul}\n\nPelanggan ini belum ditautkan ke ONU. Tautkan dulu lewat menu <b>GenieACS</b> di aplikasi (kolom Pelanggan).", 'tombol' => [], 'ada_wifi' => false];
        }

        $data = $this->muat($c->acs_device_id);

        if (! $data['ok']) {
            return ['pesan' => "{$judul}\n\n❌ ".$this->h($data['pesan']), 'tombol' => [[['text' => '🔄 Cek lagi', 'callback_data' => "wfv:{$c->id}"]]], 'ada_wifi' => false];
        }

        $ringkas = $this->acs->ringkasSatu($data['device']);
        $baris = [$judul, 'Perangkat: '.$this->h(trim(($ringkas['merek'] ?? '').' '.($ringkas['model'] ?? ''))).' <code>'.$this->h($ringkas['serial'] ?? '-').'</code>', ''];

        $tombol = [];
        $tampil = array_values(array_filter($data['wlan'], fn ($w) => $w['aktif'] !== false));

        if (! $tampil) {
            $baris[] = $data['wlan']
                ? 'Semua Wi-Fi di ONU ini sedang nonaktif.'
                : 'GenieACS belum punya data Wi-Fi ONU ini. Tekan ♻️ Muat data Wi-Fi, tunggu ONU menghubungi ACS (bisa 1-2 menit, atau lebih kalau ONU jarang inform), lalu 🔄 Cek lagi.';
        }

        $sisaKlien = 25; // batas total baris client per pesan (batas panjang pesan Telegram 4096 karakter)
        $totalKlien = 0;

        foreach ($tampil as $w) {
            $band = $w['band'] ? $w['band'].' -- ' : '';
            $baris[] = "<b>{$band}WLAN {$w['indeks']}</b>";
            $baris[] = 'SSID: <code>'.$this->h($w['ssid']).'</code>';
            $baris[] = $w['password'] !== null
                ? 'Password: <tg-spoiler>'.$this->h($w['password']).'</tg-spoiler>'
                : 'Password: 🔒 tidak terbaca (disembunyikan ONU) -- tetap bisa diganti';

            $klien = $w['klien'] ?? [];
            $totalKlien += count($klien);
            $baris[] = '👥 Client terhubung: '.count($klien);

            foreach ($klien as $k) {
                if ($sisaKlien <= 0) {
                    break;
                }

                $sisaKlien--;
                $baris[] = '• '.$this->h($k['nama'] ?? 'tanpa nama')
                    .' — '.$this->h($k['ip'] ?? '-')
                    .' — <code>'.$this->h($k['mac']).'</code>'
                    .($k['sinyal'] ? ' — '.$this->h($k['sinyal']) : '');
            }

            $baris[] = '';

            $tombol[] = [
                ['text' => "✏️ SSID {$w['indeks']}", 'callback_data' => "wfs:{$c->id}:{$w['indeks']}"],
                ['text' => "🔑 Password {$w['indeks']}", 'callback_data' => "wfp:{$c->id}:{$w['indeks']}"],
            ];
        }

        if ($tampil) {
            $waktuKlien = $this->waktuIso($data['klien_waktu']);

            $baris[] = $waktuKlien
                ? "ℹ️ Daftar client diambil dari ONU {$waktuKlien} -- tidak real-time. Tekan ♻️ Muat data Wi-Fi untuk memperbarui."
                : 'ℹ️ Data client belum pernah diambil dari ONU. Tekan ♻️ Muat data Wi-Fi, tunggu ONU inform, lalu 🔄 Cek lagi.';

            if ($totalKlien > 25) {
                $baris[] = '(Hanya 25 client pertama yang ditampilkan di sini -- lihat daftar lengkapnya di aplikasi.)';
            }
        }

        $tombol[] = [
            ['text' => '🔄 Cek lagi', 'callback_data' => "wfv:{$c->id}"],
            ['text' => '♻️ Muat data Wi-Fi', 'callback_data' => "wfr:{$c->id}"],
        ];

        return ['pesan' => rtrim(implode("\n", $baris)), 'tombol' => $tombol, 'ada_wifi' => (bool) $tampil];
    }
}
