<?php

namespace App\Services;

use App\DTO\MikrotikConnectionData;
use App\Enums\RouterConnectionStatus;
use App\Models\ApiLog;
use App\Models\IpPool;
use App\Models\Router;
use App\Models\ServicePackage;
use App\Models\Setting;
use RouterOS\Client;
use RouterOS\Query;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConfigException;
use Throwable;

/**
 * Wrapper atas evilfreelancer/routeros-api-php untuk seluruh interaksi ke MikroTik RouterOS.
 * composer require evilfreelancer/routeros-api-php
 */
class MikrotikService
{
    protected ?Client $client = null;

    /** host:port:user router yang dipakai $client sekarang -- lihat connect(). */
    protected ?string $kunciClient = null;

    // Nama address-list & komentar rule firewall yang dipakai buat
    // isolir pelanggan STATIC IP (bukan PPPoE/Hotspot -- lihat
    // blockStaticCustomer()/unblockStaticCustomer() di bawah). Dibuat
    // konstanta supaya nama persis sama dipakai di semua method terkait.
    protected const STATIC_ISOLIR_LIST = 'toya-isolir-static';

    protected const STATIC_ISOLIR_FILTER_COMMENT = 'Toya Radius - blokir pelanggan Static IP menunggak (JANGAN dihapus manual, dibuat otomatis)';

    public function connect(Router $router): Client
    {
        $data = MikrotikConnectionData::fromRouter($router);
        $kunci = $data->host.':'.$data->port.':'.$data->username;

        // PERBAIKAN (2026-10-02): dulu cukup "kalau sudah ada $client, pakai
        // lagi" TANPA melihat router mana yang diminta. Kalau SATU instance
        // service dipakai untuk BANYAK router (pembersih sesi hantu, pemulih,
        // dan pembanding online menelusuri semua router & tenant dalam satu
        // proses), semua router "menjawab" dengan isi router PERTAMA -- hasil
        // nyata: tiga router berbeda sama-sama melaporkan 469 pengguna aktif,
        // dan sesi tenant dibandingkan dengan daftar router yang salah.
        // Sekarang koneksi hanya dipakai ulang kalau tujuannya sama persis.
        if ($this->client && $this->kunciClient === $kunci) {
            return $this->client;
        }

        $this->client = $this->buatClient($data);
        $this->kunciClient = $kunci;

        return $this->client;
    }

    /** Dipisah supaya bisa diganti saat pengujian tanpa router sungguhan. */
    protected function buatClient(MikrotikConnectionData $data): Client
    {
        return new Client([
            'host' => $data->host,
            'user' => $data->username,
            'pass' => $data->password,
            'port' => $data->port,
            'ssl' => $data->useSsl,
            'timeout' => $data->timeout,
            'attempts' => 1,
        ]);
    }

