<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pembaca data perangkat ONU dari GenieACS lewat NBI (Northbound
 * Interface) — REST API bawaan GenieACS, biasanya di port 7557.
 *
 * Data perangkat SENGAJA TIDAK disalin ke database aplikasi ini. Yang
 * disimpan cuma "ONU mana milik pelanggan mana" (kolom acs_device_id di
 * tabel customers). Alasannya: nilai seperti RX power, IP, dan waktu
 * terakhir online berubah terus-menerus — salinan lokal akan selalu
 * basi, dan basi di sini berarti teknisi mengambil keputusan dari angka
 * yang salah.
 *
 * Semua pemanggilan di sini mengembalikan hasil "aman": kalau GenieACS
 * tidak bisa dihubungi, yang keluar array kosong / pesan galat, BUKAN
 * exception yang menjatuhkan halaman.
 */
class GenieAcsService
{
    public function baseUrl(): ?string
    {
        $url = trim((string) (Setting::get('genieacs_url') ?? ''));

        return $url !== '' ? rtrim($url, '/') : null;
    }

    public function terkonfigurasi(): bool
    {
        return $this->baseUrl() !== null;
    }

    protected function request()
    {
        $req = Http::timeout(12)->acceptJson();

        $user = trim((string) (Setting::get('genieacs_username') ?? ''));
        $pass = (string) (Setting::get('genieacs_password') ?? '');

        // GenieACS boleh dipasang tanpa autentikasi di jaringan lokal.
        // Kalau username kosong, jangan kirim header Authorization sama
        // sekali — mengirim basic auth kosong justru ditolak sebagian
        // pemasangan.
        if ($user !== '') {
            $req = $req->withBasicAuth($user, $pass);
        }

        return $req;
    }

    /**
     * Ambil daftar perangkat. $cari menyaring di sisi GenieACS lewat
     * query JSON supaya yang dikirim lewat jaringan hanya yang perlu.
     *
     * @return array{ok: bool, pesan: ?string, data: array}
     */
    public function devices(?string $cari = null, int $limit = 100): array
    {
        $base = $this->baseUrl();

        if (! $base) {
            return ['ok' => false, 'pesan' => 'URL GenieACS belum diisi di Pengaturan.', 'data' => []];
        }

        $params = ['limit' => $limit];

        if ($cari !== null && trim($cari) !== '') {
            $kata = trim($cari);
            // Cari di serial number ATAU di ID perangkat. Regex dipakai
            // supaya cocok sebagian (bukan harus sama persis).
            $params['query'] = json_encode([
                '$or' => [
                    ['_id' => ['$regex' => $kata]],
                    ['_deviceId._SerialNumber' => ['$regex' => $kata]],
                ],
            ]);
        }

        try {
            $res = $this->request()->get($base.'/devices', $params);
        } catch (\Throwable $e) {
            Log::warning('GenieACS tidak bisa dihubungi: '.$e->getMessage());

            return ['ok' => false, 'pesan' => 'Tidak bisa menghubungi GenieACS: '.$e->getMessage(), 'data' => []];
        }

        if (! $res->successful()) {
            Log::warning('GenieACS menolak GET /devices', ['kode' => $res->status(), 'alamat' => $this->alamatAman($base), 'isi' => Str::limit(trim(strip_tags($res->body())), 200)]);

            return ['ok' => false, 'pesan' => $this->pesanGalat($res, 'GET', '/devices', $base), 'data' => []];
        }

        return ['ok' => true, 'pesan' => null, 'data' => $this->ringkas($res->json() ?: [])];
    }

    /**
     * Ubah bentuk data GenieACS yang bersarang dalam menjadi baris datar
     * yang enak ditampilkan di tabel.
     */
    protected function ringkas(array $devices): array
    {
        return array_map(function ($d) {
            $info = $d['_deviceId'] ?? [];
            $rata = $this->ratakan($d);

            return [
                'id' => $d['_id'] ?? '-',
                'serial' => $info['_SerialNumber'] ?? '-',
                'merek' => $info['_Manufacturer'] ?? '-',
                'model' => $info['_ProductClass'] ?? '-',
                'terakhir' => $d['_lastInform'] ?? null,
                // RX & User PPPoE: jalur tetap dicoba dulu (cepat); kalau
                // kosong, baru dicari ke seluruh pohon parameter -- indeks
                // WAN dan nama parameter khusus vendor berbeda-beda antar
                // merek/model ONU.
                //
                // IP: dicari ke seluruh pohon DULU (PPPoE lebih diutamakan),
                // karena WANConnectionDevice.1 di banyak ONU (mis. ZTE) adalah
                // koneksi MANAJEMEN TR-069 dengan IP privat, bukan IP internet
                // pelanggan -- jalur tetap lama akan menampilkan IP yang salah.
                'ip' => $this->cariIp($rata) ?? $this->nilai($d, [
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
                    'Device.IP.Interface.1.IPv4Address.1.IPAddress',
                ]),
                'rx_power' => $this->normalisasiRx($this->bacaRx($d, $rata)['mentah']),
                'pppoe_user' => $this->nilai($d, [
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
                    'Device.PPP.Interface.1.Username',
                ]) ?: $this->cariPppoeUser($rata),
            ];
        }, $devices);
    }

