<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\GenieAcsService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * DIAGNOSA (hanya membaca): kenapa kolom IP / Redaman (RX) / User PPPoE sebuah
 * ONU kosong di halaman GenieACS.
 *
 * Kolom itu kosong karena salah satu dari dua hal, dan perintah ini
 * menunjukkan yang mana:
 *   1. GenieACS BELUM PUNYA datanya -- GenieACS hanya tahu parameter yang
 *      sudah pernah diambil dari ONU (Refresh / preset). Ciri: jumlah
 *      parameter yang diketahui sedikit, atau ada tugas yang menumpuk/fault.
 *   2. Datanya ADA tetapi di jalur yang tidak dikenali aplikasi. Ciri:
 *      jalur RXPower/Username/ExternalIPAddress muncul di daftar di bawah
 *      tetapi kolom tabel tetap kosong.
 *
 * Contoh:  php artisan genieacs:probe EC6CB5-F663NV9-ZTEGCCCCD100
 *          php artisan genieacs:probe ZTEGCCCCD100     (sebagian serial pun boleh)
 */
class GenieAcsProbe extends Command
{
    use RunsPerTenant;

    protected $signature = 'genieacs:probe {cari : ID perangkat atau (sebagian) serial number}';

    protected $description = 'Diagnosa (baca saja): kenapa IP/Redaman/User PPPoE sebuah ONU kosong di halaman GenieACS. Jalan untuk instalasi utama DAN setiap tenant yang mengisi URL GenieACS.';

    public function handle(GenieAcsService $svc): int
    {
        $cari = (string) $this->argument('cari');
        $ditemukan = 0;

        $this->runForEachTenant(function ($tenant) use ($svc, $cari, &$ditemukan) {
            $ditemukan += $this->periksaTenantIni($svc, $tenant?->subdomain ?? 'utama', $cari);

            return 1;
        });

        if ($ditemukan === 0) {
            $this->warn("Perangkat \"{$cari}\" tidak ditemukan di GenieACS tenant mana pun (atau URL GenieACS belum diisi).");
        }

        return self::SUCCESS;
    }

    public function periksaTenantIni(GenieAcsService $svc, string $label, string $cari): int
    {
        if (! $svc->terkonfigurasi()) {
            return 0;
        }

        $res = $svc->deviceMentah($cari);

        if (! $res['ok']) {
            $this->error("[{$label}] {$res['pesan']}");

            return 0;
        }

        foreach ($res['data'] as $device) {
            $this->laporkan($svc, $label, $device);
        }

        return count($res['data']);
    }