    /**
     * Ambil trafik jaringan LIVE PER-INTERFACE (bukan dijumlahkan) dari
     * router ini -- dipakai buat rincian di modal "Lihat Detail" widget
     * Traffik NAS (permintaan user: "interface di mikrotiknya juga
     * belum ada"). getTrafficSummary() di bawah cuma menjumlahkan hasil
     * dari method ini, supaya query API-nya tidak dobel.
     *
     * Sama seperti getTrafficSummary(): pakai "/interface/monitor-
     * traffic" mode "once", dan balik null (BUKAN array kosong) kalau
     * router tidak bisa dihubungi/timeout.
     *
     * RIWAYAT PERBAIKAN soal "kenapa CCR1036 kebaca 0 & nama interface
     * kosong" (2026-09-17, dibaca urut):
     * 1) Awalnya filter ->where('running','true') di /interface/print
     *    dicurigai jadi biang keroknya (dugaan beda versi RouterOS
     *    balikin nilai "running" beda format) -- filter itu dilepas.
     * 2) TERNYATA bukan itu. Penyebab SEBENARNYA: RouterOS MEMBATASI
     *    maksimal 100 interface per panggilan "/interface/monitor-
     *    traffic". CCR1036 ini router yang menampung ratusan sesi
     *    PPPoE pelanggan LANGSUNG -- tiap pelanggan yang connect bikin
     *    satu interface VIRTUAL DINAMIS tersendiri (field "dynamic" =
     *    true), dan totalnya 180+ di router ini, jauh lewat batas 100
     *    -- jadi permintaannya DITOLAK TOTAL sama router (error "value
     *    of interface should have no more than 100 elements"), bukan
     *    cuma sebagian gagal, makanya SEMUA kebaca kosong/nol.
     * FIX final: interface dinamis (dynamic=true) DIKECUALIKAN dari
     * pemantauan -- bukan cuma buat menghindari batas 100 itu, tapi
     * juga karena trafik tiap sesi PPPoE itu SUDAH otomatis kehitung
     * lewat interface FISIK yang dilewatinya (ether/sfp/vlan); kalau
     * dua-duanya dijumlah, hasilnya jadi DOBEL HITUNG. Ditambah jaring
     * pengaman array_slice(,100) -- kalaupun suatu saat ada router
     * dengan interface STATIS lebih dari 100 juga (jarang), tetap
     * dipotong dulu, TIDAK PERNAH lagi ditolak total oleh router.
     *
     * @return array<int, array{name: string, rx_bps: int, tx_bps: int}>|null
     */
    public function getInterfaceTraffics(Router $router): ?array
    {
        $start = microtime(true);

        try {
            $client = $this->connect($router);

            $interfaces = $client->query(new Query('/interface/print'))->read();

            // FIX 2026-09-17 (penyebab CCR1036 selalu 0 & nama interface
            // kosong): RouterOS MEMBATASI maksimal 100 interface per
            // panggilan "/interface/monitor-traffic". Router yang
            // menampung banyak sesi PPPoE pelanggan LANGSUNG (bukan
            // lewat RADIUS terpusat) punya SATU interface virtual
            // dinamis PER PELANGGAN YANG SEDANG CONNECT ("dynamic" =
            // true) -- di CCR1036 ini jumlahnya 180+, jauh melebihi
            // batas 100 itu, jadi permintaannya DITOLAK TOTAL router
            // (bukan cuma sebagian gagal), makanya semua kebaca
            // kosong/nol kemarin.
            //
            // Interface dinamis begini SENGAJA dikecualikan -- bukan
            // cuma buat menghindari batas 100 itu, tapi juga karena
            // trafik tiap sesi PPPoE itu SUDAH otomatis kehitung lewat
            // interface FISIK yang dilewatinya (ether/sfp/vlan) --
            // kalau dua-duanya dijumlah, hasilnya jadi DOBEL HITUNG.
            // Yang mau ditampilkan cukup interface fisik/statis-nya
            // saja, itu sudah cukup mewakili total trafik router ini.
            $interfaces = array_values(array_filter(
                $interfaces,
                fn ($i) => ($i['dynamic'] ?? 'false') !== 'true'
            ));

            $nama = array_values(array_filter(array_map(fn ($i) => $i['name'] ?? null, $interfaces)));

            // Jaring pengaman TAMBAHAN (di luar filter dynamic di atas)
            // -- kalaupun suatu saat masih ada router dengan interface
            // statis/tidak-dinamis lebih dari 100 (jarang, tapi supaya
            // TIDAK PERNAH lagi kena ditolak total sama router seperti
            // kemarin), potong maksimal 100 saja, jangan gagal total.
            $nama = array_slice($nama, 0, 100);

            if (empty($nama)) {
                $this->logApiCall($router, 'interface_traffics', 'success', null, 'no interfaces found', null, $start);

                return [];
            }

            $response = $client->query(
                (new Query('/interface/monitor-traffic'))
                    ->equal('interface', implode(',', $nama))
                    ->equal('once')
            )->read();

            $hasil = array_map(fn ($row) => [
                'name' => $row['name'] ?? '-',
                'rx_bps' => (int) ($row['rx-bits-per-second'] ?? 0),
                'tx_bps' => (int) ($row['tx-bits-per-second'] ?? 0),
            ], $response);

            $this->logApiCall($router, 'interface_traffics', 'success', null, json_encode($hasil), null, $start);

            return $hasil;
        } catch (Throwable $e) {
            $this->logApiCall($router, 'interface_traffics', 'failed', null, null, $e->getMessage(), $start);

            return null;
        }
    }

    /**
     * Ambil trafik jaringan LIVE (kecepatan sekarang, bukan kumulatif)
     * dari SEMUA interface aktif di router ini, dijumlahkan jadi satu
     * angka Download & Upload total -- dipakai widget "Traffik Jaringan
     * NAS" di Dashboard (permintaan user, menggantikan kartu Status
     * Server yang lama). Rincian PER-interface (tanpa dijumlahkan) ada
     * di getInterfaceTraffics() di atas.
     *
     * Balik null kalau router tidak bisa dihubungi/timeout -- PEMANGGIL
     * (widget) WAJIB menangani null ini dengan baik (tampilkan "Tidak
     * terhubung", bukan bikin seluruh Dashboard error).
     */
    public function getTrafficSummary(Router $router): ?array
    {
        $perInterface = $this->getInterfaceTraffics($router);

        if ($perInterface === null) {
            return null;
        }

        return [
            'rx_bps' => (int) array_sum(array_column($perInterface, 'rx_bps')),
            'tx_bps' => (int) array_sum(array_column($perInterface, 'tx_bps')),
        ];
    }

    public function testConnection(Router $router): array
    {
        $start = microtime(true);

        try {
            $client = $this->connect($router);

            $response = $client->query((new Query('/system/identity/print')))->read();
            $identity = $response[0]['name'] ?? null;

            $resource = $client->query((new Query('/system/resource/print')))->read();
            $version = $resource[0]['version'] ?? null;

            $this->logApiCall($router, 'test_connection', 'success', null, json_encode($response), null, $start);

            return [
                'success' => true,
                'identity' => $identity,
                'version' => $version,
            ];
        } catch (Throwable $e) {
            $this->logApiCall($router, 'test_connection', 'failed', null, null, $e->getMessage(), $start);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->client = null;
        }
    }

