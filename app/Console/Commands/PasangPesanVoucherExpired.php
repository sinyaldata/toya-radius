<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Enums\VoucherStatus;
use App\Models\Radreply;
use App\Models\Voucher;
use App\Services\FreeRadiusService;
use App\Support\RadiusUsername;
use Illuminate\Console\Command;

/**
 * Pasang pesan "voucher kadaluarsa, beli yang baru di outlet terdekat" pada
 * voucher yang SUDAH berstatus Expired sebelum fitur pesan itu ada. Pesannya
 * PER VOUCHER: memuat paket, tanggal aktif, dan tanggal kadaluarsa voucher itu
 * (FreeRadiusService::pesanExpiredUntuk).
 *
 * Voucher yang kadaluarsa MULAI SEKARANG otomatis dapat pesannya lewat
 * FreeRadiusService::expireVoucher() (dipanggil tugas terjadwal
 * vouchers:mark-expired). Perintah ini hanya untuk "tumpukan lama".
 *
 * Aman dijalankan berulang: pesan lama milik voucher yang sama diganti,
 * bukan digandakan. Hanya baris Reply-Message yang disentuh -- Auth-Type,
 * password, dan rate limit tidak berubah. Jalan untuk instalasi utama DAN
 * setiap tenant aktif (pesannya mengikuti Setting tenant masing-masing).
 *
 * Contoh:
 *   php artisan vouchers:pasang-pesan-expired --dry-run
 *   php artisan vouchers:pasang-pesan-expired
 */
class PasangPesanVoucherExpired extends Command
{
    use RunsPerTenant;

    protected $signature = 'vouchers:pasang-pesan-expired
        {--dry-run : Hitung saja, tidak menulis apa pun}';

    protected $description = 'Pasang pesan "voucher kadaluarsa, beli yang baru di outlet terdekat" pada voucher yang sudah terlanjur berstatus Expired. Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(): int
    {
        $simulasi = (bool) $this->option('dry-run');

        $total = $this->runForEachTenant(function ($tenant) use ($simulasi) {
            $jumlah = $this->pasangDiTenantIni($simulasi);

            if ($jumlah > 0) {
                $this->line(sprintf('[%s] %d voucher expired %s pesan', $tenant?->subdomain ?? 'utama', $jumlah, $simulasi ? 'AKAN diberi' : 'diberi'));
            }

            return $jumlah;
        });

        $this->info($simulasi
            ? "{$total} voucher AKAN diberi pesan (dry-run, belum ada yang ditulis) -- gabungan semua tenant."
            : "{$total} voucher diberi pesan -- gabungan semua tenant.");

        return self::SUCCESS;
    }

    public function pasangDiTenantIni(bool $simulasi): int
    {
        $total = 0;

        Voucher::withoutGlobalScopes()
            ->where('status', VoucherStatus::Expired->value)
            ->select(['id', 'username', 'voucher_profile_id', 'activated_at', 'expired_at'])
            ->with('profile:id,name')
            ->chunkById(500, function ($potong) use ($simulasi, &$total) {
                $total += $potong->count();

                if ($simulasi) {
                    return;
                }

                $usernameRadius = $potong->mapWithKeys(fn ($v) => [$v->id => RadiusUsername::forRadius($v->username)]);
                $pesanPerVoucher = $potong->mapWithKeys(fn ($v) => [$v->id => FreeRadiusService::pesanExpiredUntuk($v)]);

                // Ganti, bukan gandakan: buang pesan lama voucher-voucher ini lalu
                // sisipkan yang baru dalam SATU insert.
                Radreply::whereIn('username', $usernameRadius->values()->all())
                    ->where('attribute', 'Reply-Message')
                    ->delete();

                Radreply::insert($usernameRadius->map(fn ($u, $id) => [
                    'username' => $u,
                    'attribute' => 'Reply-Message',
                    'op' => '=',
                    'value' => $pesanPerVoucher[$id],
                    'voucher_id' => $id,
                ])->values()->all());
            });

        return $total;
    }
}