    protected function laporkan(GenieAcsService $svc, string $label, array $device): void
    {
        $id = (string) ($device['_id'] ?? '-');
        $rata = $svc->ratakan($device);

        $this->newLine();
        $this->line("<options=bold>[{$label}] {$id}</>");
        $this->line('  Terakhir inform  : '.($device['_lastInform'] ?? '-'));
        $this->line('  Parameter dikenal: '.count($rata).' (yang sudah diambil GenieACS dari ONU)');

        $ringkas = $svc->ringkasSatu($device);
        $this->line('  Yang dibaca aplikasi -> IP: '.($ringkas['ip'] ?? '(kosong)')
            .' | RX: '.($ringkas['rx_power'] ?? '(kosong)')
            .' | User PPPoE: '.($ringkas['pppoe_user'] ?? '(kosong)'));

        $sumber = $svc->sumberRx($device);
        $this->line('  RX dibaca dari    : '.($sumber['jalur']
            ? "{$sumber['jalur']} ({$sumber['jenis']}) nilai mentah {$sumber['mentah']} -> ".($sumber['hasil'] ?? '-').' dBm'
            : '(tidak ada sumber RX)'));

        $wlan = $svc->daftarWifi($device);
        $this->line('  Wi-Fi terbaca     : '.($wlan ? '' : '(tidak ada antarmuka Wi-Fi di data GenieACS -- tekan Muat data Wi-Fi)'));

        foreach ($wlan as $w) {
            $this->line(sprintf(
                '    WLAN %d (%s)%s: SSID "%s", kata sandi %s, %d client',
                $w['indeks'],
                $w['band'] ?? '?',
                $w['aktif'] === false ? ' [nonaktif]' : '',
                $w['ssid'],
                $w['password'] !== null ? 'terbaca' : ($w['password_ditebak'] ? 'jalur belum dikenal' : 'disembunyikan/kosong'),
                count($w['klien'])
            ));
        }

        // Contoh jalur data client (untuk memastikan jalur tiap merek terbaca).
        $polaKlien = '/(AssociatedDevice\.\d+\.(AssociatedDeviceMACAddress|MACAddress)|Hosts\.Host\.\d+\.(MACAddress|PhysAddress|HostName|InterfaceType|Layer[12]Interface|Active))$/i';
        $contohKlien = [];

        foreach ($rata as $jalur => $nilai) {
            if (preg_match($polaKlien, $jalur)) {
                $contohKlien[] = $jalur.' = '.Str::limit($nilai, 40);

                if (count($contohKlien) >= 12) {
                    break;
                }
            }
        }

        $this->line('  Contoh parameter client Wi-Fi/Hosts: '.($contohKlien ? '' : '(belum ada -- tekan Muat data Wi-Fi)'));

        foreach ($contohKlien as $baris) {
            $this->line('    '.$baris);
        }

        $this->line('  Parameter terkait yang ADA di GenieACS:');
        $pola = '/(RXPower|TXPower|OpticalSignalLevel|WANPPPConnection\.\d+\.Username|ExternalIPAddress|ConnectionStatus|ManagementServer\.(ConnectionRequestURL|PeriodicInformInterval|PeriodicInformEnable|URL)|WLANConfiguration\.\d+\.(SSID|Enable|Standard)|KeyPassphrase|Device\.WiFi\.SSID\.\d+\.SSID)$/i';
        $ada = 0;

        foreach ($rata as $jalur => $nilai) {
            if (preg_match($pola, $jalur) || str_starts_with($jalur, 'VirtualParameters.')) {
                // Kata sandi Wi-Fi TIDAK ditampilkan -- cukup diketahui ada/tidak.
                $tampilNilai = preg_match('/Passphrase$/i', $jalur)
                    ? ($nilai === '' ? '(kosong/disembunyikan ONU)' : '(terisi, '.strlen($nilai).' karakter)')
                    : Str::limit($nilai, 60);

                $this->line('    '.$jalur.' = '.$tampilNilai);
                $ada++;

                if ($ada >= 40) {
                    $this->line('    ... (dipotong 40 baris)');
                    break;
                }
            }
        }

        if ($ada === 0) {
            $this->line('    (tidak ada satu pun)');
        }

        $antrian = $svc->antrianDanGalat($id);
        $this->line('  Tugas antri di GenieACS: '.count($antrian['tugas']));

        foreach (array_slice($antrian['tugas'], 0, 5) as $t) {
            $this->line('    - '.($t['name'] ?? '?').' | dibuat '.($t['timestamp'] ?? '?').' | percobaan '.($t['retries'] ?? 0));
        }

        $this->line('  Catatan gagal (fault): '.count($antrian['fault']));

        foreach (array_slice($antrian['fault'], 0, 5) as $f) {
            $this->line('    - '.($f['code'] ?? '?').': '.Str::limit((string) ($f['message'] ?? ''), 100).' | '.($f['timestamp'] ?? ''));
        }

        $this->line('  <options=bold>Kesimpulan:</>');
        $this->line('    '.static::kesimpulan(count($rata), $ada, $ringkas, count($antrian['tugas']), count($antrian['fault']), $device['_lastInform'] ?? null));
    }

    /**
     * Logika kesimpulan murni (mudah dites).
     *
     * @param  array{ip:?string,rx_power:?string,pppoe_user:?string}  $ringkas
     */
    public static function kesimpulan(int $jumlahParameter, int $parameterTerkait, array $ringkas, int $tugasAntri, int $fault, ?string $terakhirInform = null): string
    {
        $kosong = array_filter([
            'IP' => empty($ringkas['ip']),
            'RX' => empty($ringkas['rx_power']),
            'User PPPoE' => empty($ringkas['pppoe_user']),
        ]);

        if (! $kosong) {
            return 'Semua kolom terbaca -- tidak ada masalah.';
        }

        if ($fault > 0) {
            return 'Ada catatan gagal (fault) di GenieACS -- ONU menolak/gagal menjalankan tugas. Lihat kode dan pesannya di atas.';
        }

        if ($tugasAntri > 0) {
            return "Ada {$tugasAntri} tugas antri yang BELUM dijalankan. GenieACS baru bisa menjalankannya saat ONU menghubungi ACS (inform), dan terakhir kali itu terjadi ".($terakhirInform ?? 'tidak diketahui').'. '
                .'Kalau ONU jarang inform (lihat PeriodicInformInterval di atas) dan permintaan langsung GenieACS ke ONU (ConnectionRequestURL) tidak sampai, tugas akan menunggu lama. '
                .'Cara cepat: cabut-colok ONU (ONU inform saat menyala) lalu jalankan perintah ini lagi. Jangan menekan Refresh berkali-kali -- tiap tekan menambah antrian.';
        }

        if ($jumlahParameter < 60) {
            return "GenieACS baru mengenal {$jumlahParameter} parameter -- pohon parameter ONU ini belum diambil. Tekan Refresh, tunggu ONU inform, lalu jalankan perintah ini lagi.";
        }

        if ($parameterTerkait === 0) {
            return 'Pohon parameter sudah diambil, tetapi tidak ada RXPower/Username/ExternalIPAddress sama sekali: ONU/firmware ini tidak menyediakannya di TR-069, atau perlu Refresh subpohon tertentu (mis. InternetGatewayDevice.WANDevice).';
        }

        return 'Parameter-nya ADA di GenieACS tetapi kolom "'.implode('", "', array_keys($kosong)).'" tetap kosong: nilainya kosong/tidak valid di ONU (mis. PPPoE belum terhubung, RX belum terbaca), atau jalurnya belum dikenali -- kirim daftar "Parameter terkait" di atas ke pengembang.';
    }
}