    /**
     * Push Profile (ServicePackage) ke MikroTik SESUAI TIPENYA:
     *   - hotspot -> /ip/hotspot/user/profile/  (rate-limit, shared-users)
     *   - pppoe   -> /ppp/profile/               (rate-limit, local-address)
     *   - both    -> keduanya
     *
     * BUG LAMA yang diperbaiki: sebelumnya method ini SELALU menulis ke
     * /ip/hotspot/user/profile/ apa pun tipe profile-nya — jadi profile
     * PPPoE tidak pernah benar-benar terbentuk sebagai PPP Profile di
     * router, cuma nyasar jadi Hotspot User Profile yang tidak berguna
     * untuk PPPoE.
     */
    public function syncProfile(Router $router, ServicePackage $profile): array
    {
        $type = $profile->type instanceof \App\Enums\ServicePackageType ? $profile->type->value : $profile->type;

        // Paket Static IP TIDAK punya PPP/Hotspot Profile untuk didorong
        // ke router -- pelanggan tipe ini tidak login RADIUS sama sekali
        // (lihat CustomerType::StaticIp), jadi konfigurasi per-paket di
        // sini tidak berlaku untuknya. Rate-limit-nya baru benar-benar
        // dipasang PER PELANGGAN sebagai Simple Queue, lewat
        // CustomerRadiusService::provisionCustomer() saat pelanggan
        // dibuat/diedit -- BUKAN di sini. Diperlakukan sebagai "berhasil,
        // tidak ada yang perlu disinkron" supaya tombol "Sinkronkan Semua
        // Profile" di NAS/Router tidak melaporkannya sebagai gagal.
        if ($type === 'static_ip') {
            return ['success' => true];
        }

        $results = [];

        if (in_array($type, ['hotspot', 'both'], true)) {
            $results[] = $this->syncHotspotProfile($router, $profile);
        }

        if (in_array($type, ['pppoe', 'both'], true)) {
            $results[] = $this->syncPppProfile($router, $profile);
        }

        $failed = array_filter($results, fn ($r) => ! $r['success']);

        if (empty($results)) {
            return ['success' => false, 'error' => 'Tipe profile tidak dikenali: '.$type];
        }

        if (! empty($failed)) {
            return ['success' => false, 'error' => implode('; ', array_column($failed, 'error'))];
        }

        return ['success' => true];
    }

    protected function syncHotspotProfile(Router $router, ServicePackage $profile): array
    {
        $start = microtime(true);

        try {
            $client = $this->connect($router);

            $existing = $client->query((new Query('/ip/hotspot/user/profile/print'))
                ->where('name', $profile->mikrotik_profile))->read();

            // Koneksi DIBUAT ULANG (bukan pakai socket yang sama) sebelum
            // kirim perintah add/set. Ditemukan lewat error acak yang tidak
            // masuk akal ("The MAC is invalid" — field yang tidak pernah
            // kita kirim) saat print+add/set dikirim beruntun di satu
            // koneksi yang sama; kemungkinan respons dari perintah pertama
            // belum selesai terbaca bersih saat perintah kedua dikirim.
            $this->client = null;
            $client = $this->connect($router);

            $query = empty($existing)
                ? (new Query('/ip/hotspot/user/profile/add'))
                : (new Query('/ip/hotspot/user/profile/set'))->equal('.id', $existing[0]['.id']);

            $query->equal('name', $profile->mikrotik_profile)
                ->equal('rate-limit', $profile->effective_rate_limit)
                ->equal('shared-users', (string) $profile->shared_users)
                ->equal('comment', 'Toya Radius');

            $client->query($query)->read();

            $this->logApiCall($router, 'sync_hotspot_profile', 'success', json_encode(['profile' => $profile->mikrotik_profile]), null, null, $start);

            return ['success' => true];
        } catch (Throwable $e) {
            $this->logApiCall($router, 'sync_hotspot_profile', 'failed', json_encode(['profile' => $profile->mikrotik_profile]), null, $e->getMessage(), $start);

            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->client = null;
        }
    }

