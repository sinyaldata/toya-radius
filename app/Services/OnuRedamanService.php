<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Susun pesan Telegram "redaman ONU/ONT" untuk satu pelanggan (perintah
 * /redaman di bot). Datanya dibaca LANGSUNG dari GenieACS saat itu juga --
 * tidak disalin ke database (lihat catatan di GenieAcsService).
 *
 * Semua teks dinamis di-escape karena bot memakai parse_mode HTML.
 */
class OnuRedamanService
{
    public function __construct(protected GenieAcsService $acs) {}

    /** Nilai aman untuk disisipkan ke pesan HTML Telegram. */
    protected function h(?string $teks): string
    {
        return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return array{pesan: string, bisa_refresh: bool}
     *         bisa_refresh = true kalau ONU-nya ada, jadi tombol "Minta data terbaru" berguna
     */
    public function untukPelanggan(Customer $c): array
    {
        $nama = $this->h($c->name).' ('.$this->h($c->username).')';

        if (! $this->acs->terkonfigurasi()) {
            return ['pesan' => "📶 <b>Redaman ONU</b>\nPelanggan: {$nama}\n\nGenieACS belum dikonfigurasi di aplikasi (Pengaturan → GenieACS).", 'bisa_refresh' => false];
        }

        if (! $c->acs_device_id) {
            return ['pesan' => "📶 <b>Redaman ONU</b>\nPelanggan: {$nama}\n\nPelanggan ini belum ditautkan ke ONU. Tautkan dulu lewat menu <b>GenieACS</b> di aplikasi (kolom Pelanggan).", 'bisa_refresh' => false];
        }

        $res = $this->acs->devicePerId($c->acs_device_id);

        if (! $res['ok']) {
            return ['pesan' => "📶 <b>Redaman ONU</b>\nPelanggan: {$nama}\n\n❌ ".$this->h($res['pesan']), 'bisa_refresh' => false];
        }

        if (! $res['data']) {
            return ['pesan' => "📶 <b>Redaman ONU</b>\nPelanggan: {$nama}\n\nPerangkat <code>".$this->h($c->acs_device_id)."</code> tidak ditemukan di GenieACS -- mungkin sudah dihapus atau ID-nya berubah (ONU diganti/direset). Tautkan ulang lewat menu GenieACS.", 'bisa_refresh' => false];
        }

        $d = $this->acs->ringkasSatu($res['data']);

        $rx = is_numeric($d['rx_power'] ?? null) ? (float) $d['rx_power'] : null;
        $nilai = $this->acs->klasifikasiRx($rx);

        $baris = [];
        $baris[] = '📶 <b>Redaman ONU</b>';
        $baris[] = "Pelanggan: {$nama}";
        $baris[] = 'Perangkat: '.$this->h(trim(($d['merek'] ?? '').' '.($d['model'] ?? ''))).' <code>'.$this->h($d['serial'] ?? '-').'</code>';
        $baris[] = '';
        $baris[] = $rx !== null
            ? 'Redaman RX: <b>'.number_format($rx, 2, ',', '.').' dBm</b> '.$nilai['ikon'].' '.$nilai['label']
            : 'Redaman RX: ⚪ belum terbaca -- tekan ♻️ Minta data terbaru';
        $baris[] = 'IP: '.$this->h($d['ip'] ?? '-');
        $baris[] = 'User PPPoE: '.$this->h($d['pppoe_user'] ?? '-');

        $baris[] = 'Terakhir online: '.$this->waktu($d['terakhir'] ?? null, $stale);

        if ($stale) {
            $baris[] = '';
            $baris[] = '⚠️ ONU sudah lama tidak menghubungi ACS -- angka di atas bisa sudah basi. Cek apakah ONU menyala/terhubung.';
        }

        return ['pesan' => implode("\n", $baris), 'bisa_refresh' => true];
    }

    /**
     * "2 menit lalu (21-09-2026 17:09)". $stale = true kalau lebih dari 6 jam.
     */
    protected function waktu(?string $iso, ?bool &$stale = null): string
    {
        $stale = false;

        if (! $iso) {
            return '-';
        }

        try {
            $t = Carbon::parse($iso)->timezone((string) (Setting::get('timezone') ?: 'Asia/Jakarta'));
        } catch (\Throwable) {
            return $this->h($iso);
        }

        $stale = $t->lt(now()->subHours(6));

        return $this->h($t->copy()->locale('id')->diffForHumans().' ('.$t->format('d-m-Y H:i').')');
    }
}
