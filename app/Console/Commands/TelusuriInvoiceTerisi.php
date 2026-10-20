<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentLog;
use App\Models\Scopes\OutletScope;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * PELACAK (hanya membaca): untuk satu periode tagihan (bawaan: bulan ini),
 * tampilkan SEMUA invoice yang sudah "terisi" -- lunas atau sudah ada
 * nominal terbayar -- beserta PENYEBAB dan PEMBAYARAN SUMBERNYA.
 *
 * Dibuat karena kasus (2026-10-01): pelanggan yang menurut admin tidak
 * bayar di muka & tidak punya saldo, invoice bulan barunya sudah terisi.
 * Menurut kode (InvoiceService::generateMonthlyInvoices), invoice baru
 * hanya bisa langsung terisi lewat salah satu jalur di bawah -- perintah
 * ini menunjukkan jalur mana yang terjadi PER PELANGGAN, bukan menebak:
 *
 *  GRATIS           paket Rp0 (otomatis lunas, bukan pembayaran)
 *  SALDO DI MUKA    saldo lebih bayar (credit_balance) dipakai otomatis
 *                   saat invoice dibuat; sumbernya = pembayaran yang
 *                   menambah saldo (kelebihan bayar / bayar di muka)
 *  SALDO SEBAGIAN   saldo lebih kecil dari tagihan -> terisi sebagian
 *  BAYAR SEBAGIAN   ada pembayaran yang dialokasikan sebagian ke invoice ini
 *  DIBAYAR          dilunasi langsung oleh sebuah pembayaran (ada catatannya)
 *  LUNAS MANUAL     berstatus lunas TANPA catatan pembayaran apa pun
 *                   (mis. tombol "Tandai Lunas" di daftar invoice)
 *
 * Contoh:
 *   php artisan invoice:telusuri-terisi
 *   php artisan invoice:telusuri-terisi --bulan=10 --tahun=2026 --batas=300
 */
class TelusuriInvoiceTerisi extends Command
{
    use RunsPerTenant;

    protected $signature = 'invoice:telusuri-terisi
        {--bulan= : Bulan periode tagihan (1-12), bawaan bulan ini}
        {--tahun= : Tahun periode tagihan, bawaan tahun ini}
        {--batas=150 : Maksimal baris rincian per tenant}';

    protected $description = 'Telusuri kenapa invoice suatu periode sudah terisi (lunas/sebagian): saldo di muka, gratis, bayar langsung, atau ditandai lunas manual. Hanya membaca.';

    public function handle(): int
    {
        $bulan = (int) ($this->option('bulan') ?: now()->month);
        $tahun = (int) ($this->option('tahun') ?: now()->year);
        $batas = max(1, (int) $this->option('batas'));

        $this->runForEachTenant(function ($tenant) use ($bulan, $tahun, $batas) {
            $this->telusuriTenantIni($tenant?->subdomain ?? 'utama', $bulan, $tahun, $batas);

            return 0;
        });

        return self::SUCCESS;
    }