    /**
     * Ratakan dokumen perangkat GenieACS menjadi "jalur => nilai" -- hanya
     * parameter (simpul yang punya _value). Kunci berawalan "_" adalah
     * metadata GenieACS, bukan bagian pohon parameter.
     *
     * @return array<string,string>
     */
    public function ratakan(array $node, string $awalan = ''): array
    {
        $hasil = [];

        foreach ($node as $kunci => $isi) {
            if (! is_array($isi) || (is_string($kunci) && str_starts_with($kunci, '_'))) {
                continue;
            }

            $jalur = $awalan === '' ? (string) $kunci : $awalan.'.'.$kunci;

            if (array_key_exists('_value', $isi) && ! is_array($isi['_value'])) {
                $hasil[$jalur] = trim((string) $isi['_value']);
            }

            $hasil += $this->ratakan($isi, $jalur);
        }

        return $hasil;
    }

    /** IPv4 yang layak ditampilkan sebagai IP WAN (bukan kosong/0.0.0.0/loopback). */
    protected function ipWanValid(string $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && $v !== '0.0.0.0'
            && ! str_starts_with($v, '127.');
    }

    /**
     * IP WAN: koneksi PPPoE dulu (itulah IP internet pelanggan), lalu koneksi
     * IP biasa, lalu TR-181. Indeks WANConnectionDevice/WANPPPConnection
     * dicari otomatis -- di banyak ONU koneksi manajemen TR-069 memakai
     * indeks 1 dan koneksi internet PPPoE ada di indeks lain.
     */
    protected function cariIp(array $rata): ?string
    {
        $kelompok = [
            '/WANPPPConnection\.\d+\.ExternalIPAddress$/i',
            '/WANIPConnection\.\d+\.ExternalIPAddress$/i',
            '/^Device\.IP\.Interface\.\d+\.IPv4Address\.\d+\.IPAddress$/i',
        ];

        foreach ($kelompok as $pola) {
            foreach ($rata as $jalur => $nilai) {
                if (preg_match($pola, $jalur) && $this->ipWanValid($nilai)) {
                    return $nilai;
                }
            }
        }

        return null;
    }

    protected function cariPppoeUser(array $rata): ?string
    {
        foreach (['/WANPPPConnection\.\d+\.Username$/i', '/^Device\.PPP\.Interface\.\d+\.Username$/i'] as $pola) {
            foreach ($rata as $jalur => $nilai) {
                if (preg_match($pola, $jalur) && $nilai !== '') {
                    return $nilai;
                }
            }
        }

        return null;
    }

    /** Jalur RX tetap yang sudah dikenal (dicoba setelah virtual parameter). */
    protected const JALUR_RX_TETAP = [
        'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower',
        'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
        'Device.Optical.Interface.1.CurrentStatus.RXPower',
    ];

    /**
     * Baca redaman RX beserta ASAL-nya. Urutan prioritas:
     *
     *  1. VIRTUAL PARAMETER GenieACS yang namanya memuat "RX" (mis.
     *     VirtualParameters.RXPower). Kolom "RX" di antarmuka GenieACS milik
     *     admin biasanya dibuat dari virtual parameter -- skrip di sana sudah
     *     memilih parameter yang benar per merek dan mengonversi satuannya --
     *     jadi itulah angka yang dipercaya teknisi, dan angka aplikasi harus
     *     sama dengannya.
     *  2. Jalur tetap yang dikenal (Huawei, China Telecom, TR-181).
     *  3. Pencarian ke seluruh pohon (parameter berakhiran RXPower dst.).
     *
     * @return array{jalur: ?string, mentah: ?string, jenis: ?string}
     */
    public function bacaRx(array $device, array $rata): array
    {
        foreach ($rata as $jalur => $nilai) {
            if (str_starts_with($jalur, 'VirtualParameters.')
                && preg_match('/rx/i', substr($jalur, 18))
                && ! preg_match('/tx/i', substr($jalur, 18))
                && is_numeric($nilai)) {
                return ['jalur' => $jalur, 'mentah' => $nilai, 'jenis' => 'virtual parameter'];
            }
        }

        foreach (self::JALUR_RX_TETAP as $jalur) {
            if (array_key_exists($jalur, $rata) && is_numeric($rata[$jalur])) {
                return ['jalur' => $jalur, 'mentah' => $rata[$jalur], 'jenis' => 'jalur tetap'];
            }
        }

        $dinamis = $this->cariRx($rata);

        return $dinamis
            ? ['jalur' => $dinamis[0], 'mentah' => $dinamis[1], 'jenis' => 'pencarian pohon']
            : ['jalur' => null, 'mentah' => null, 'jenis' => null];
    }

