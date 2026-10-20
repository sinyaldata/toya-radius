<?php

namespace App\Console\Commands;

use App\DTO\MikrotikConnectionData;
use App\Models\Nas;
use App\Models\Router;
use App\Models\Scopes\TenantScope;
use Illuminate\Console\Command;
use RouterOS\Client;
use RouterOS\Query;

/**
 * DIAGNOSA (hanya membaca): ambil isi /log router lewat API MikroTik dari
 * SSH server -- dipakai kalau admin/teknisi TIDAK bisa membuka WinBox
 * langsung ke router itu (mis. router tenant yang jauh, cuma terjangkau
 * lewat tunnel VPN yang sudah dikenal aplikasi).
 *
 * Router dicari lewat IP-nya di Master Data (kolom Router) ATAU lewat
 * shortname NAS (App\Services\RouterResolver memakai pola pencarian yang
 * sama; di sini disederhanakan jadi satu perintah baca-saja).
 *
 * Contoh:
 *   php artisan router:ambil-log 10.90.90.26
 *   php artisan router:ambil-log KFLINK --topik=hotspot --cari="23Q2B7"
 *   php artisan router:ambil-log 10.90.90.26 --topik=hotspot --baris=100
 */
class RouterAmbilLog extends Command
{
    protected $signature = 'router:ambil-log
        {router : IP router (kolom Router di Master Data) ATAU shortname NAS, mis. KFLINK}
        {--topik= : Saring topik log, mis. hotspot, ppp, system (kosongkan = semua)}
        {--cari= : Saring baris yang MEMUAT teks ini, mis. username voucher/pelanggan}
        {--baris=60 : Maksimal baris TERBARU yang ditampilkan}';

    protected $description = 'Ambil isi /log router lewat API MikroTik dari server (tanpa perlu WinBox) -- untuk router yang tidak bisa diakses langsung.';

    public function handle(): int
    {
        $kunci = trim((string) $this->argument('router'));

        // TANPA withoutGlobalScope(TenantScope::class), Nas/Router yang
        // tenant_id-nya BUKAN NULL (yaitu SEMUA router tenant) tidak akan
        // pernah ketemu sama sekali kalau dijalankan dari SSH/artisan --
        // TenantScope defaultnya cuma menampilkan baris "instalasi utama"
        // (tenant_id NULL) kalau tidak ada konteks tenant aktif. Perintah
        // ini SENGAJA lintas tenant (nama routernya diketik langsung),
        // jadi scope itu dilepas di sini, bukan di model-nya.
        $router = Router::withoutGlobalScope(TenantScope::class)->where('ip_address', $kunci)->first()
            ?? Router::withoutGlobalScope(TenantScope::class)->where('name', $kunci)->first();

        if (! $router) {
            $nas = Nas::withoutGlobalScope(TenantScope::class)
                ->where('nasname', $kunci)
                ->orWhere('shortname', $kunci)
                ->first();
            $router = $nas?->router_id
                ? Router::withoutGlobalScope(TenantScope::class)->find($nas->router_id)
                : null;
        }

        if (! $router) {
            $this->error("Router \"{$kunci}\" tidak ditemukan di Master Data Router atau NAS.");

            return self::FAILURE;
        }

        $this->line("Menghubungi router \"{$router->name}\" ({$router->ip_address})...");

        try {
            $client = $this->buatKlien($router);
        } catch (\Throwable $e) {
            $this->error('Tidak bisa terhubung ke router: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            // "topics" ditapis di PHP (bukan lewat ->where() RouterOS),
            // karena library ini cuma mendukung operator pembanding persis
            // (-, =, >, <) -- topics di /log selalu berupa daftar digabung
            // koma (mis. "hotspot,info"), jadi pembanding PERSIS tidak akan
            // pernah cocok. Diambil semua lalu disaring sendiri (sama
            // seperti --cari), jauh lebih aman ketimbang bergantung pada
            // fitur query yang ternyata tidak ada.
            $baris = $client->query(new Query('/log/print'))->read();
        } catch (\Throwable $e) {
            $this->error('Gagal membaca /log: '.$e->getMessage());

            return self::FAILURE;
        }

        $baris = static::saring($baris, $this->option('topik'), $this->option('cari'), (int) $this->option('baris'));

        if (! $baris) {
            $this->warn('Tidak ada baris log yang cocok. Coba tanpa --topik/--cari dulu untuk memastikan router benar-benar punya isi /log, atau /log di router memang sudah kosong/terhapus.');

            return self::SUCCESS;
        }

        foreach ($baris as $b) {
            $this->line(sprintf(
                '%s %-16s %s',
                $b['time'] ?? '-',
                '['.($b['topics'] ?? '-').']',
                $b['message'] ?? ''
            ));
        }

        $this->newLine();
        $this->line(count($baris).' baris ditampilkan. Waktu di atas memakai jam ROUTER (cek /system clock print kalau perlu dicocokkan dengan waktu server).');

        return self::SUCCESS;
    }

    /** Dipisah supaya bisa diganti dengan router tiruan saat diuji. */
    protected function buatKlien(Router $router): Client
    {
        $d = MikrotikConnectionData::fromRouter($router);

        return new Client([
            'host' => $d->host,
            'user' => $d->username,
            'pass' => $d->password,
            'port' => $d->port,
            'ssl' => $d->useSsl,
            'timeout' => 8,
            'attempts' => 1,
        ]);
    }

    /**
     * Logika penyaringan murni (mudah dites): opsional saring topik (mis.
     * "hotspot" cocok dengan baris ber-topics "hotspot,info" -- topics di
     * /log selalu daftar digabung koma, jadi dicocokkan lewat "memuat",
     * bukan sama persis) dan/atau teks bebas $cari (di topics ATAU
     * message), lalu ambil $maks baris TERBARU.
     *
     * @param  list<array<string,mixed>>  $baris
     * @return list<array<string,mixed>>
     */
    public static function saring(array $baris, ?string $topik, ?string $cari, int $maks): array
    {
        if ($topik) {
            $baris = array_values(array_filter(
                $baris,
                fn ($b) => str_contains(strtolower($b['topics'] ?? ''), strtolower($topik))
            ));
        }

        if ($cari) {
            $baris = array_values(array_filter($baris, fn ($b) => str_contains(
                strtolower(($b['topics'] ?? '').' '.($b['message'] ?? '')),
                strtolower($cari)
            )));
        }

        return array_slice($baris, -$maks);
    }
}