    /**
     * @return array{ringkasan: array<string,int>, baris: array<int, array<int,string>>, saldo_sekarang: int}
     */
    public function telusuriTenantIni(string $label, int $bulan, int $tahun, int $batas = 150): array
    {
        $invoices = Invoice::withoutGlobalScopes()
            ->where('period_month', $bulan)
            ->where('period_year', $tahun)
            ->where(fn ($q) => $q->where('status', InvoiceStatus::Paid->value)->orWhere('amount_paid', '>', 0))
            ->orderBy('customer_id')
            ->get();

        $pelangganIds = $invoices->pluck('customer_id')->unique();

        $pelanggan = Customer::withoutGlobalScope(OutletScope::class)
            ->whereIn('id', $pelangganIds)
            ->get(['id', 'name', 'username', 'credit_balance'])
            ->keyBy('id');

        $log = PaymentLog::whereIn('customer_id', $pelangganIds)
            ->orderBy('created_at')
            ->get()
            ->groupBy('customer_id');

        $ringkasan = [];
        $baris = [];

        foreach ($invoices as $inv) {
            [$sebab, $sumber] = $this->klasifikasi($inv, $log->get($inv->customer_id, collect()));
            $ringkasan[$sebab] = ($ringkasan[$sebab] ?? 0) + 1;

            $p = $pelanggan->get($inv->customer_id);
            $baris[] = [
                $sebab,
                (string) ($p->name ?? '(?)'),
                (string) ($p->username ?? '-'),
                number_format((float) $inv->amount, 0, ',', '.'),
                number_format((float) $inv->amount_paid, 0, ',', '.'),
                $inv->created_at?->format('d/m H:i') ?? '-',
                $sumber,
            ];
        }

        usort($baris, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $saldoSekarang = Customer::withoutGlobalScope(OutletScope::class)->where('credit_balance', '>', 0)->count();

        $this->newLine();
        $this->line("<options=bold>[{$label}]</> periode {$bulan}/{$tahun}: ".count($baris).' invoice terisi');

        if ($baris === []) {
            $this->line('  (tidak ada)');
        } else {
            foreach ($ringkasan as $sebab => $jumlah) {
                $this->line("  - {$sebab}: {$jumlah}");
            }

            $this->table(['Sebab', 'Pelanggan', 'Username', 'Tagihan', 'Terbayar', 'Invoice dibuat', 'Sumber terisi'], array_slice($baris, 0, $batas));

            if (count($baris) > $batas) {
                $this->line('  ... dan '.(count($baris) - $batas).' baris lagi (naikkan --batas).');
            }
        }

        $this->line("  Pelanggan yang SEKARANG masih punya saldo di muka: {$saldoSekarang} (otomatis dipakai saat invoice bulan berikutnya dibuat)");

        return ['ringkasan' => $ringkasan, 'baris' => $baris, 'saldo_sekarang' => $saldoSekarang];
    }

    /**
     * @param  Collection<int, PaymentLog>  $logPelanggan
     * @return array{0:string, 1:string} [sebab, sumber]
     */
    protected function klasifikasi(Invoice $inv, Collection $logPelanggan): array
    {
        $lunas = $inv->status === InvoiceStatus::Paid;
        $amount = (float) $inv->amount;

        if ($amount <= 0) {
            return ['GRATIS (paket Rp0)', '-'];
        }

        // Pembayaran yang dialokasikan LANGSUNG ke invoice ini.
        $alokasi = $logPelanggan->first(fn (PaymentLog $l) => collect($l->allocations ?? [])->contains('invoice_id', $inv->id));

        if ($alokasi) {
            return [$lunas ? 'DIBAYAR' : 'BAYAR SEBAGIAN', $this->ringkasLog($alokasi)];
        }

        if (($lunas && $inv->payment_method === 'saldo_dimuka') || (! $lunas && $inv->payment_method === null && (float) $inv->amount_paid > 0)) {
            // Sumber saldo = pembayaran terakhir (sebelum invoice dibuat)
            // yang MENAMBAH saldo lebih bayar.
            $sumber = $logPelanggan
                ->filter(fn (PaymentLog $l) => (float) $l->credit_added > 0 && (! $inv->created_at || $l->created_at <= $inv->created_at))
                ->last();

            return [
                $lunas ? 'SALDO DI MUKA' : 'SALDO SEBAGIAN',
                $sumber ? 'saldo dari: '.$this->ringkasLog($sumber, true) : 'saldo dari: (tidak ada catatan pembayaran penambah saldo)',
            ];
        }

        return $lunas
            ? ['LUNAS MANUAL', 'status lunas ('.($inv->payment_method ?: '-').') tanpa catatan pembayaran'.($inv->paid_at ? ', '.$inv->paid_at->format('d/m H:i') : '')]
            : ['TERISI SEBAGIAN', 'tanpa catatan pembayaran'];
    }

    protected function ringkasLog(PaymentLog $l, bool $denganKelebihan = false): string
    {
        $oleh = $l->recorded_by ? (User::find($l->recorded_by)?->name ?? 'user#'.$l->recorded_by) : 'bot/otomatis';
        $teks = $l->created_at?->format('d/m H:i').' Rp'.number_format((float) $l->amount, 0, ',', '.');

        if ($denganKelebihan) {
            $teks .= ' (kelebihan Rp'.number_format((float) $l->credit_added, 0, ',', '.').')';
        }

        $teks .= ' '.($l->payment_method ?: '-').' oleh '.$oleh;

        if ($l->notes) {
            $teks .= ' "'.mb_strimwidth((string) $l->notes, 0, 45, '…').'"';
        }

        return $teks;
    }
}