    /**
     * Redaman optik: parameter berakhiran RXPower (nama induknya khusus
     * vendor: X_GponInterafceConfig milik Huawei, X_..._WANPONInterfaceConfig
     * milik ZTE, dst.) atau OpticalSignalLevel (TR-181).
     */
    protected function cariRx(array $rata): ?array
    {
        $calon = [];

        foreach ($rata as $jalur => $nilai) {
            if (! is_numeric($nilai) || ! preg_match('/(RXPower|OpticalSignalLevel)$/i', $jalur)) {
                continue;
            }

            // Utamakan yang jalurnya jelas-jelas antarmuka optik/PON.
            $skor = preg_match('/PON|Gpon|Optical|WANDevice/i', $jalur) ? 0 : 1;
            $calon[] = [$skor, $jalur, $nilai];
        }

        usort($calon, fn ($a, $b) => $a[0] <=> $b[0]);

        return isset($calon[0]) ? [$calon[0][1], $calon[0][2]] : null;
    }

    /**
     * Ubah nilai mentah RX ke dBm. Satuannya berbeda antar merek (ada yang
     * dBm langsung, ada yang 0,01 / 0,001 / 0,0001 dBm), jadi dipilih
     * skala pertama yang menghasilkan angka masuk akal (-50 s/d +10 dBm).
     * Nilai persis 0 dianggap "belum ada bacaan" (bukan 0 dBm).
     * Ini TEBAKAN berdasarkan rentang -- kalau angka di kolom tampak aneh
     * untuk merek tertentu, jalankan: php artisan genieacs:probe ID
     */
    protected function normalisasiRx(?string $nilai): ?string
    {
        if ($nilai === null || ! is_numeric($nilai)) {
            return $nilai;
        }

        $n = (float) $nilai;

        if ($n == 0.0) {
            return null;
        }

        foreach ([1, 0.1, 0.01, 0.001, 0.0001] as $skala) {
            $c = $n * $skala;

            if ($c >= -50 && $c <= 10) {
                return (string) round($c, 2);
            }
        }

        return (string) $n;
    }

    /** Satu perangkat mentah (dokumen lengkap) -- dipakai genieacs:probe. */
    public function deviceMentah(string $cari): array
    {
        $base = $this->baseUrl();

        if (! $base) {
            return ['ok' => false, 'pesan' => 'URL GenieACS belum diisi di Pengaturan.', 'data' => []];
        }

        try {
            $res = $this->request()->get($base.'/devices', [
                'query' => json_encode(['$or' => [
                    ['_id' => ['$regex' => $cari]],
                    ['_deviceId._SerialNumber' => ['$regex' => $cari]],
                ]]),
                'limit' => 3,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'pesan' => 'Tidak bisa menghubungi GenieACS: '.$e->getMessage(), 'data' => []];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'pesan' => $this->pesanGalat($res, 'GET', '/devices', $base), 'data' => []];
        }

        return ['ok' => true, 'pesan' => null, 'data' => $res->json() ?: []];
    }

    /**
     * Tugas yang masih ANTRI untuk perangkat ini + catatan kegagalan (fault)
     * dari GenieACS. Refresh/Reboot yang "tidak berefek" biasanya menumpuk di
     * sini karena ONU tidak menjawab connection request.
     *
     * @return array{tugas: array, fault: array}
     */
    public function antrianDanGalat(string $deviceId): array
    {
        $base = $this->baseUrl();
        $hasil = ['tugas' => [], 'fault' => []];

        if (! $base) {
            return $hasil;
        }

        foreach (['tugas' => '/tasks', 'fault' => '/faults'] as $kunci => $jalur) {
            try {
                $res = $this->request()->get($base.$jalur, ['query' => json_encode(['device' => $deviceId])]);
                $hasil[$kunci] = $res->successful() ? (array) ($res->json() ?: []) : [];
            } catch (\Throwable) {
                $hasil[$kunci] = [];
            }
        }

        return $hasil;
    }

    /**
     * Satu perangkat berdasarkan ID PERSIS (kolom customers.acs_device_id).
     * Sengaja bukan pencarian regex seperti devices(): ID perangkat memuat
     * tanda hubung dan kadang karakter khusus regex.
     *
     * @return array{ok: bool, pesan: ?string, data: ?array}  data null = tidak ada perangkat ber-ID itu
     */
    public function devicePerId(string $id): array
    {
        $base = $this->baseUrl();

        if (! $base) {
            return ['ok' => false, 'pesan' => 'URL GenieACS belum diisi di Pengaturan.', 'data' => null];
        }

        try {
            $res = $this->request()->get($base.'/devices', [
                'query' => json_encode(['_id' => $id]),
                'limit' => 1,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'pesan' => 'Tidak bisa menghubungi GenieACS: '.$e->getMessage(), 'data' => null];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'pesan' => $this->pesanGalat($res, 'GET', '/devices', $base), 'data' => null];
        }

        return ['ok' => true, 'pesan' => null, 'data' => ($res->json() ?: [])[0] ?? null];
    }

