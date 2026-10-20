<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\DTO\MikrotikConnectionData;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Router;
use App\Models\Scopes\OutletScope;
use App\Models\ServicePackage;
use App\Services\CustomerRadiusService;
use Illuminate\Console\Command;
use RouterOS\Client;
use RouterOS\Query;
use Throwable;

/**
 * PEMERIKSA (hanya membaca, tidak mengubah apa pun): daftar paket PPPoE yang
 * AKAN mengirim atribut RADIUS Mikrotik-Group kalau PPPOE_MIKROTIK_GROUP
 * dinyalakan, dan apakah profile bernama itu memang aman di routernya.
 *
 * Aplikasi hanya mengirim Mikrotik-Group untuk paket yang punya IP Pool DAN
 * Router (lihat CustomerRadiusService::provisionCustomer). Untuk tiap paket
 * itu perintah ini membaca /ppp/profile di router paketnya lalu menilai:
 *
 *   OK       profile ada & punya remote-address (pool) -> pelanggan dapat IP
 *            dari pool itu.
 *   DILEWATI nama profile bawaan MikroTik (default / default-encryption,
 *            termasuk "DEFAULT" dari nilai bawaan form lama) -> aplikasi
 *            SENGAJA tidak mengirim atribut untuk paket ini; pelanggannya
 *            tetap seperti sekarang (default-profile server).
 *   ABAIKAN  nama tidak ada di router -> router mengabaikan atribut itu dan
 *            memakai default-profile server (sudah diuji: pelanggan tetap
 *            bisa konek, hanya tidak pindah pool).
 *   BAHAYA   profile ADA tapi TIDAK punya remote-address, atau di router
 *            hanya beda huruf besar/kecil -> pelanggan bisa tersambung tanpa
 *            IP. JANGAN nyalakan flag sebelum ini dibereskan.
 *   ?        router tidak bisa dihubungi -- belum bisa dinilai.
 *
 * Jalan untuk instalasi utama DAN semua tenant aktif.
 */
class CekMikrotikGroup extends Command
{
    use RunsPerTenant;

    protected $signature = 'pppoe:cek-mikrotik-group';

    protected $description = 'Periksa (baca saja) paket PPPoE mana yang akan mengirim Mikrotik-Group dan apakah profile-nya aman di router. Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(): int
    {
        $bahaya = 0;

        $this->runForEachTenant(function ($tenant) use (&$bahaya) {
            $bahaya += $this->cekTenantIni($tenant?->subdomain ?? 'utama');

            return 1;
        });

        $this->newLine();
        $this->line($bahaya > 0
            ? "<fg=red>{$bahaya} paket BAHAYA</> -- beresi dulu sebelum menyalakan PPPOE_MIKROTIK_GROUP."
            : '<fg=green>Tidak ada paket BAHAYA.</> (Paket "ABAIKAN"/"?" tidak memutus pelanggan, tapi belum tentu pindah pool.)');

        return self::SUCCESS;
    }

    /**
     * @return int jumlah paket berstatus BAHAYA di tenant ini
     */
    public function cekTenantIni(string $label): int
    {
        $paket = ServicePackage::whereIn('type', ['pppoe', 'both'])
            ->with(['router', 'ipPool'])
            ->orderBy('name')
            ->get();

        $akanKirim = $paket->filter(fn (ServicePackage $p) => $p->ip_pool_id && $p->router_id && $p->router);
        $tidakKirim = $paket->count() - $akanKirim->count();

        $this->line("<options=bold>[{$label}]</> {$paket->count()} paket PPPoE, {$akanKirim->count()} akan mengirim Mikrotik-Group, {$tidakKirim} tidak (tanpa Router/IP Pool).");

        $profilPerRouter = [];
        $bahaya = 0;

        foreach ($akanKirim as $p) {
            $router = $p->router;

            if (! array_key_exists($router->id, $profilPerRouter)) {
                $profilPerRouter[$router->id] = $this->bacaProfilRouter($router);
            }

            [$status, $ket] = static::nilai(
                trim((string) $p->mikrotik_profile),
                $profilPerRouter[$router->id]
            );

            if ($status === 'BAHAYA') {
                $bahaya++;
            }

            $pelanggan = Customer::withoutGlobalScope(OutletScope::class)
                ->where('service_package_id', $p->id)
                ->where('type', CustomerType::Pppoe->value)
                ->count();

            $warna = match ($status) {
                'OK' => 'green', 'BAHAYA' => 'red', default => 'yellow',
            };

            $this->line(sprintf(
                '  <fg=%s>%-8s</> %s  [User Group: "%s"]  router=%s  pool=%s  pelanggan=%d  -- %s',
                $warna, $status, $p->name, $p->mikrotik_profile, $router->name, $p->ipPool?->name ?? '-', $pelanggan, $ket
            ));
        }

        return $bahaya;
    }

    /**
     * Logika penilaian murni (mudah dites).
     *
     * @param  array<string,array>|null  $profilRouter  nama-huruf-kecil => baris profile; null = router tak terjangkau
     * @return array{0:string,1:string}  [status, keterangan]
     */
    public static function nilai(string $nama, ?array $profilRouter): array
    {
        if (in_array(strtolower($nama), CustomerRadiusService::PROFILE_BAWAAN_MIKROTIK, true)) {
            return ['DILEWATI', 'nama profile bawaan MikroTik -- aplikasi TIDAK mengirim Mikrotik-Group untuk paket ini (perilaku tetap seperti sekarang)'];
        }

        if ($profilRouter === null) {
            return ['?', 'router tidak bisa dihubungi -- belum bisa dinilai'];
        }

        $baris = $profilRouter[strtolower($nama)] ?? null;

        if (! $baris) {
            return ['ABAIKAN', 'tidak ada profile bernama itu di router -- router mengabaikan atribut'];
        }

        if (($baris['name'] ?? '') !== $nama) {
            return ['BAHAYA', 'di router namanya "'.$baris['name'].'" (beda huruf besar/kecil) -- samakan persis'];
        }

        $remote = trim((string) ($baris['remote-address'] ?? ''));

        if ($remote === '') {
            return ['BAHAYA', 'profile ada tapi TANPA remote-address -- pelanggan tersambung tanpa IP'];
        }

        return ['OK', "pelanggan akan dapat IP dari pool \"{$remote}\""];
    }

    /**
     * Baca daftar /ppp/profile router (dipisah supaya bisa diganti saat tes).
     *
     * @return array<string,array>|null  nama-huruf-kecil => baris; null kalau gagal
     */
    protected function bacaProfilRouter(Router $router): ?array
    {
        try {
            $d = MikrotikConnectionData::fromRouter($router);

            $client = new Client([
                'host' => $d->host,
                'user' => $d->username,
                'pass' => $d->password,
                'port' => $d->port,
                'ssl' => $d->useSsl,
                'timeout' => 6,
                'attempts' => 1,
            ]);

            $hasil = [];
            foreach ($client->query(new Query('/ppp/profile/print'))->read() as $baris) {
                if (is_array($baris) && isset($baris['name'])) {
                    $hasil[strtolower($baris['name'])] = $baris;
                }
            }

            return $hasil;
        } catch (Throwable $e) {
            $this->warn("  Router {$router->name} tidak bisa dihubungi: ".$e->getMessage());

            return null;
        }
    }
}