    protected function syncPppProfile(Router $router, ServicePackage $profile): array
    {
        $start = microtime(true);

        try {
            $client = $this->connect($router);

            // Nama pool di MikroTik dibuat khas per IP Pool aplikasi, supaya
            // tidak bentrok dengan pool lain yang mungkin sudah ada di
            // router untuk keperluan lain.
            $poolName = null;

            if ($profile->ipPool) {
                $poolName = 'toya-'.\Illuminate\Support\Str::slug($profile->ipPool->name);

                $existingPool = $client->query((new Query('/ip/pool/print'))
                    ->where('name', $poolName))->read();

                $poolQuery = empty($existingPool)
                    ? (new Query('/ip/pool/add'))
                    : (new Query('/ip/pool/set'))->equal('.id', $existingPool[0]['.id']);

                $poolQuery->equal('name', $poolName)
                    ->equal('ranges', $profile->ipPool->start_ip.'-'.$profile->ipPool->end_ip);

                $client->query($poolQuery)->read();

                // Koneksi dibuat ulang lagi sebelum lanjut ke print profile
                // di bawah — pola yang sama seperti sebelumnya untuk hindari
                // respons API tercampur antar perintah beruntun.
                $this->client = null;
                $client = $this->connect($router);
            }

            $existing = $client->query((new Query('/ppp/profile/print'))
                ->where('name', $profile->mikrotik_profile))->read();

            $this->client = null;
            $client = $this->connect($router);

            $query = empty($existing)
                ? (new Query('/ppp/profile/add'))
                : (new Query('/ppp/profile/set'))->equal('.id', $existing[0]['.id']);

            // Profil DIBAGI beberapa paket (nama sama, router sama)? Maka
            // rate-limit di profil DIKOSONGKAN -- kalau tidak, isinya cuma
            // milik paket yang terakhir disinkron dan menyesatkan. Kecepatan
            // pelanggan TETAP diatur RADIUS per-pelanggan (Mikrotik-Rate-Limit,
            // selalu ditulis provisionCustomer) yang menimpa nilai profil.
            $dibagi = ServicePackage::query()
                ->whereIn('type', ['pppoe', 'both'])
                ->where('router_id', $router->id)
                ->where('mikrotik_profile', $profile->mikrotik_profile)
                ->where('id', '!=', $profile->id)
                ->exists();

            $query->equal('name', $profile->mikrotik_profile)
                ->equal('rate-limit', $dibagi ? '' : $profile->effective_rate_limit)
                ->equal('comment', 'Toya Radius');

            if ($profile->ipPool?->gateway) {
                $query->equal('local-address', $profile->ipPool->gateway);
            }

            // WAJIB diisi untuk PPPoE — kalau kosong, MikroTik MENOLAK IP
            // yang dikirim RADIUS sama sekali (error "could not determine
            // remote IP address" di log, ditemukan lewat investigasi
            // langsung). Diarahkan ke pool yang baru dibuat/diperbarui di
            // atas; IP sebenarnya per-pelanggan tetap datang dari RADIUS
            // (Framed-IP-Address), pool ini cuma "mengaktifkan" mekanismenya.
            if ($poolName) {
                $query->equal('remote-address', $poolName);
            }

            $client->query($query)->read();

            // Parent Queue: batas TOTAL bandwidth gabungan untuk semua
            // pelanggan di pool ini (kalau diisi di form IP Pool). Dibuat
            // sebagai Simple Queue statis yang menyasar seluruh rentang IP
            // pool — jadi pemakaian bareng semua pelanggan tidak melebihi
            // batas itu, terlepas dari rate-limit per-pelanggan masing².
            if ($profile->ipPool && ($profile->ipPool->max_download || $profile->ipPool->max_upload)) {
                $this->client = null;
                $client = $this->connect($router);
                $this->syncParentQueue($client, $profile->ipPool);
            }

            $this->logApiCall($router, 'sync_ppp_profile', 'success', json_encode(['profile' => $profile->mikrotik_profile, 'pool' => $poolName]), null, null, $start);

            return ['success' => true];
        } catch (Throwable $e) {
            $this->logApiCall($router, 'sync_ppp_profile', 'failed', json_encode(['profile' => $profile->mikrotik_profile]), null, $e->getMessage(), $start);

            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->client = null;
        }
    }

    /**
     * Parent Queue — batas TOTAL bandwidth gabungan untuk semua pelanggan
     * yang dapat IP dari satu IP Pool. Dibuat sebagai Simple Queue statis
     * (bukan dynamic seperti queue per-pelanggan dari RADIUS), menyasar
     * SELURUH rentang IP pool sekaligus lewat field "Target".
     *
     * CATATAN PENTING (konsisten dengan temuan PPPoE sebelumnya): ini
     * belum tentu langsung "menaungi" queue per-pelanggan yang dibuat
     * dinamis oleh RADIUS (Mikrotik-Rate-Limit) sebagai induk-anak yang
     * benar-benar bertingkat di semua versi RouterOS — perlu dites
     * langsung di router untuk pastikan totalnya benar-benar terpotong
     * gabungan, bukan cuma berdampingan sebagai 2 queue terpisah.
     */
    protected function syncParentQueue(Client $client, IpPool $pool): void
    {
        // Kalau admin sudah isi nama queue manual di form IP Pool, pakai itu
        // (menyasar queue yang SUDAH ADA di MikroTik, bukan bikin baru).
        // Kalau kosong, tetap pakai nama otomatis seperti sebelumnya.
        $queueName = $pool->parent_queue_name ?: 'toya-parent-'.\Illuminate\Support\Str::slug($pool->name);

        $existing = $client->query((new Query('/queue/simple/print'))
            ->where('name', $queueName))->read();

        $query = empty($existing)
            ? (new Query('/queue/simple/add'))
            : (new Query('/queue/simple/set'))->equal('.id', $existing[0]['.id']);

        // Format max-limit MikroTik: "upload/download". Kosongkan sisi
        // yang tidak diisi supaya tidak dibatasi arah itu (pakai angka
        // sangat besar sebagai "tidak dibatasi" tidak diperlukan — cukup
        // isi 0 kalau salah satu memang mau dibiarkan bebas, tapi di sini
        // kalau salah satu kosong kita pakai nilai yang lain juga supaya
        // valid secara sintaks MikroTik).
        $upload = $pool->max_upload ?: ($pool->max_download ?: '0');
        $download = $pool->max_download ?: ($pool->max_upload ?: '0');

        $query->equal('name', $queueName)
            ->equal('target', $pool->start_ip.'-'.$pool->end_ip)
            ->equal('max-limit', $upload.'/'.$download)
            ->equal('comment', 'Toya Radius');

        $client->query($query)->read();
    }