    /**
     * Batas klasifikasi redaman (dBm), bisa diubah di Pengaturan:
     *  - terlalu_kuat: di ATAS ini = terlalu kuat (mis. -8)
     *  - waspada     : di BAWAH ini = waspada (mis. -22)
     *  - buruk       : di BAWAH ini = buruk (mis. -24)
     * Bawaan dipilih supaya -24,43 dBm berlabel "Buruk", sama dengan
     * tampilan GenieACS milik pengelola.
     *
     * @return array{terlalu_kuat: float, waspada: float, buruk: float}
     */
    public function batasRx(): array
    {
        $ambil = fn (string $kunci, float $bawaan) => is_numeric($n = Setting::get($kunci)) ? (float) $n : $bawaan;

        $waspada = $ambil('redaman_batas_waspada', -22.0);
        $buruk = $ambil('redaman_batas_buruk', -24.0);

        // Kalau salah isi (waspada lebih rendah dari buruk), tukar supaya urutannya benar.
        if ($waspada < $buruk) {
            [$waspada, $buruk] = [$buruk, $waspada];
        }

        return ['terlalu_kuat' => $ambil('redaman_batas_terlalu_kuat', -8.0), 'waspada' => $waspada, 'buruk' => $buruk];
    }

    /**
     * Label + ikon + warna untuk angka RX -- dipakai halaman GenieACS dan
     * bot Telegram supaya keduanya selalu menilai sama.
     *
     * @return array{label: string, ikon: string, warna: string}
     */
    public function klasifikasiRx(?float $rx): array
    {
        if ($rx === null) {
            return ['label' => 'Belum terbaca', 'ikon' => '⚪', 'warna' => '#a1a1aa'];
        }

        $b = $this->batasRx();

        return match (true) {
            $rx > $b['terlalu_kuat'] => ['label' => 'Terlalu kuat', 'ikon' => '🟠', 'warna' => '#9a3412'],
            $rx < $b['buruk'] => ['label' => 'Buruk', 'ikon' => '🔴', 'warna' => '#dc2626'],
            $rx < $b['waspada'] => ['label' => 'Waspada', 'ikon' => '🟡', 'warna' => '#d97706'],
            default => ['label' => 'Bagus', 'ikon' => '🟢', 'warna' => '#16a34a'],
        };
    }

    // ------------------------------------------------------------------
    // Wi-Fi (SSID & kata sandi)
    // ------------------------------------------------------------------

    /**
     * Semua antarmuka Wi-Fi (SSID) yang dikenal GenieACS untuk perangkat ini.
     *
     * TR-098 (InternetGatewayDevice.LANDevice.*.WLANConfiguration.*) dan
     * TR-181 (Device.WiFi.SSID.* + Device.WiFi.AccessPoint.*) dicari otomatis;
     * nomor instance-nya berbeda antar merek (mis. ZTE: 1-4 = 2,4 GHz,
     * 5-8 = 5 GHz).
     *
     * Kata sandi TIDAK selalu bisa dibaca: banyak ONU sengaja mengembalikan
     * kosong/bintang untuk KeyPassphrase. Itu ditandai password = null,
     * sementara jalur_password tetap diisi kalau jalurnya dikenal -- jadi
     * MENGGANTI kata sandi tetap bisa walau membacanya tidak.
     *
     * @return list<array{model:string,dasar:string,indeks:int,ssid:string,jalur_ssid:string,password:?string,jalur_password:?string,password_ditebak:bool,aktif:?bool,band:?string,klien:list<array{mac:string,ip:?string,nama:?string,sinyal:?string}>}>
     */
    public function daftarWifi(array $device): array
    {
        $rata = $this->ratakan($device);
        $hasil = [];
        $klienPerWlan = $this->kumpulkanKlien($rata);

        $aktif = fn (?string $v) => $v === null ? null : in_array(strtolower($v), ['1', 'true', 'enabled'], true);

        foreach ($rata as $jalur => $nilai) {
            // ---- TR-098
            if (preg_match('/^(InternetGatewayDevice\.LANDevice\.\d+\.WLANConfiguration\.(\d+))\.SSID$/', $jalur, $m)) {
                [$_, $dasar, $indeks] = $m;

                [$jalurPw, $pw, $ditebak] = $this->cariKataSandi098($rata, $dasar);

                $hasil[] = [
                    'model' => 'tr098',
                    'dasar' => $dasar,
                    'indeks' => (int) $indeks,
                    'ssid' => $nilai,
                    'jalur_ssid' => $jalur,
                    'password' => $pw,
                    'jalur_password' => $jalurPw,
                    'password_ditebak' => $ditebak,
                    'aktif' => $aktif($rata[$dasar.'.Enable'] ?? null),
                    'band' => $this->bandWifi($rata, $dasar, (int) $indeks),
                    'klien' => $klienPerWlan[$dasar] ?? [],
                ];

                continue;
            }

            // ---- TR-181
            if (preg_match('/^(Device\.WiFi\.SSID\.(\d+))\.SSID$/', $jalur, $m)) {
                [$_, $dasar, $indeks] = $m;
                $jalurPw = null;
                $pw = null;

                foreach ($rata as $jp => $vp) {
                    if (preg_match('/^(Device\.WiFi\.AccessPoint\.\d+)\.SSIDReference$/', $jp, $ma)
                        && rtrim($vp, '.') === $dasar
                        && array_key_exists($ma[1].'.Security.KeyPassphrase', $rata)) {
                        $jalurPw = $ma[1].'.Security.KeyPassphrase';
                        $pw = $this->bersihkanKataSandi($rata[$jalurPw]);
                        break;
                    }
                }

                $hasil[] = [
                    'model' => 'tr181',
                    'dasar' => $dasar,
                    'indeks' => (int) $indeks,
                    'ssid' => $nilai,
                    'jalur_ssid' => $jalur,
                    'password' => $pw,
                    'jalur_password' => $jalurPw,
                    'password_ditebak' => false,
                    'aktif' => $aktif($rata[$dasar.'.Enable'] ?? null),
                    'band' => null,
                    'klien' => $klienPerWlan[$dasar] ?? [],
                ];
            }
        }

        usort($hasil, fn ($a, $b) => [$a['model'], $a['indeks']] <=> [$b['model'], $b['indeks']]);

        return $hasil;
    }

