<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Central\Tenant;
use App\Models\Customer;
use App\Models\Radcheck;
use App\Models\Scopes\OutletScope;
use App\Services\CustomerRadiusService;
use App\Support\RadiusUsername;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Menyinkronkan ulang pelanggan (PPPoE & Hotspot) ke tabel FreeRADIUS.
 *
 * KENAPA PERLU: baris kredensial pelanggan di radcheck bisa saja hilang
 * — misalnya pelanggan pernah dihapus lalu dibuat ulang lewat jalur yang
 * tidak menyinkron, atau pernah dilakukan pembersihan data. Kalau itu
 * terjadi, aplikasi tetap menampilkan pelanggannya normal, tapi RADIUS
 * sama sekali tidak mengenalnya sehingga login SELALU ditolak walau
 * username & password di aplikasi sudah benar.
 *
 * Perintah ini memakai CustomerRadiusService::provisionCustomer() yang
 * SAMA PERSIS dipakai saat pelanggan dibuat — jadi hasilnya dijamin
 * identik, bukan logika penulisan RADIUS versi kedua.
 *
 * Contoh pakai:
 *   php artisan radius:sync-customers                 (yang hilang saja)
 *   php artisan radius:sync-customers --force         (tulis ulang SEMUA)
 *   php artisan radius:sync-customers --type=pppoe
 *   php artisan radius:sync-customers --tenant=ijoe
 *   php artisan radius:sync-customers --type=pppoe --force --username=budi01   (satu pelanggan saja)
 *   php artisan radius:sync-customers --dry-run       (lihat dulu, tidak menulis)
 */
class SyncCustomersToRadius extends Command
{
    protected $signature = 'radius:sync-customers
        {--tenant= : Subdomain tenant (kosongkan untuk instalasi utama)}
        {--type= : pppoe atau hotspot (kosongkan untuk keduanya)}
        {--username= : Hanya satu pelanggan (username persis seperti di aplikasi) -- biasanya dipakai bersama --force}
        {--force : Tulis ulang SEMUA pelanggan, bukan cuma yang hilang}
        {--dry-run : Cuma tampilkan yang akan disinkron, tidak menulis apa pun}';

    protected $description = 'Sinkronkan ulang pelanggan PPPoE/Hotspot ke FreeRADIUS (perbaiki kredensial yang hilang)';

