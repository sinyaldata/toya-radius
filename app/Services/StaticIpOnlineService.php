<?php

namespace App\Services;

use App\DTO\MikrotikConnectionData;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Router;
use App\Support\RadiusUsername;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Query;
use Throwable;

/**
 * Status ONLINE/OFFLINE pelanggan STATIC IP.
 *
 * KENAPA BEDA DARI PPPoE/MEMBER: pelanggan Static IP TIDAK login lewat
 * RADIUS (cuma DHCP Static Lease + Simple Queue di MikroTik), jadi tidak
 * ada baris di radacct. Satu-satunya cara tahu mereka hidup adalah
 * MENANYA router. Cara yang dipakai: ARP-PING dari router ke IP pelanggan
 * (/ping arp-ping=yes interface=...). Itu jalan di level MAC, jadi TIDAK
 * terpengaruh setelan "blokir remote management / ICMP" di router
 * pelanggan -- perangkat yang menyala pasti menjawab ARP.
 *
 * ALUR:
 *  - Command terjadwal `static-ip:cek-online` (tiap 5 menit, semua tenant)
 *    memanggil checkAll(): per router, ambil daftar alamat IP router
 *    (/ip/address) & tabel ARP SEKALI, tentukan interface tempat IP
 *    pelanggan berada, lalu ARP-ping satu per satu.
 *  - Hasil disimpan di CACHE (bukan tabel baru -> tidak perlu migrasi),
 *    per tenant. Tabel pelanggan cuma MEMBACA cache ini, tidak pernah
 *    menghubungi router saat halaman dibuka.
 *  - Hasil yang lebih tua dari STALE_AFTER dianggap "tidak diketahui",
 *    BUKAN offline -- jadi router mati / API gagal tidak membuat semua
 *    pelanggan mendadak tampil offline.
 *
 * CATATAN: cache ikut terhapus oleh `artisan optimize:clear`; setelah
 * deploy statusnya kosong sampai command terjadwal jalan lagi (maks 5
 * menit) atau tombol "Cek Status" per baris ditekan.
 */
class StaticIpOnlineService
{
    /** Detik. Hasil lebih tua dari ini -> "tidak diketahui". */
    public const STALE_AFTER = 1200;

    /** Detik. Batas waktu satu putaran checkAll() per tenant. */
    public const BUDGET_SECONDS = 480;

    protected const CACHE_TTL = 7200;

    /** @var array<string, array<int, array{0:int,1:int}>> */
    protected static array $memo = [];

    // ------------------------------------------------------------------
    // Cache (dibaca tabel & ditulis pengecekan)
    // ------------------------------------------------------------------

    protected static function cacheKey(): string
    {
        return 'static-ip-online:'.(RadiusUsername::currentPrefix() ?: 'utama');
    }

    /**
     * @return array<int, array{0:int,1:int}>  id pelanggan => [1|0 online, unix time cek]
     */
    public static function read(): array
    {
        $key = static::cacheKey();

        return static::$memo[$key] ??= (array) Cache::get($key, []);
    }

    /**
     * Gabungkan hasil baru ke cache (dibaca ULANG dulu, supaya hasil
     * "Cek Status" per baris yang masuk selama putaran panjang tidak
     * tertimpa).
     *
     * @param  array<int,bool>  $updates  id pelanggan => online?
     */
    public static function write(array $updates): void
    {
        $key = static::cacheKey();
        $now = (array) Cache::get($key, []);

        foreach ($updates as $id => $online) {
            $now[(int) $id] = [$online ? 1 : 0, time()];
        }

        $batas = time() - static::CACHE_TTL;
        $now = array_filter($now, fn ($e) => is_array($e) && ($e[1] ?? 0) >= $batas);

        Cache::put($key, $now, static::CACHE_TTL);
        unset(static::$memo[$key]);
    }

    /** 'online' | 'offline' | 'unknown' */
    public static function stateOf(int $customerId): string
    {
        $e = static::read()[$customerId] ?? null;

        if (! $e || (time() - (int) ($e[1] ?? 0)) > static::STALE_AFTER) {
            return 'unknown';
        }

        return ((int) $e[0]) === 1 ? 'online' : 'offline';
    }

    public static function checkedAt(int $customerId): ?int
    {
        $e = static::read()[$customerId] ?? null;

        return $e ? (int) ($e[1] ?? 0) : null;
    }