    /**
     * Client Wi-Fi yang terhubung, dikelompokkan per antarmuka Wi-Fi (kunci =
     * jalur dasar antarmuka, sama dengan daftarWifi()['dasar']).
     *
     * Sumber datanya DUA, digabung lewat alamat MAC:
     *  1. tabel AssociatedDevice milik tiap antarmuka Wi-Fi -- siapa yang
     *     benar-benar tersambung (dan kadang kekuatan sinyalnya);
     *  2. tabel Hosts milik LANDevice -- nama perangkat (HostName) dan IP.
     * Host bertipe 802.11 yang aktif tapi tidak ada di AssociatedDevice
     * (sebagian firmware tidak mengisi tabel itu) tetap ditampilkan kalau
     * Layer2Interface-nya menunjuk ke antarmuka Wi-Fi tertentu.
     *
     * PENTING: ONU tidak mengabarkan client baru/keluar ke GenieACS -- data ini
     * hanya sesegar Refresh terakhir (lihat waktuKlien()).
     *
     * @return array<string, list<array{mac:string,ip:?string,nama:?string,sinyal:?string}>>
     */
    protected function kumpulkanKlien(array $rata): array
    {
        $mac = fn (?string $v) => $v ? strtoupper(str_replace('-', ':', trim($v))) : '';

        // ---- tabel Hosts (TR-098 dan TR-181)
        $host = [];

        foreach ($rata as $jalur => $nilai) {
            if (preg_match('/^((?:InternetGatewayDevice\.LANDevice\.\d+\.Hosts|Device\.Hosts)\.Host\.\d+)\.(.+)$/', $jalur, $m)) {
                $host[$m[1]][$m[2]] = $nilai;
            }
        }

        $hostPerMac = [];

        foreach ($host as $h) {
            $m = $mac($h['MACAddress'] ?? $h['PhysAddress'] ?? null);

            if ($m !== '') {
                $hostPerMac[$m] = $h;
            }
        }

        $hasil = [];
        $sudah = [];
        $tambah = function (string $dasar, string $macKlien, ?string $ip, ?string $sinyal) use (&$hasil, &$sudah, $hostPerMac) {
            $kunci = $dasar.'|'.$macKlien;

            if ($macKlien === '' || isset($sudah[$kunci])) {
                return;
            }

            $sudah[$kunci] = true;
            $h = $hostPerMac[$macKlien] ?? [];
            $ipKlien = ($ip !== null && $ip !== '' && $ip !== '0.0.0.0') ? $ip : (($h['IPAddress'] ?? '') !== '' ? $h['IPAddress'] : null);
            $nama = trim((string) ($h['HostName'] ?? ''));

            $hasil[$dasar][] = [
                'mac' => $macKlien,
                'ip' => $ipKlien,
                'nama' => $nama !== '' ? $nama : null,
                'sinyal' => $this->normalisasiSinyal($sinyal),
            ];
        };

        // ---- TR-098: AssociatedDevice di dalam tiap WLANConfiguration
        $asosiasi = [];

        foreach ($rata as $jalur => $nilai) {
            if (preg_match('/^(InternetGatewayDevice\.LANDevice\.\d+\.WLANConfiguration\.\d+)\.AssociatedDevice\.(\d+)\.(.+)$/', $jalur, $m)) {
                $asosiasi[$m[1]][$m[2]][$m[3]] = $nilai;
            }
        }

        foreach ($asosiasi as $dasar => $daftar) {
            foreach ($daftar as $a) {
                $sinyal = null;

                foreach ($a as $daun => $v) {
                    if (preg_match('/(RSSI|SignalStrength|SignalLevel)$/i', $daun) && is_numeric($v)) {
                        $sinyal = $v;
                        break;
                    }
                }

                $tambah($dasar, $mac($a['AssociatedDeviceMACAddress'] ?? null), $a['AssociatedDeviceIPAddress'] ?? null, $sinyal);
            }
        }

        // ---- TR-181: AccessPoint.*.AssociatedDevice, dipetakan ke SSID lewat SSIDReference
        $apKeSsid = [];

        foreach ($rata as $jalur => $nilai) {
            if (preg_match('/^(Device\.WiFi\.AccessPoint\.\d+)\.SSIDReference$/', $jalur, $m)) {
                $apKeSsid[$m[1]] = rtrim($nilai, '.');
            }
        }

        $asosiasi181 = [];

        foreach ($rata as $jalur => $nilai) {
            if (preg_match('/^(Device\.WiFi\.AccessPoint\.\d+)\.AssociatedDevice\.(\d+)\.(.+)$/', $jalur, $m) && isset($apKeSsid[$m[1]])) {
                $asosiasi181[$apKeSsid[$m[1]]][$m[2]][$m[3]] = $nilai;
            }
        }

        foreach ($asosiasi181 as $dasar => $daftar) {
            foreach ($daftar as $a) {
                if (isset($a['Active']) && ! in_array(strtolower($a['Active']), ['1', 'true'], true)) {
                    continue;
                }

                $tambah($dasar, $mac($a['MACAddress'] ?? null), null, $a['SignalStrength'] ?? null);
            }
        }

        // ---- host nirkabel aktif yang tidak muncul di AssociatedDevice
        foreach ($host as $h) {
            $m = $mac($h['MACAddress'] ?? $h['PhysAddress'] ?? null);
            $aktif = strtolower((string) ($h['Active'] ?? '1'));
            $layer = (string) ($h['Layer2Interface'] ?? $h['Layer1Interface'] ?? '');
            $tipe = (string) ($h['InterfaceType'] ?? '');

            if ($m === '' || ! in_array($aktif, ['1', 'true'], true)) {
                continue;
            }

            if (! preg_match('/(InternetGatewayDevice\.LANDevice\.\d+\.WLANConfiguration\.\d+|Device\.WiFi\.SSID\.\d+)/', $layer, $l)) {
                continue;
            }

            $tambah($l[1], $m, null, null);
        }

        foreach ($hasil as &$daftar) {
            usort($daftar, fn ($a, $b) => strcasecmp((string) ($a['nama'] ?? 'zzz'.$a['mac']), (string) ($b['nama'] ?? 'zzz'.$b['mac'])));
        }
        unset($daftar);

        return $hasil;
    }

