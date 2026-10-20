<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Models\Setting;
use App\Services\HapusPenjualan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * HAPUS-PENJUALAN -- daftarkan Chat ID Telegram SUPER ADMIN (boleh pakai
 * perintah bot /hapusjual). Chat ID bisa dilihat dengan mengetik /admin
 * di bot.
 *
 *   php artisan toya:superadmin-bot 123456789                  (instalasi utama)
 *   php artisan toya:superadmin-bot 123456789 --tenant=madani  (tenant madani)
 *   php artisan toya:superadmin-bot 123456789 --hapus          (cabut izin)
 *   php artisan toya:superadmin-bot --lihat [--tenant=madani]
 */
class SuperAdminBot extends Command
{
    protected $signature = 'toya:superadmin-bot {chat_id? : Chat ID Telegram} {--tenant= : subdomain tenant (kosong = instalasi utama)} {--hapus : cabut chat ID} {--lihat : tampilkan daftar}';

    protected $description = 'Daftarkan / cabut Chat ID Telegram super admin (perintah bot /hapusjual)';

    public function handle(): int
    {
        $sub = trim((string) $this->option('tenant'));

        if ($sub !== '' && ! in_array($sub, ['utama', 'pusat'], true)) {
            $tenant = Tenant::where('subdomain', $sub)->first();

            if (! $tenant) {
                $this->error("Tenant '{$sub}' tidak ditemukan.");

                return self::FAILURE;
            }

            Config::set('database.connections.mysql.database', $tenant->database_name);
            DB::purge('mysql');
            DB::reconnect('mysql');
            app()->instance('currentTenant', $tenant);
        }

        $daftar = HapusPenjualan::chatSuperAdmin();
        $label = $sub !== '' ? "tenant {$sub}" : 'instalasi utama';

        if ($this->option('lihat') || ! $this->argument('chat_id')) {
            $this->info('Chat ID super admin ('.$label.'): '.($daftar ? implode(', ', $daftar) : '(belum ada)'));

            return self::SUCCESS;
        }

        $id = trim((string) $this->argument('chat_id'));

        if (! preg_match('/^-?\d{3,20}$/', $id)) {
            $this->error('Chat ID harus angka (lihat dengan mengetik /admin di bot).');

            return self::FAILURE;
        }

        if ($this->option('hapus')) {
            $daftar = array_values(array_filter($daftar, fn ($x) => $x !== $id));
        } elseif (! in_array($id, $daftar, true)) {
            $daftar[] = $id;
        }

        Setting::set(HapusPenjualan::KUNCI_CHAT_SUPERADMIN, implode(',', $daftar), 'telegram');

        $this->info('Chat ID super admin ('.$label.') sekarang: '.($daftar ? implode(', ', $daftar) : '(kosong)'));

        return self::SUCCESS;
    }
}