    /**
     * Id pelanggan yang SEGAR (belum kedaluwarsa) menurut statusnya --
     * dipakai sort & filter tabel.
     *
     * @return array{online: int[], offline: int[]}
     */
    public static function freshSets(): array
    {
        $online = [];
        $offline = [];
        $batas = time() - static::STALE_AFTER;

        foreach (static::read() as $id => $e) {
            if (($e[1] ?? 0) < $batas) {
                continue;
            }

            if (((int) $e[0]) === 1) {
                $online[] = (int) $id;
            } else {
                $offline[] = (int) $id;
            }
        }

        return ['online' => $online, 'offline' => $offline];
    }

    // ------------------------------------------------------------------
    // Pengecekan
    // ------------------------------------------------------------------

    /**
     * Cek SEMUA pelanggan Static IP di tenant yang sedang aktif.
     *
     * @return array{checked:int, online:int, offline:int, skipped:int, routers_failed:int}
     */
    public function checkAll(): array
    {
        $stats = ['checked' => 0, 'online' => 0, 'offline' => 0, 'skipped' => 0, 'routers_failed' => 0];
        $deadline = microtime(true) + static::BUDGET_SECONDS;

        $customers = Customer::withoutGlobalScopes()
            ->where('type', CustomerType::StaticIp)
            ->whereNotNull('static_ip')
            ->where('static_ip', '!=', '')
            ->with('servicePackage.router')
            ->get();

        $sebelumnya = static::read();

        $perRouter = [];
        foreach ($customers as $customer) {
            $router = $customer->servicePackage?->router;

            if (! $router || ! $router->is_active) {
                $stats['skipped']++;

                continue;
            }

            $perRouter[$router->id]['router'] = $router;
            $perRouter[$router->id]['customers'][] = $customer;
        }

        foreach ($perRouter as $grup) {
            /** @var Router $router */
            $router = $grup['router'];

            // Yang paling lama belum dicek didahulukan -- kalau waktu
            // habis, sisanya pasti kebagian di putaran berikutnya.
            $daftar = collect($grup['customers'])
                ->sortBy(fn (Customer $c) => $sebelumnya[$c->id][1] ?? 0)
                ->values();

            try {
                $client = $this->makeClient($router);
                $inventory = $this->inventory($client);
            } catch (Throwable $e) {
                $stats['routers_failed']++;
                $stats['skipped'] += $daftar->count();
                Log::warning('Cek status Static IP: router tidak bisa dihubungi', [
                    'router' => $router->name,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $hasil = [];

            foreach ($daftar as $customer) {
                if (microtime(true) > $deadline) {
                    $stats['skipped']++;

                    continue;
                }

                $interface = static::resolveInterface($inventory, trim((string) $customer->static_ip));

                if (! $interface) {
                    $stats['skipped']++;

                    continue;
                }

                try {
                    $online = $this->arpPing($client, trim((string) $customer->static_ip), $interface);
                } catch (Throwable $e) {
                    $stats['skipped']++;
                    Log::warning('Cek status Static IP: ARP-ping gagal', [
                        'router' => $router->name,
                        'customer_id' => $customer->id,
                        'error' => $e->getMessage(),
                    ]);

                    // Koneksi mungkin putus di tengah jalan -- coba sambung
                    // SEKALI lagi; kalau gagal, hentikan router ini.
                    try {
                        $client = $this->makeClient($router);
                    } catch (Throwable) {
                        break;
                    }

                    continue;
                }

                $hasil[$customer->id] = $online;
                $stats['checked']++;
                $online ? $stats['online']++ : $stats['offline']++;
            }

            if ($hasil) {
                static::write($hasil);
            }
        }

        return $stats;
    }

    /**
     * Cek SATU pelanggan sekarang (tombol "Cek Status" di tabel).
     *
     * @return array{state: string, message: ?string, interface: ?string}
     *         state: 'online' | 'offline' | 'error'
     */
    public function checkCustomer(Customer $customer): array
    {
        $customer->loadMissing('servicePackage.router');

        $router = $customer->servicePackage?->router;
        $ip = trim((string) $customer->static_ip);

        if (! $router) {
            return ['state' => 'error', 'message' => 'Paket pelanggan ini belum punya Router.', 'interface' => null];
        }

        if ($ip === '') {
            return ['state' => 'error', 'message' => 'Pelanggan ini belum punya IP Address.', 'interface' => null];
        }

        try {
            $client = $this->makeClient($router);
            $interface = static::resolveInterface($this->inventory($client), $ip);

            if (! $interface) {
                return [
                    'state' => 'error',
                    'message' => "Interface untuk IP {$ip} tidak ditemukan di router {$router->name} (IP itu tidak masuk ke jaringan mana pun di /ip address router).",
                    'interface' => null,
                ];
            }

            $online = $this->arpPing($client, $ip, $interface);
        } catch (Throwable $e) {
            return ['state' => 'error', 'message' => $e->getMessage(), 'interface' => null];
        }

        static::write([$customer->id => $online]);

        return ['state' => $online ? 'online' : 'offline', 'message' => null, 'interface' => $interface];
    }

    // ------------------------------------------------------------------
    // Bagian yang bicara ke router (dipisah supaya bisa diganti saat tes)
    // ------------------------------------------------------------------

    protected function makeClient(Router $router): object
    {
        $data = MikrotikConnectionData::fromRouter($router);

        return new Client([
            'host' => $data->host,
            'user' => $data->username,
            'pass' => $data->password,
            'port' => $data->port,
            'ssl' => $data->useSsl,
            // Lebih longgar dari default DTO (2 detik): ARP-ping yang tidak
            // dijawab butuh sekitar 1 detik sebelum router melapor timeout.
            'timeout' => 6,
            'attempts' => 1,
        ]);
    }

    /** @return array{address: array<int,array>, arp: array<int,array>} */
    protected function inventory(object $client): array
    {
        return [
            'address' => $client->query(new Query('/ip/address/print'))->read(),
            'arp' => $client->query(new Query('/ip/arp/print'))->read(),
        ];
    }

    protected function arpPing(object $client, string $ip, string $interface): bool
    {
        $rows = $client->query(
            (new Query('/ping'))
                ->equal('address', $ip)
                ->equal('interface', $interface)
                ->equal('arp-ping', 'yes')
                ->equal('count', '1')
        )->read();

        $hasil = static::parsePing($rows);

        if ($hasil === null) {
            // Format balasan tidak dikenali -- JANGAN dianggap offline
            // (bisa menyesatkan). Catat isinya supaya bisa disesuaikan.
            Log::warning('Cek status Static IP: balasan /ping tidak dikenali', [
                'ip' => $ip,
                'interface' => $interface,
                'rows' => json_encode($rows),
            ]);

            throw new \RuntimeException('Balasan ping dari router tidak dikenali (lihat storage/logs/laravel.log).');
        }

        return $hasil;
    }

    // ------------------------------------------------------------------
    // Logika murni (mudah dites)
    // ------------------------------------------------------------------

    /**
     * true = ada balasan, false = timeout/0 diterima, null = tidak dikenali.
     *
     * @param  array<int,mixed>  $rows
     */
    public static function parsePing(array $rows): ?bool
    {
        $adaTimeout = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (isset($row['time']) || (int) ($row['received'] ?? 0) > 0) {
                return true;
            }

            if (($row['status'] ?? null) === 'timeout' || (isset($row['received']) && (int) $row['received'] === 0)) {
                $adaTimeout = true;
            }
        }

        return $adaTimeout ? false : null;
    }

    /**
     * Interface tempat IP pelanggan berada: dicari dari jaringan yang
     * dimiliki router sendiri (/ip address -- prefix terpanjang menang);
     * kalau tidak ada, dari entri ARP untuk IP itu.
     *
     * @param  array{address?: array<int,array>, arp?: array<int,array>}  $inventory
     */
    public static function resolveInterface(array $inventory, string $ip): ?string
    {
        $target = ip2long($ip);

        if ($target !== false) {
            $terbaik = null;
            $panjangTerbaik = -1;

            foreach ($inventory['address'] ?? [] as $row) {
                if (($row['disabled'] ?? 'false') === 'true' || ($row['invalid'] ?? 'false') === 'true') {
                    continue;
                }

                [$jaringan, $panjang] = array_pad(explode('/', (string) ($row['address'] ?? ''), 2), 2, null);
                $jaringan = ip2long((string) $jaringan);

                if ($jaringan === false || $panjang === null || ! ctype_digit((string) $panjang) || (int) $panjang > 32 || empty($row['interface'])) {
                    continue;
                }

                $panjang = (int) $panjang;
                $mask = $panjang === 0 ? 0 : ((~0 << (32 - $panjang)) & 0xFFFFFFFF);

                if (($jaringan & $mask) === ($target & $mask) && $panjang > $panjangTerbaik) {
                    $terbaik = $row['interface'];
                    $panjangTerbaik = $panjang;
                }
            }

            if ($terbaik !== null) {
                return $terbaik;
            }
        }

        foreach ($inventory['arp'] ?? [] as $row) {
            if (($row['address'] ?? null) === $ip && ! empty($row['interface'])) {
                return $row['interface'];
            }
        }

        return null;
    }
}