    /** Kekuatan sinyal client: negatif = dBm; angka positif = nilai mentah (satuan berbeda antar merek). */
    protected function normalisasiSinyal(?string $v): ?string
    {
        if ($v === null || ! is_numeric($v)) {
            return null;
        }

        $n = (float) $v;

        return $n < 0 && $n > -120 ? round($n).' dBm' : (string) $v.' (mentah)';
    }

    /**
     * Kapan data client ini terakhir DIAMBIL dari ONU (ISO 8601 dari GenieACS),
     * atau null kalau belum pernah. Dipakai untuk memberi tahu bahwa daftar
     * client tidak real-time.
     */
    public function waktuKlien(array $device): ?string
    {
        $terbaru = null;

        $telusuri = function (array $node) use (&$telusuri, &$terbaru) {
            foreach ($node as $k => $v) {
                if (! is_array($v) || (is_string($k) && str_starts_with($k, '_'))) {
                    continue;
                }

                if (isset($v['_timestamp']) && is_string($v['_timestamp']) && ($terbaru === null || $v['_timestamp'] > $terbaru)) {
                    $terbaru = $v['_timestamp'];
                }

                $telusuri($v);
            }
        };

        foreach (['InternetGatewayDevice.LANDevice.1.Hosts', 'Device.Hosts'] as $jalur) {
            $node = $device;

            foreach (explode('.', $jalur) as $bagian) {
                $node = is_array($node) && isset($node[$bagian]) ? $node[$bagian] : null;
            }

            if (is_array($node)) {
                $telusuri($node);
            }
        }

        return $terbaru;
    }

    /**
     * Kata sandi WLANConfiguration (TR-098): jalur standar
     * PreSharedKey.N.KeyPassphrase dulu, lalu KeyPassphrase langsung, lalu
     * parameter vendor yang berakhiran Passphrase. Kalau tak satu pun dikenal
     * GenieACS, jalur standar PreSharedKey.1.KeyPassphrase dipakai sebagai
     * TEBAKAN untuk menulis (ONU akan menolak kalau jalurnya tidak ada).
     *
     * @return array{0:?string,1:?string,2:bool}  [jalur, nilai terbaca, jalur_ditebak]
     */
    protected function cariKataSandi098(array $rata, string $dasar): array
    {
        $polaBerurutan = [
            '/^'.preg_quote($dasar, '/').'\.PreSharedKey\.\d+\.KeyPassphrase$/',
            '/^'.preg_quote($dasar, '/').'\.KeyPassphrase$/',
            '/^'.preg_quote($dasar, '/').'\..*Passphrase$/i',
        ];

        foreach ($polaBerurutan as $pola) {
            foreach ($rata as $jalur => $nilai) {
                if (preg_match($pola, $jalur)) {
                    return [$jalur, $this->bersihkanKataSandi($nilai), false];
                }
            }
        }

        return [$dasar.'.PreSharedKey.1.KeyPassphrase', null, true];
    }

    /** Kosong atau hanya bintang = ONU menyembunyikannya (bukan kata sandi sungguhan). */
    protected function bersihkanKataSandi(string $nilai): ?string
    {
        $nilai = trim($nilai);

        return ($nilai === '' || preg_match('/^\*+$/', $nilai)) ? null : $nilai;
    }