    // ================================================================
    // PELANGGAN STATIC IP (DHCP Lease) — TIDAK login RADIUS sama
    // sekali, jadi cara menyalakan/mematikan/membatasi kecepatannya
    // beda total dari PPPoE/Hotspot di atas:
    //   - "Login"-nya      -> DHCP Static Lease (MAC address -> IP tetap)
    //   - Batas kecepatan  -> Simple Queue per IP pelanggan
    //   - Isolir/suspend   -> IP pelanggan dimasukkan ke address-list
    //     "toya-isolir-static", yang di-drop oleh SATU rule firewall
    //     forward (dibuat otomatis sekali saja kalau belum ada -- lihat
    //     ensureStaticIsolirFilterRule()). Bukan MATIKAN lease-nya
    //     (supaya IP tetap konsisten kalau nanti dibuka lagi), bukan
    //     juga redirect ke walled-garden (lebih sederhana & tidak perlu
    //     halaman blokir tambahan di router).
    //
    // CATATAN PENTING: belum pernah dites di router sungguhan (beda
    // dengan sebagian besar method lain di file ini yang sudah lewat
    // banyak putaran tes langsung -- lihat komentar-komentar di atas).
    // Struktur perintahnya mengikuti pola resmi RouterOS (dhcp-server
    // lease, queue simple, firewall address-list/filter) dan gaya
    // pemanggilan API yang SAMA dengan method lain di file ini, tapi
    // WAJIB dicoba dulu di satu pelanggan test sebelum dipakai massal.
    // ================================================================

    /**
     * Pasang/perbarui DHCP Static Lease: mengikat MAC Address pelanggan
     * ke IP tetap ($ipAddress). Dicari dulu lewat MAC (bukan IP),
     * supaya kalau IP pelanggan pernah diganti lewat form Edit, baris
     * lease yang SAMA (punya MAC itu) yang diperbarui -- bukan malah
     * bikin lease baru dan yang lama nyangkut jadi sampah.
     */
    public function syncStaticLease(Router $router, string $macAddress, string $ipAddress, string $comment): array
    {
        $start = microtime(true);

        try {
            $client = $this->connect($router);

            $existing = $client->query((new Query('/ip/dhcp-server/lease/print'))
                ->where('mac-address', $macAddress))->read();

            $this->client = null;
            $client = $this->connect($router);

            $query = empty($existing)
                ? (new Query('/ip/dhcp-server/lease/add'))
                : (new Query('/ip/dhcp-server/lease/set'))->equal('.id', $existing[0]['.id']);

            // "server" SENGAJA tidak diisi -- RouterOS otomatis mencocokkan
            // ke DHCP Server yang jaringannya cocok dengan $ipAddress kalau
            // dikosongkan. Kalau di router ada LEBIH DARI SATU DHCP Server
            // yang jaringannya tumpang tindih, isi manual lewat Winbox
            // setelah lease ini terbuat.
            $query->equal('address', $ipAddress)
                ->equal('mac-address', $macAddress)
                ->equal('comment', $comment);

            $client->query($query)->read();

            $this->logApiCall($router, 'sync_static_lease', 'success', json_encode(['mac' => $macAddress, 'ip' => $ipAddress]), null, null, $start);

            return ['success' => true];
        } catch (Throwable $e) {
            $this->logApiCall($router, 'sync_static_lease', 'failed', json_encode(['mac' => $macAddress, 'ip' => $ipAddress]), null, $e->getMessage(), $start);

            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->client = null;
        }
    }

    /**
     * Hapus DHCP Static Lease pelanggan (dipanggil saat pelanggan
     * dihapus dari aplikasi -- lihat CustomerRadiusService::
     * deprovisionCustomer()). Dicari lewat MAC Address.
     */
    public function removeStaticLease(Router $router, string $macAddress): array
    {
        $start = microtime(true);

        try {
            $client = $this->connect($router);

            $existing = $client->query((new Query('/ip/dhcp-server/lease/print'))
                ->where('mac-address', $macAddress))->read();

            foreach ($existing as $row) {
                if (isset($row['.id'])) {
                    $this->client = null;
                    $client = $this->connect($router);
                    $client->query((new Query('/ip/dhcp-server/lease/remove'))->equal('.id', $row['.id']))->read();
                }
            }

            $this->logApiCall($router, 'remove_static_lease', 'success', json_encode(['mac' => $macAddress]), null, null, $start);

            return ['success' => true];
        } catch (Throwable $e) {
            $this->logApiCall($router, 'remove_static_lease', 'failed', json_encode(['mac' => $macAddress]), null, $e->getMessage(), $start);

            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->client = null;
        }
    }

    /**
     * Pasang/perbarui Simple Queue buat batasi kecepatan SATU pelanggan
     * Static IP, menyasar IP-nya langsung (target "$ip/32"). Nama queue
     * dibuat dari IP-nya sendiri supaya dicari/diperbarui lagi
     * konsisten walau nama pelanggan berubah.
     */
    public function syncStaticQueue(Router $router, string $ipAddress, string $rateLimit, string $comment): array
    {
        $start = microtime(true);

        try {
            $client = $this->connect($router);

            $queueName = 'toya-static-'.str_replace('.', '-', $ipAddress);

            $existing = $client->query((new Query('/queue/simple/print'))
                ->where('name', $queueName))->read();

            $this->client = null;
            $client = $this->connect($router);

            $query = empty($existing)
                ? (new Query('/queue/simple/add'))
                : (new Query('/queue/simple/set'))->equal('.id', $existing[0]['.id']);

            $query->equal('name', $queueName)
                ->equal('target', $ipAddress.'/32')
                // max-limit Simple Queue = "upload/download" (dari sisi
                // pelanggan) -- urutan yang SAMA dengan Mikrotik-Rate-Limit.
                // Kalau paketnya punya Min (format panjang dengan burst/min),
                // hanya token pertama ("up/down") yang valid di sini.
                ->equal('max-limit', explode(' ', trim($rateLimit))[0])
                ->equal('comment', $comment);

            $client->query($query)->read();

            $this->logApiCall($router, 'sync_static_queue', 'success', json_encode(['ip' => $ipAddress, 'rate' => $rateLimit]), null, null, $start);

            return ['success' => true];
        } catch (Throwable $e) {
            $this->logApiCall($router, 'sync_static_queue', 'failed', json_encode(['ip' => $ipAddress]), null, $e->getMessage(), $start);

            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->client = null;
        }
    }