    public function handle(CustomerRadiusService $radius): int
    {
        $this->aktifkanTenant();

        $kueri = Customer::withoutGlobalScope(OutletScope::class)->with('servicePackage');

        if ($tipe = $this->option('type')) {
            $enum = CustomerType::tryFrom($tipe);

            if (! $enum) {
                $this->error("Tipe '{$tipe}' tidak dikenal. Pakai: pppoe, hotspot, atau static_ip.");

                return self::FAILURE;
            }

            $kueri->where('type', $enum);
        } else {
            // Perintah ini KHUSUS memperbaiki kredensial yang hilang di
            // FreeRADIUS (radcheck) -- pelanggan Static IP TIDAK PERNAH
            // punya baris di radcheck sama sekali (lihat CustomerType::
            // StaticIp & CustomerRadiusService), jadi kalau ikut disaring
            // di sini tanpa --type, mereka akan SELALU dianggap "hilang
            // dari RADIUS" dan disinkron ulang setiap kali perintah ini
            // jalan -- padahal tidak ada yang benar-benar rusak. Dikecualikan
            // dari saringan default; tetap bisa disinkron manual (misalnya
            // sekadar mendorong ulang lease/queue-nya ke MikroTik) lewat
            // --type=static_ip secara eksplisit.
            $kueri->where('type', '!=', CustomerType::StaticIp->value);
        }

        // Satu pelanggan saja (mis. untuk menguji Mikrotik-Group tanpa
        // menyentuh pelanggan lain).
        if ($username = $this->option('username')) {
            $kueri->where('username', $username);
        }

        $pelanggan = $kueri->get();

        if ($pelanggan->isEmpty()) {
            $this->warn('Tidak ada pelanggan yang cocok.');

            return self::SUCCESS;
        }

        $paksa = (bool) $this->option('force');
        $simulasi = (bool) $this->option('dry-run');

        $disinkron = 0;
        $dilewati = 0;
        $dibukaBlokirnya = 0;
        $gagal = 0;
        $tanpaPaket = [];

        foreach ($pelanggan as $c) {
            $userRadius = RadiusUsername::forRadius($c->username);

            $sudahAda = Radcheck::where('username', $userRadius)
                ->where('attribute', 'Cleartext-Password')
                ->exists();

            // BLOKIR NYASAR: pelanggan berstatus Aktif di aplikasi TAPI
            // masih punya baris "Auth-Type := Reject" di RADIUS.
            //
            // Kejadian nyatanya: pelanggan diisolir otomatis karena
            // tagihan lewat tempo, lalu statusnya dikembalikan ke Aktif
            // lewat form Edit — form itu dulu tidak menyentuh RADIUS
            // sama sekali, jadi baris penolakannya tertinggal. Di layar
            // tertulis Aktif, tapi login SELALU ditolak.
            //
            // Ini diperiksa TERPISAH dari kredensial, karena
            // kredensialnya sendiri baik-baik saja — jadi tanpa
            // pemeriksaan ini pelanggan tersebut akan terus "dilewati"
            // dan tidak pernah ketahuan.
            $blokirNyasar = false;

            if ($c->status === CustomerStatus::Active) {
                $blokirNyasar = Radcheck::where('username', $userRadius)
                    ->where('attribute', 'Auth-Type')
                    ->exists();

                if ($blokirNyasar) {
                    $this->warn(($simulasi ? '[SIMULASI] ' : '').'Buka blokir nyasar: '.$c->name.'  ->  '.$userRadius);

                    if (! $simulasi) {
                        $radius->reactivate($c);
                    }

                    $dibukaBlokirnya++;
                }
            }

            if ($sudahAda && ! $paksa) {
                if (! $blokirNyasar) {
                    $dilewati++;
                }

                continue;
            }

            // Pelanggan tanpa paket TIDAK BISA disinkron: provisionCustomer
            // butuh paket untuk menentukan rate-limit & profile MikroTik.
            if (! $c->servicePackage) {
                $tanpaPaket[] = $c->name.' ('.$c->username.')';
                $gagal++;

                continue;
            }

            $this->line(($simulasi ? '[SIMULASI] ' : '').'Sinkron: '.$c->name.'  ->  '.$userRadius);

            if ($simulasi) {
                $disinkron++;

                continue;
            }

            try {
                $radius->provisionCustomer($c, $c->servicePackage);

                // Pelanggan yang statusnya bukan Aktif harus TETAP
                // diblokir sesudah disinkron ulang — provisionCustomer
                // menulis kredensialnya, tapi tidak memasang penolakan.
                // Tanpa langkah ini, menyinkron ulang justru akan
                // MENGHIDUPKAN kembali pelanggan yang sedang disuspend.
                if ($c->status !== CustomerStatus::Active) {
                    $radius->suspend($c);
                    $this->comment('   (status '.$c->status->label().' -> tetap diblokir di RADIUS)');
                }

                $disinkron++;
            } catch (\Throwable $e) {
                $this->error('   GAGAL: '.$e->getMessage());
                $gagal++;
            }
        }

        $this->newLine();
        $this->info('Selesai.');
        $this->line('  Disinkron        : '.$disinkron);
        $this->line('  Blokir dibuka    : '.$dibukaBlokirnya.' (status Aktif tapi masih diblokir di RADIUS)');
        $this->line('  Dilewati         : '.$dilewati.' (sudah benar)');
        $this->line('  Gagal            : '.$gagal);

        if ($tanpaPaket) {
            $this->newLine();
            $this->warn('Pelanggan berikut TIDAK bisa disinkron karena belum punya paket:');

            foreach ($tanpaPaket as $nama) {
                $this->line('  - '.$nama);
            }
        }

        if ($simulasi) {
            $this->newLine();
            $this->comment('Ini mode simulasi — belum ada yang ditulis. Jalankan lagi tanpa --dry-run.');
        }

        return self::SUCCESS;
    }

    protected function aktifkanTenant(): void
    {
        $subdomain = $this->option('tenant');

        if (! $subdomain) {
            return;
        }

        $tenant = Tenant::where('subdomain', $subdomain)->first();

        if (! $tenant) {
            $this->error("Tenant '{$subdomain}' tidak ditemukan.");
            exit(self::FAILURE);
        }

        Config::set('database.connections.mysql.database', $tenant->database_name);
        DB::purge('mysql');
        DB::reconnect('mysql');
        app()->instance('currentTenant', $tenant);

        $this->line("Tenant aktif: {$tenant->name} ({$tenant->database_name})");
    }
}
