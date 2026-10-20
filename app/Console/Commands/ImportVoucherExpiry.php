<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Models\Voucher;
use App\Services\FreeRadiusService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import KHUSUS tanggal kadaluarsa (kolom "Expired"/"Kedaluwarsa") dari
 * file Excel hasil export "Voucher Terjual", dicocokkan ke voucher yang
 * SUDAH ADA di database lewat kode vouchernya (kolom "Voucher").
 *
 * SENGAJA dibuat TERPISAH dari fitur Import biasa (menu Voucher > Import),
 * karena file export "Voucher Terjual" formatnya beda -- kolomnya bernama
 * "Voucher" (bukan "Username") dan "Product" (bukan "Profile"), dan yang
 * mau diisi CUMA tanggal kadaluarsanya saja. TIDAK menyentuh apa pun yang
 * lain (harga, status, outlet, profile) -- kalau vouchernya tidak
 * ditemukan di database, barisnya dilewati (tidak membuat voucher baru).
 *
 * Sesudah expired_at di database diperbarui, langsung disusulkan juga ke
 * FreeRADIUS (syncExpiration) -- supaya tanggal barunya BENERAN mulai
 * berlaku menahan login, bukan cuma tercatat di database.
 *
 * Pemakaian:
 *
 *     php artisan vouchers:import-expiry storage/app/voucher_sales.xlsx
 *     php artisan vouchers:import-expiry storage/app/voucher_sales.xlsx --tenant=sinyalta
 *     php artisan vouchers:import-expiry storage/app/voucher_sales.xlsx --dry-run
 */
class ImportVoucherExpiry extends Command
{
    protected $signature = 'vouchers:import-expiry
        {file : Path file .xlsx di server (upload dulu filenya ke server)}
        {--tenant= : Jalankan untuk satu subdomain tenant, mis. --tenant=sinyalta (kosongkan untuk database utama)}
        {--dry-run : Cuma tampilkan apa yang AKAN diubah, tanpa benar-benar menyimpan}';

    protected $description = 'Import tanggal kadaluarsa voucher dari file Excel "Voucher Terjual", dicocokkan lewat kode voucher';

    public function handle(FreeRadiusService $freeRadiusService): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $databaseUtama = config('database.connections.mysql.database');
        $subdomain = $this->option('tenant');

        if ($subdomain) {
            $tenant = Tenant::where('subdomain', $subdomain)->first();

            if (! $tenant) {
                $this->error("Tenant dengan subdomain \"{$subdomain}\" tidak ditemukan.");

                return self::FAILURE;
            }

            $this->info("Beralih ke database tenant: {$tenant->subdomain} ({$tenant->database_name})");
            Config::set('database.connections.mysql.database', $tenant->database_name);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        try {
            return $this->prosesFile($path, $freeRadiusService);
        } finally {
            if ($subdomain) {
                Config::set('database.connections.mysql.database', $databaseUtama);
                DB::purge('mysql');
                DB::reconnect('mysql');
            }
        }
    }

    protected function prosesFile(string $path, FreeRadiusService $freeRadiusService): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);

        if (empty($rows)) {
            $this->error('File kosong / tidak terbaca.');

            return self::FAILURE;
        }

        $header = array_shift($rows);
        $kolomVoucher = $this->cariKolom($header, ['voucher', 'username', 'kode voucher', 'kode', 'user']);
        $kolomExpired = $this->cariKolom($header, ['expired', 'kedaluwarsa', 'expired_at', 'tanggal expired', 'tanggal kadaluarsa']);

        if ($kolomVoucher === null || $kolomExpired === null) {
            $this->error('Kolom "Voucher" dan/atau "Expired" tidak ditemukan di file ini. Judul kolom yang terbaca: '.implode(', ', array_filter($header)));

            return self::FAILURE;
        }

        $diperbarui = 0;
        $dilewatiKosong = 0;
        $tidakDitemukan = [];

        $this->info(($isDryRun ? '[DRY RUN] ' : '').'Memproses '.count($rows).' baris...');
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach ($rows as $row) {
            $bar->advance();

            $username = trim((string) ($row[$kolomVoucher] ?? ''));

            if ($username === '') {
                continue;
            }

            $expiredRaw = $row[$kolomExpired] ?? null;
            $expiredAt = $this->parseTanggal($expiredRaw);

            if (! $expiredAt) {
                $dilewatiKosong++;

                continue;
            }

            $voucher = Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                ->where('username', $username)
                ->first();

            if (! $voucher) {
                $tidakDitemukan[] = $username;

                continue;
            }

            if (! $isDryRun) {
                $voucher->update(['expired_at' => $expiredAt]);

                try {
                    $freeRadiusService->syncExpiration($voucher);
                } catch (\Throwable $e) {
                    $this->warn("\n  Gagal sinkron RADIUS untuk {$username}: {$e->getMessage()}");
                }
            }

            $diperbarui++;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Kadaluarsa diperbarui: {$diperbarui}");
        $this->line("Baris dengan kolom Expired kosong (dilewati): {$dilewatiKosong}");

        if (count($tidakDitemukan)) {
            $this->warn('Kode voucher TIDAK ditemukan di database ('.count($tidakDitemukan).'):');
            foreach (array_slice($tidakDitemukan, 0, 20) as $kode) {
                $this->line("  - {$kode}");
            }
            if (count($tidakDitemukan) > 20) {
                $this->line('  ... dan '.(count($tidakDitemukan) - 20).' lainnya.');
            }
        }

        if ($isDryRun) {
            $this->newLine();
            $this->comment('Ini baru simulasi (--dry-run) -- TIDAK ADA yang benar-benar disimpan. Jalankan ulang tanpa --dry-run kalau hasilnya sudah sesuai.');
        }

        return self::SUCCESS;
    }

    /**
     * Cari index kolom dari baris header, dicocokkan case-insensitive
     * ke beberapa kemungkinan nama.
     *
     * @param  array<int, mixed>  $header
     * @param  array<int, string>  $kandidat
     */
    protected function cariKolom(array $header, array $kandidat): ?int
    {
        foreach ($header as $index => $judul) {
            $judul = strtolower(trim((string) $judul));

            if (in_array($judul, $kandidat, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Sama persis logikanya dengan App\Imports\VoucherImport::parseTanggal()
     * -- menangani angka serial Excel, format tanggal Indonesia (hari
     * dulu baru bulan), dan format ISO. Nilai yang tidak bisa dibaca
     * dikembalikan null (lebih aman tidak diisi daripada tanggal ngawur).
     */
    protected function parseTanggal($value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
            } catch (\Throwable) {
                return null;
            }
        }

        $teks = trim((string) $value);

        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})(.*)$#', $teks, $m)) {
            $tanggal = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            $jam = trim($m[4]);

            try {
                return Carbon::parse($jam !== '' ? $tanggal.' '.$jam : $tanggal);
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse($teks);
        } catch (\Throwable) {
            return null;
        }
    }
}