    /**
     * Hapus Simple Queue pelanggan Static IP (dipanggil saat pelanggan
     * dihapus). Nama queue dicari dari IP-nya, sama seperti dibuat.
     */
    public function removeStaticQueue(Router $router, string $ipAddress): array
    {
        $start = microtime(true);

        try {
            $client = $this->connect($router);

            $queueName = 'toya-static-'.str_replace('.', '-', $ipAddress);

            $existing = $client->query((new Query('/queue/simple/print'))
                ->where('name', $queueName))->read();

            foreach ($existing as $row) {
                if (isset($row['.id'])) {
                    $this->client = null;
                    $client = $this->connect($router);
                    $client->query((new Query('/queue/simple/remove'))->equal('.id', $row['.id']))->read();
                }
            }

            $this->logApiCall($router, 'remove_static_queue', 'success', json_encode(['ip' => $ipAddress]), null, null, $start);

            return ['success' => true];
        } catch (Throwable $e) {
            $this->logApiCall($router, 'remove_static_queue', 'failed', json_encode(['ip' => $ipAddress]), null, $e->getMessage(), $start);

            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->client = null;
        }
    }

    /**
     * Isolir pelanggan Static IP: masukkan IP-nya ke address-list
     * "toya-isolir-static". Rule firewall yang men-drop address-list
     * ini dipastikan ada dulu (dibuat otomatis kalau belum ada) lewat
     * ensureStaticIsolirFilterRule() -- pola yang sama dengan
     * syncPppProfile() yang otomatis membuat IP Pool kalau belum ada.
     */
    public function blockStaticCustomer(Router $router, string $ipAddress, string $comment): array
    {
        $start = microtime(true);

        try {
            $client = $this->connect($router);

            $this->ensureStaticIsolirFilterRule($client);

            $this->client = null;
            $client = $this->connect($router);

            $existing = $client->query((new Query('/ip/firewall/address-list/print'))
                ->where('list', self::STATIC_ISOLIR_LIST)
                ->where('address', $ipAddress))->read();

            if (empty($existing)) {
                $client->query((new Query('/ip/firewall/address-list/add'))
                    ->equal('list', self::STATIC_ISOLIR_LIST)
                    ->equal('address', $ipAddress)
                    ->equal('comment', $comment))->read();
            }

            $this->logApiCall($router, 'block_static_customer', 'success', json_encode(['ip' => $ipAddress]), null, null, $start);

            return ['success' => true];
        } catch (Throwable $e) {
            $this->logApiCall($router, 'block_static_customer', 'failed', json_encode(['ip' => $ipAddress]), null, $e->getMessage(), $start);

            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->client = null;
        }
    }

    /**
     * Buka isolir pelanggan Static IP: keluarkan IP-nya dari
     * address-list "toya-isolir-static". Rule firewall-nya sendiri
     * TIDAK dihapus (tetap dipakai untuk pelanggan lain yang mungkin
     * masih diisolir).
     */
    public function unblockStaticCustomer(Router $router, string $ipAddress): array
    {
        $start = microtime(true);

        try {
            $client = $this->connect($router);

            $existing = $client->query((new Query('/ip/firewall/address-list/print'))
                ->where('list', self::STATIC_ISOLIR_LIST)
                ->where('address', $ipAddress))->read();

            foreach ($existing as $row) {
                if (isset($row['.id'])) {
                    $this->client = null;
                    $client = $this->connect($router);
                    $client->query((new Query('/ip/firewall/address-list/remove'))->equal('.id', $row['.id']))->read();
                }
            }

            $this->logApiCall($router, 'unblock_static_customer', 'success', json_encode(['ip' => $ipAddress]), null, null, $start);

            return ['success' => true];
        } catch (Throwable $e) {
            $this->logApiCall($router, 'unblock_static_customer', 'failed', json_encode(['ip' => $ipAddress]), null, $e->getMessage(), $start);

            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->client = null;
        }
    }

    protected const STATIC_ISOLIR_NAT_COMMENT = 'toya-isolir-static-nat';