    protected function bandWifi(array $rata, string $dasar, int $indeks): ?string
    {
        $pita = $rata[$dasar.'.OperatingFrequencyBand'] ?? null;

        if ($pita !== null && $pita !== '') {
            return str_contains($pita, '5') ? '5 GHz' : (str_contains($pita, '2') ? '2,4 GHz' : $pita);
        }

        $standar = strtolower($rata[$dasar.'.Standard'] ?? $rata[$dasar.'.X_ZTE-COM_Standard'] ?? '');

        if ($standar !== '') {
            if (str_contains($standar, 'ac') || str_contains($standar, 'ax')) {
                return '5 GHz';
            }

            if (preg_match('/[bg]/', $standar)) {
                return '2,4 GHz';
            }
        }

        $kanal = (int) ($rata[$dasar.'.Channel'] ?? 0);

        return $kanal > 14 ? '5 GHz' : ($kanal > 0 ? '2,4 GHz' : null);
    }

    /** Pesan kesalahan untuk SSID yang tidak sah, atau null kalau sah (1-32 byte, tanpa karakter kontrol). */
    public function validasiSsid(string $ssid): ?string
    {
        if ($ssid === '' || trim($ssid) === '') {
            return 'SSID tidak boleh kosong.';
        }

        if (strlen($ssid) > 32) {
            return 'SSID maksimal 32 karakter (huruf/angka/simbol biasa; emoji dan huruf khusus memakai lebih dari 1 byte).';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $ssid)) {
            return 'SSID tidak boleh berisi karakter kontrol.';
        }

