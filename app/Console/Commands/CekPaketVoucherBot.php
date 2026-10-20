<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Enums\PartnerType;
use App\Models\Outlet;
use App\Models\ServicePackage;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * PEMERIKSA (hanya membaca): kenapa sebuah paket hotspot MUNCUL atau TIDAK
 * muncul di menu /voucher bot Telegram, dan apakah outlet/reseller bisa
 * membuat voucher dari paket itu.
 *
 * Aturan yang diperiksa PERSIS sama dengan TelegramWebhookController::
 * showPackageMenu() dan GenerateVoucherBatchAction:
 *   - paket harus AKTIF ("Aktif" di Profile Hotspot),
 *   - BUKAN "Khusus Member" (paket member tidak pernah tampil di bot),
 *   - tipenya hotspot / both,
 *   - kalau paket DIBATASI ke beberapa outlet (tabel service_package_outlet,
 *     yang diatur dari menu Reseller/Outlet), hanya outlet itu yang melihatnya,
 *   - Reseller yang wajib saldo dipotong cost_price x jumlah saat generate:
 *     saldo kurang -> "Gagal membuat voucher: saldo tidak cukup".
 *
 * Contoh:
 *   php artisan telegram:cek-paket-voucher --paket="VOUCHER 2000"
 *   php artisan telegram:cek-paket-voucher --paket="VOUCHER 2000" --outlet="HOME"
 */
class CekPaketVoucherBot extends Command
{
    use RunsPerTenant;

    protected $signature = 'telegram:cek-paket-voucher {--paket= : sebagian nama paket (kosong = semua paket hotspot)} {--outlet= : sebagian nama outlet/reseller (kosong = semua yang tertaut Telegram)}';

    protected $description = 'Periksa (baca saja) kenapa paket hotspot muncul/tidak muncul di menu /voucher bot Telegram. Jalan untuk instalasi utama DAN setiap tenant aktif.';

    public function handle(): int
    {
        $this->runForEachTenant(function ($tenant) {
            $this->periksaTenantIni($tenant?->subdomain ?? 'utama');

            return 1;
        });

        return self::SUCCESS;
    }

    public function periksaTenantIni(string $label): void
    {
        $cariPaket = trim((string) $this->option('paket'));
        $cariOutlet = trim((string) $this->option('outlet'));

        $paket = ServicePackage::with('outlets')
            ->when($cariPaket !== '', fn ($q) => $q->where('name', 'like', "%{$cariPaket}%"))
            ->whereIn('type', ['hotspot', 'both', 'pppoe', 'static_ip'])
            ->orderBy('name')
            ->get();

        // Yang bisa memakai bot voucher: mitra tertaut Telegram, bukan Biller.
        $outlet = Outlet::whereNotNull('telegram_chat_id')
            ->where('partner_type', '!=', PartnerType::Biller->value)
            ->when($cariOutlet !== '', fn ($q) => $q->where('name', 'like', "%{$cariOutlet}%"))
            ->orderBy('name')
            ->get();

        $this->line("<options=bold>[{$label}]</> {$paket->count()} paket cocok, {$outlet->count()} outlet/reseller tertaut Telegram.");

        if ($paket->isEmpty()) {
            $this->warn('  Tidak ada paket dengan nama itu.');

            return;
        }

        foreach ($paket as $p) {
            [$status, $ket, $tampil] = static::analisis($p, $outlet);

            $warna = $status === 'MUNCUL' ? 'green' : 'red';
            $this->line(sprintf('  <fg=%s>%-13s</> %s -- %s', $warna, $status, $p->name, $ket));

            if ($cariOutlet !== '' && $status === 'MUNCUL') {
                foreach ($outlet as $o) {
                    $ada = $tampil->contains('id', $o->id);
                    $this->line(sprintf('      %s %s', $ada ? '<fg=green>ya   </>' : '<fg=red>tidak</>', $o->name));
                }
            }
        }
    }

    /**
     * Logika penilaian murni (mudah dites).
     *
     * @param  Collection<int,Outlet>  $outlet
     * @return array{0:string,1:string,2:Collection}  [status, keterangan, outlet yang melihat paket]
     */
    public static function analisis(ServicePackage $p, Collection $outlet): array
    {
        $tipe = (string) ($p->type?->value ?? $p->type);
        $alasan = [];

        if (! $p->is_active) {
            $alasan[] = 'paket NONAKTIF (toggle "Aktif" di Profile Hotspot mati)';
        }

        if ($p->is_member_only) {
            $alasan[] = 'ditandai "Khusus Member" -- paket member tidak pernah tampil di bot voucher';
        }

        if (! in_array($tipe, ['hotspot', 'both'], true)) {
            $alasan[] = "tipenya \"{$tipe}\", bukan hotspot";
        }

        if ($alasan) {
            return ['TIDAK MUNCUL', implode('; ', $alasan), collect()];
        }

        $dibatasi = $p->outlets;

        if ($dibatasi->isEmpty()) {
            $tampil = $outlet;
            $ket = 'tidak dibatasi -> tampil untuk SEMUA outlet';
        } else {
            $tampil = $outlet->whereIn('id', $dibatasi->pluck('id')->all())->values();
            $ket = "DIBATASI hanya untuk {$dibatasi->count()} outlet ({$tampil->count()} di antaranya tertaut Telegram): "
                .$dibatasi->pluck('name')->take(8)->implode(', ')
                .($dibatasi->count() > 8 ? ', ...' : '');
        }

        $kurang = $tampil->filter(fn (Outlet $o) => $o->requiresSaldo() && (float) $o->saldo < (float) $p->cost_price);

        if ($kurang->isNotEmpty()) {
            $ket .= '. SALDO KURANG untuk 1 voucher (harga modal Rp'.number_format((float) $p->cost_price, 0, ',', '.').'): '
                .$kurang->pluck('name')->take(8)->implode(', ');
        }

        return ['MUNCUL', $ket, $tampil];
    }
}