    /**
     * Pastikan aturan firewall pelanggan Static IP isolir SESUAI mode yang
     * aktif sekarang (App\Filament\Pages\IsolirSettingsPage) -- dicek dan
     * disesuaikan SETIAP KALI blockStaticCustomer() dipanggil, supaya kalau
     * admin ganti mode di Pengaturan, pelanggan yang BELUM PERNAH
     * diblokir-buka-blokir ulang pun tetap ikut mode terbaru begitu isolir
     * berikutnya terjadi. Idempotent (aman dipanggil berkali-kali).
     *
     *  - Mode 'reject' (bawaan): SATU rule drop polos. Perilaku LAMA,
     *    tidak berubah kalau pengaturan Halaman Isolir tidak pernah disentuh.
     *  - Mode 'walled_garden': rule drop TETAP ada tapi dengan pengecualian
     *    (dst-address != server aplikasi), DITAMBAH satu rule NAT yang
     *    mengarahkan port 80 dari address-list ini ke halaman isolir --
     *    pola yang SAMA dengan PPPoE, lihat IsolirSettingsPage::scriptMikrotik().
     */
    protected function ensureStaticIsolirFilterRule(Client $client): void
    {
        $walledGarden = Setting::get('isolir_mode', 'reject') === 'walled_garden';
        $serverIp = Setting::get('isolir_server_ip');

        $existing = $client->query((new Query('/ip/firewall/filter/print'))
            ->where('comment', self::STATIC_ISOLIR_FILTER_COMMENT))->read();

        // Mode walled_garden butuh server IP terisi -- kalau belum, jangan
        // buat rule drop TANPA pengecualian (itu akan memblokir juga
        // halaman isolirnya sendiri kalau mode-nya diganti belakangan
        // tanpa mengisi IP server dulu). Jatuh balik ke perilaku 'reject'
        // sampai IP server-nya diisi.
        $modeEfektif = ($walledGarden && filled($serverIp)) ? 'walled_garden' : 'reject';

        if (empty($existing)) {
            $query = (new Query('/ip/firewall/filter/add'))
                ->equal('chain', 'forward')
                ->equal('src-address-list', self::STATIC_ISOLIR_LIST)
                ->equal('action', 'drop')
                ->equal('comment', self::STATIC_ISOLIR_FILTER_COMMENT);

            if ($modeEfektif === 'walled_garden') {
                $query->equal('dst-address', '!'.$serverIp);
            }

            $client->query($query)->read();
        } elseif (isset($existing[0]['.id'])) {
            // Rule sudah ada -- SESUAIKAN dst-address-nya kalau mode
            // berubah sejak terakhir dibuat (bukan bikin baru/dobel).
            $query = (new Query('/ip/firewall/filter/set'))->equal('.id', $existing[0]['.id']);
            $query->equal('dst-address', $modeEfektif === 'walled_garden' ? '!'.$serverIp : '');
            $client->query($query)->read();
        }

        $natExisting = $client->query((new Query('/ip/firewall/nat/print'))
            ->where('comment', self::STATIC_ISOLIR_NAT_COMMENT))->read();

        if ($modeEfektif === 'walled_garden') {
            if (empty($natExisting)) {
                $client->query((new Query('/ip/firewall/nat/add'))
                    ->equal('chain', 'dstnat')
                    ->equal('src-address-list', self::STATIC_ISOLIR_LIST)
                    ->equal('protocol', 'tcp')
                    ->equal('dst-port', '80')
                    ->equal('action', 'dst-nat')
                    ->equal('to-addresses', $serverIp)
                    ->equal('to-ports', '80')
                    ->equal('comment', self::STATIC_ISOLIR_NAT_COMMENT))->read();
            }

            $this->bersihkanSisaDnsIsolir($client);
        } else {
            // Mode 'reject' -- rule NAT peninggalan mode walled_garden
            // (kalau ada) dihapus, supaya tidak ada NAT menggantung tanpa
            // gunanya saat sudah balik ke mode Tolak Total.
            foreach ($natExisting as $row) {
                if (isset($row['.id'])) {
                    $client->query((new Query('/ip/firewall/nat/remove'))->equal('.id', $row['.id']))->read();
                }
            }

            $this->bersihkanSisaDnsIsolir($client);
        }
    }

    protected const STATIC_ISOLIR_DNS_NAT_COMMENT = 'toya-isolir-static-dns-nat';

    /**
     * MEMBERSIHKAN sisa fitur "pengalihan DNS" isolir yang DITARIK
     * kembali (2026-09-28). Aplikasi TIDAK LAGI memasang apa pun soal DNS
     * di router -- method ini cuma menghapus peninggalan versi lama, dan
     * HANYA yang bertanda komentar milik kita sendiri:
     *  - NAT redirect port 53 (toya-isolir-static-dns-nat)
     *  - entri /ip dns static wildcard (toya-isolir-dns)
     *
     * KENAPA DITARIK: entri DNS static "regexp=.*" itu berlaku untuk
     * SIAPA SAJA yang memakai DNS router (bukan cuma pelanggan yang
     * diisolir) -- di lapangan membuat SEMUA pelanggan Hotspot di router
     * yang sama kehilangan internet (semua nama domain dijawab dengan IP
     * server aplikasi). Fitur DNS ini tidak bisa dibatasi per-pelanggan
     * di MikroTik, jadi tidak aman dipakai di router bersama.
     *
     * Pengaturan `/ip dns allow-remote-requests` SENGAJA TIDAK disentuh
     * (bisa jadi sudah 'yes' dari sebelum fitur ini dan dipakai Hotspot).
     * Idempotent -- aman dipanggil berkali-kali; kalau tidak ada sisa,
     * tidak ada yang dihapus.
     */
    protected function bersihkanSisaDnsIsolir(Client $client): void
    {
        $natSisa = $client->query((new Query('/ip/firewall/nat/print'))
            ->where('comment', self::STATIC_ISOLIR_DNS_NAT_COMMENT))->read();

        foreach ($natSisa as $row) {
            if (isset($row['.id'])) {
                $client->query((new Query('/ip/firewall/nat/remove'))->equal('.id', $row['.id']))->read();
            }
        }

        $dnsSisa = $client->query((new Query('/ip/dns/static/print'))
            ->where('comment', 'toya-isolir-dns'))->read();

        foreach ($dnsSisa as $row) {
            if (isset($row['.id'])) {
                $client->query((new Query('/ip/dns/static/remove'))->equal('.id', $row['.id']))->read();
            }
        }
    }