        return null;
    }

    /** Kata sandi WPA/WPA2: 8-63 karakter ASCII yang bisa dicetak. */
    public function validasiKataSandi(string $sandi): ?string
    {
        if (strlen($sandi) < 8 || strlen($sandi) > 63) {
            return 'Kata sandi Wi-Fi harus 8 sampai 63 karakter.';
        }

        if (! preg_match('/^[\x20-\x7E]+$/', $sandi)) {
            return 'Kata sandi Wi-Fi hanya boleh huruf, angka, dan simbol biasa (tanpa emoji atau huruf khusus).';
        }

        return null;
    }

    /**
     * Tulis parameter ke ONU (task setParameterValues). Nilai SELALU string.
     * connection_request agar GenieACS langsung menghubungi ONU; kalau ONU
     * tidak terjangkau tugasnya menunggu inform berikutnya (status 202).
     *
     * @param  list<array{0:string,1:string}>  $pasangan  [[jalur, nilai], ...]
     * @return array{ok:bool, pesan:?string, langsung:bool}  langsung=true kalau sudah dijalankan saat itu juga (HTTP 200)
     */
    public function ubahParameter(string $deviceId, array $pasangan): array
    {
        $base = $this->baseUrl();

        if (! $base) {
            return ['ok' => false, 'pesan' => 'URL GenieACS belum diisi di Pengaturan.', 'langsung' => false];
        }

        if (! $pasangan) {
            return ['ok' => false, 'pesan' => 'Tidak ada perubahan.', 'langsung' => false];
        }

        try {
            $res = $this->request()->post(
                $base.'/devices/'.rawurlencode($deviceId).'/tasks?connection_request',
                [
                    'name' => 'setParameterValues',
                    'parameterValues' => array_map(fn ($p) => [$p[0], (string) $p[1], 'xsd:string'], $pasangan),
                ]
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'pesan' => 'Tidak bisa menghubungi GenieACS: '.$e->getMessage(), 'langsung' => false];
        }

        if (! $res->successful()) {
            Log::warning('GenieACS menolak setParameterValues', ['kode' => $res->status(), 'perangkat' => $deviceId, 'isi' => Str::limit(trim(strip_tags($res->body())), 200)]);

            return ['ok' => false, 'pesan' => $this->pesanGalat($res, 'POST', '/devices/{id}/tasks', $this->baseUrl(), $deviceId), 'langsung' => false];
        }

        return ['ok' => true, 'pesan' => null, 'langsung' => $res->status() === 200];
    }

    /** Minta ONU mengirim ulang SATU bagian pohon parameter (mis. "InternetGatewayDevice.LANDevice" untuk data Wi-Fi). */
    public function refreshBagian(string $deviceId, string $objek): array
    {
        $base = $this->baseUrl();

        if (! $base) {
            return ['ok' => false, 'pesan' => 'URL GenieACS belum diisi di Pengaturan.'];
        }

        try {
            $res = $this->request()->post(
                $base.'/devices/'.rawurlencode($deviceId).'/tasks?connection_request',
                ['name' => 'refreshObject', 'objectName' => $objek]
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'pesan' => 'Tidak bisa menghubungi GenieACS: '.$e->getMessage()];
        }

        return $res->successful()
            ? ['ok' => true, 'pesan' => null]
            : ['ok' => false, 'pesan' => $this->pesanGalat($res, 'POST', '/devices/{id}/tasks', $this->baseUrl(), $deviceId)];
    }

    /** Bagian pohon yang perlu di-refresh supaya data Wi-Fi terisi, sesuai model data perangkat. */
    public function objekWifi(array $device): string
    {
        return isset($device['Device']) && ! isset($device['InternetGatewayDevice'])
            ? 'Device.WiFi'
            : 'InternetGatewayDevice.LANDevice';
    }

    /** ASAL angka RX yang tampil di tabel (untuk genieacs:probe). */
    public function sumberRx(array $device): array
    {
        $hasil = $this->bacaRx($device, $this->ratakan($device));
        $hasil['hasil'] = $this->normalisasiRx($hasil['mentah']);

        return $hasil;
    }

    /** Ringkasan satu dokumen perangkat (yang tampil di tabel) -- dipakai genieacs:probe. */
    public function ringkasSatu(array $device): array
    {
        return $this->ringkas([$device])[0];
    }

    /**
     * Ambil nilai parameter dari beberapa kemungkinan jalur sekaligus.
     *
     * Jalur parameter TR-069 berbeda-beda antar merek ONU (dan bahkan
     * antar versi firmware merek yang sama), jadi mencoba satu jalur
     * saja akan menghasilkan kolom kosong untuk sebagian besar
     * perangkat. Yang pertama ketemu dipakai.
     */
    protected function nilai(array $device, array $jalurKandidat): ?string
    {
        foreach ($jalurKandidat as $jalur) {
            $node = $device;
            $ketemu = true;

            foreach (explode('.', $jalur) as $bagian) {
                if (! is_array($node) || ! array_key_exists($bagian, $node)) {
                    $ketemu = false;
                    break;
                }
                $node = $node[$bagian];
            }

            if ($ketemu && is_array($node) && array_key_exists('_value', $node)) {
                return (string) $node['_value'];
            }
        }

        return null;
    }

    /**
     * Kirim tugas ke perangkat (reboot / minta kirim data terbaru).
     * connection_request=1 supaya GenieACS langsung menghubungi
     * perangkatnya, tidak menunggu jadwal inform berikutnya.
     */
    public function tugas(string $deviceId, string $nama): array
    {
        $base = $this->baseUrl();

        if (! $base) {
            return ['ok' => false, 'pesan' => 'URL GenieACS belum diisi di Pengaturan.'];
        }

        $isi = match ($nama) {
            'reboot' => ['name' => 'reboot'],
            'refresh' => ['name' => 'refreshObject', 'objectName' => ''],
            default => null,
        };

        if (! $isi) {
            return ['ok' => false, 'pesan' => 'Perintah tidak dikenali.'];
        }

        try {
            $res = $this->request()->post(
                $base.'/devices/'.rawurlencode($deviceId).'/tasks?connection_request',
                $isi
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'pesan' => 'Tidak bisa menghubungi GenieACS: '.$e->getMessage()];
        }

        if ($res->successful()) {
            return ['ok' => true, 'pesan' => null];
        }

        Log::warning('GenieACS menolak tugas '.$nama, ['kode' => $res->status(), 'perangkat' => $deviceId, 'alamat' => $this->alamatAman($base), 'isi' => Str::limit(trim(strip_tags($res->body())), 200)]);

        return ['ok' => false, 'pesan' => $this->pesanGalat($res, 'POST', '/devices/{id}/tasks', $base, $deviceId)];
    }

    /**
     * Pesan galat yang MENUNJUKKAN penyebabnya, bukan cuma kodenya. Dulu
     * cuma "GenieACS menjawab kode 404." -- tanpa tahu alamat mana yang
     * dipanggil, dan 404 di sini punya DUA arti yang sangat berbeda
     * (alamat GenieACS salah vs perangkat tidak ada).
     */
    protected function pesanGalat(Response $res, string $metode, string $jalur, string $base, ?string $deviceId = null): string
    {
        $kode = $res->status();
        $isi = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($res->body()))), 120);
        $alamat = $this->alamatAman($base);

        $dasar = "GenieACS menjawab kode {$kode} untuk {$metode} {$jalur} (alamat di Pengaturan: {$alamat}).";

        $petunjuk = match (true) {
            $kode === 404 && $deviceId !== null => "Perangkat \"{$deviceId}\" tidak ditemukan di GenieACS -- mungkin sudah dihapus dari ACS, atau ID-nya berubah (mis. sesudah ONU direset/diganti). Tautkan ulang pelanggan ke perangkat yang benar.",
            $kode === 404 => 'Alamat yang diisi kemungkinan BUKAN alamat NBI GenieACS. Yang benar biasanya http://IP-SERVER:7557 (port NBI), tanpa "/devices" di belakangnya. Port 3000 itu antarmuka web, bukan NBI. Kalau GenieACS ada di belakang nginx, sertakan awalan path-nya.',
            $kode === 401 => 'Username/password GenieACS salah, atau GenieACS mewajibkan login tetapi belum diisi di Pengaturan.',
            $kode === 403 => 'GenieACS menolak akses -- periksa hak akses user GenieACS-nya.',
            $kode >= 500 => 'GenieACS-nya sendiri error -- lihat log GenieACS di servernya.',
            default => null,
        };

        return trim($dasar.($petunjuk ? ' '.$petunjuk : '').($isi !== '' ? " (balasan: {$isi})" : ''));
    }

    /** Alamat tanpa user:password (kalau ada) supaya aman ditampilkan/dicatat. */
    protected function alamatAman(string $base): string
    {
        $p = parse_url($base);

        if (! $p || empty($p['host'])) {
            return $base;
        }

        return ($p['scheme'] ?? 'http').'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '').($p['path'] ?? '');
    }
}