    public function disconnectUser(Router $router, string $username): array
    {
        $start = microtime(true);

        try {
            $client = $this->connect($router);
            $removed = 0;

            // Coba di sesi Hotspot dulu, lalu PPPoE — satu method ini dipakai
            // dari menu Hotspot Session maupun PPPoE Online, dan sebuah username
            // hanya akan ada di salah satunya.
            $targets = [
                ['print' => '/ip/hotspot/active/print', 'remove' => '/ip/hotspot/active/remove', 'field' => 'user'],
                ['print' => '/ppp/active/print', 'remove' => '/ppp/active/remove', 'field' => 'name'],
            ];

            foreach ($targets as $target) {
                $active = $client->query((new Query($target['print']))
                    ->where($target['field'], $username))->read();

                foreach ($active as $session) {
                    if (! isset($session['.id'])) {
                        continue;
                    }

                    $client->query((new Query($target['remove']))
                        ->equal('.id', $session['.id']))->read();

                    $removed++;
                }
            }

            if ($removed === 0) {
                $this->logApiCall($router, 'disconnect_user', 'failed', json_encode(['username' => $username]), null, 'Sesi tidak ditemukan di router', $start);

                return [
                    'success' => false,
                    'error' => 'Sesi tidak ditemukan di router. Kemungkinan user sudah logout, atau data online di aplikasi belum sinkron (accounting radius tertinggal).',
                ];
            }

            $this->logApiCall($router, 'disconnect_user', 'success', json_encode(['username' => $username, 'removed' => $removed]), null, null, $start);

            return ['success' => true];
        } catch (Throwable $e) {
            $this->logApiCall($router, 'disconnect_user', 'failed', json_encode(['username' => $username]), null, $e->getMessage(), $start);

            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->client = null;
        }
    }

    /**
     * Ambil daftar sesi PPP yang BENAR-BENAR aktif di router (langsung
     * tanya MikroTik, bukan baca dari tabel radacct aplikasi). Dipakai
     * untuk kasus: MikroTik nyala duluan saat listrik balik, pelanggan
     * berhasil connect sebelum FreeRADIUS/database siap menerima paket
     * accounting — sesinya jalan normal di MikroTik, tapi tidak pernah
     * tercatat di radacct, jadi aplikasi kelihatan "0 online" padahal
     * sebenarnya ada.
     *
     * @return array<int, array{username: string, address: string, uptime: string, caller_id: string}>
     */
    public function getActivePppSessions(Router $router): array
    {
        try {
            $client = $this->connect($router);

            $rows = $client->query((new Query('/ppp/active/print')))->read();
        } finally {
            // Sama seperti metode lain di kelas ini: lepas koneksi setelah
            // selesai supaya panggilan berikutnya (router lain) membuka
            // koneksi baru ke tujuan yang benar.
            $this->client = null;
        }

        return array_map(fn ($row) => [
            'username' => $row['name'] ?? '',
            'address' => $row['address'] ?? '',
            'uptime' => $row['uptime'] ?? '',
            'caller_id' => $row['caller-id'] ?? '',
        ], $rows);
    }

    /**
     * Sama seperti getActivePppSessions() di atas, tapi buat Hotspot —
     * endpoint RouterOS-nya beda (/ip/hotspot/active/print, bukan
     * /ppp/active/print), dipakai HotspotOnlineResource buat tombol
     * "Sinkronkan dari Router".
     *
     * @return array<int, array{username: string, address: string, uptime: string, caller_id: string}>
     */
    public function getActiveHotspotSessions(Router $router): array
    {
        try {
            $client = $this->connect($router);

            $rows = $client->query((new Query('/ip/hotspot/active/print')))->read();
        } finally {
            $this->client = null;
        }

        return array_map(fn ($row) => [
            'username' => $row['user'] ?? '',
            'address' => $row['address'] ?? '',
            'uptime' => $row['uptime'] ?? '',
            'caller_id' => $row['mac-address'] ?? '',
        ], $rows);
    }

    /**
     * Ubah teks uptime RouterOS (mis. "1d2h3m4s", "5m30s") jadi detik.
     * Dipakai untuk perkirakan acctstarttime sesi yang "ketinggalan"
     * dicatat radacct.
     */
    public function parseUptimeToSeconds(string $uptime): int
    {
        preg_match_all('/(\d+)([wdhms])/', $uptime, $matches, PREG_SET_ORDER);
        $seconds = 0;
        $unitSeconds = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];

        foreach ($matches as $m) {
            $seconds += ((int) $m[1]) * ($unitSeconds[$m[2]] ?? 0);
        }

        return $seconds;
    }


    protected function logApiCall(Router $router, string $action, string $status, ?string $request, ?string $response, ?string $error, float $start): void
    {
        ApiLog::create([
            'service' => 'mikrotik',
            'router_id' => $router->id,
            'action' => $action,
            'status' => $status,
            'request_payload' => $request,
            'response_payload' => $response,
            'error_message' => $error,
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
        ]);
    }
}
