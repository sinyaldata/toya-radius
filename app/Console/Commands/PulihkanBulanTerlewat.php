<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentLog;
use App\Models\Scopes\OutletScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PERBAIKAN DATA untuk pelanggan yang dibuat di TENGAH bulan lalu langsung
 * membayar bulan itu, padahal invoice bulan itu belum ada (kasus
 * 2026-10-01: 6 pelanggan dibuat 15-21 September).
 *
 * Yang terjadi dulu: pembayaran September tidak punya invoice untuk
 * dilunasi -> seluruhnya masuk saldo (credit_balance) -> tanggal 1 Oktober
 * saldo itu otomatis melunasi invoice OKTOBER. September tidak pernah
 * tertagih dan Oktober tampak "lunas" padahal belum dibayar.
 *
 * Perintah ini membetulkannya PER PELANGGAN yang disebut username-nya:
 *   1. Membuat invoice bulan terlewat (mis. September), langsung LUNAS,
 *      tanggal bayar = tanggal pembayaran aslinya.
 *   2. Membuka lagi invoice bulan berikutnya (Oktober) yang dulu lunas dari
 *      saldo, lalu melunasinya lagi HANYA kalau sisa saldonya masih cukup.
 *   3. Menyesuaikan saldo, dan mencatat di PaymentLog aslinya bahwa
 *      sebagian uangnya melunasi invoice bulan terlewat (alokasi + kelebihan
 *      dikurangi) -- sehingga "Hapus pembayaran" di aplikasi tetap konsisten.
 *
 * AMAN: default-nya menolak kalau datanya tidak persis seperti kasus itu
 * (invoice bulan terlewat sudah ada, tidak ada pembayaran sumber dengan
 * kelebihan >= harga paket, saldo tidak cukup, paket tidak berbayar).
 * Hanya menyentuh pelanggan yang DISEBUT. Pakai --dry-run dulu.
 *
 * Invoice bulan berikutnya yang dibuka lagi akan menjadi tagihan sungguhan:
 * kalau tanggal jatuh temponya lewat, pelanggan bisa ikut diisolir otomatis
 * (kalau Isolir Otomatis aktif) -- kabari pelanggannya dulu.
 *
 * Contoh:
 *   php artisan invoice:pulihkan-bulan-terlewat bahri-9992 sus-4990 --dry-run
 *   php artisan invoice:pulihkan-bulan-terlewat bahri-9992 sus-4990 --bulan=9 --tahun=2026
 */
class PulihkanBulanTerlewat extends Command
{
    use RunsPerTenant;

    protected $signature = 'invoice:pulihkan-bulan-terlewat
        {username* : Username pelanggan yang diperbaiki (boleh lebih dari satu)}
        {--bulan= : Bulan yang terlewat (1-12), bawaan: bulan lalu}
        {--tahun= : Tahun bulan yang terlewat, bawaan: tahun bulan lalu}
        {--dry-run : Tampilkan rencana saja, tidak menulis apa pun}';

    protected $description = 'Perbaiki pelanggan yang membayar bulan berjalan sebelum invoice-nya ada (uang salah masuk saldo, invoice bulan berikutnya ikut lunas).';

    /** @var array<int,string> username yang ketemu di salah satu tenant */
    protected array $ditemukan = [];

    /** @var array<string,int> jumlah per status (ok / lewati / gagal) */
    protected array $hitung = [];

    public function handle(): int
    {
        $bulanLalu = now()->startOfMonth()->subMonthNoOverflow();
        $bulan = (int) ($this->option('bulan') ?: $bulanLalu->month);
        $tahun = (int) ($this->option('tahun') ?: $bulanLalu->year);

        if ($bulan < 1 || $bulan > 12) {
            $this->error('--bulan harus 1 sampai 12.');

            return self::FAILURE;
        }

        $periode = Carbon::create($tahun, $bulan, 1)->startOfDay();
        $simulasi = (bool) $this->option('dry-run');
        $username = array_values(array_unique((array) $this->argument('username')));

        $this->info(($simulasi ? '[SIMULASI] ' : '')."Memperbaiki bulan {$periode->translatedFormat('F Y')} untuk ".count($username).' pelanggan');

        $this->runForEachTenant(function ($tenant) use ($username, $periode, $simulasi) {
            $jumlah = 0;

            foreach ($this->pelangganDiTenantIni($username) as $pelanggan) {
                $this->ditemukan[] = $pelanggan->username;
                $hasil = $this->pulihkanAman($pelanggan, $periode, $simulasi);
                $label = '['.($tenant?->subdomain ?? 'utama').'] '.$pelanggan->name.' ('.$pelanggan->username.')';
                $this->hitung[$hasil['status']] = ($this->hitung[$hasil['status']] ?? 0) + 1;

                if ($hasil['status'] === 'ok') {
                    $jumlah++;
                    $this->line("<fg=green>OK</>     {$label}");
                    foreach ($hasil['rincian'] as $baris) {
                        $this->line("        {$baris}");
                    }
                } elseif ($hasil['status'] === 'gagal') {
                    $this->line("<fg=red>GAGAL</>  {$label}: {$hasil['pesan']}");
                } else {
                    $this->line("<fg=yellow>LEWATI</> {$label}: {$hasil['pesan']}");
                }
            }

            return $jumlah;
        });

        $this->newLine();
        $this->line(sprintf(
            'Ringkasan: %d %s, %d dilewati, %d gagal.',
            $this->hitung['ok'] ?? 0,
            $simulasi ? 'akan diperbaiki' : 'diperbaiki',
            $this->hitung['lewati'] ?? 0,
            $this->hitung['gagal'] ?? 0
        ));

        foreach (array_diff($username, $this->ditemukan) as $hilang) {
            $this->line("<fg=red>TIDAK DITEMUKAN</> {$hilang} (tidak ada di instalasi utama maupun tenant mana pun)");
        }

        if ($simulasi) {
            $this->newLine();
            $this->comment('Simulasi selesai, TIDAK ada yang diubah. Jalankan lagi tanpa --dry-run untuk menerapkan.');
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Customer> */
    protected function pelangganDiTenantIni(array $username)
    {
        return Customer::withoutGlobalScope(OutletScope::class)->whereIn('username', $username)->get();
    }

    /**
     * Sama seperti pulihkanPelanggan(), tapi galat apa pun pada SATU
     * pelanggan (mis. bentrok nomor invoice) hanya menandai pelanggan itu
     * 'gagal' -- perubahan pelanggan itu dibatalkan otomatis (ada di dalam
     * transaksi) dan pelanggan berikutnya tetap diproses.
     *
     * @return array{status: string, pesan: string, rincian: array<int,string>}
     */
    public function pulihkanAman(Customer $pelanggan, Carbon $periode, bool $simulasi): array
    {
        try {
            return $this->pulihkanPelanggan($pelanggan, $periode, $simulasi);
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'gagal', 'pesan' => mb_strimwidth($e->getMessage(), 0, 160, '…'), 'rincian' => []];
        }
    }

    /**
     * @return array{status: string, pesan: string, rincian: array<int,string>}
     */
    public function pulihkanPelanggan(Customer $pelanggan, Carbon $periode, bool $simulasi): array
    {
        $lewati = fn (string $pesan) => ['status' => 'lewati', 'pesan' => $pesan, 'rincian' => []];
        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
        $label = $periode->translatedFormat('F Y');

        $paket = $pelanggan->servicePackage;

        if (! $paket || (float) $paket->sale_price <= 0) {
            return $lewati('paket tidak berbayar / tidak ada paket');
        }

        $harga = (float) $paket->sale_price;
        $berikutnya = $periode->copy()->addMonthNoOverflow();

        $sudahAda = Invoice::where('customer_id', $pelanggan->id)
            ->where('period_month', $periode->month)->where('period_year', $periode->year)->exists();

        if ($sudahAda) {
            return $lewati("sudah ada invoice {$label} -- bukan kasus ini, tidak disentuh");
        }

        $invBerikut = Invoice::where('customer_id', $pelanggan->id)
            ->where('period_month', $berikutnya->month)->where('period_year', $berikutnya->year)->first();

        // Invoice bulan berikutnya hanya "dibuka lagi" kalau TERBUKTI terisi
        // dari saldo (bukan dibayar langsung / ditandai lunas manual).
        $dariSaldo = 0.0;
        $sentuhBerikut = false;

        if ($invBerikut) {
            $lunasDariSaldo = $invBerikut->status === InvoiceStatus::Paid && $invBerikut->payment_method === 'saldo_dimuka';
            $sebagianDariSaldo = $invBerikut->status !== InvoiceStatus::Paid && $invBerikut->payment_method === null && (float) $invBerikut->amount_paid > 0;

            if ($lunasDariSaldo || $sebagianDariSaldo) {
                $dariSaldo = (float) $invBerikut->amount_paid;
                $sentuhBerikut = true;
            }
        }

        $kolam = (float) $pelanggan->credit_balance + $dariSaldo;

        if ($kolam + 0.005 < $harga) {
            return $lewati('saldo + pembayaran dari saldo ('.$rp($kolam).") kurang dari harga paket ({$rp($harga)})");
        }

        $batasWaktu = $invBerikut?->created_at ?? now();
        $sumber = PaymentLog::where('customer_id', $pelanggan->id)
            ->where('credit_added', '>=', $harga - 0.005)
            ->where('created_at', '<=', $batasWaktu)
            ->orderByDesc('created_at')
            ->first();

        if (! $sumber) {
            return $lewati("tidak ada pembayaran sumber dengan kelebihan >= {$rp($harga)} sebelum invoice {$berikutnya->translatedFormat('F Y')} dibuat");
        }

        // ---- rencana ----
        $sisa = $kolam - $harga;
        $rincian = [
            "Pembayaran sumber: {$sumber->created_at->format('d/m/Y H:i')} {$rp($sumber->amount)} (kelebihan {$rp($sumber->credit_added)})",
            "{$label}: DIBUAT & LUNAS {$rp($harga)}",
        ];

        $berikutBaru = null;

        if ($sentuhBerikut) {
            $dipakai = min($sisa, (float) $invBerikut->amount);
            $lunasLagi = $dipakai + 0.005 >= (float) $invBerikut->amount;
            $sisa -= $dipakai;
            $berikutBaru = ['dipakai' => $dipakai, 'lunas' => $lunasLagi];

            $rincian[] = "{$berikutnya->translatedFormat('F Y')}: dulu ".$invBerikut->status->value.' terbayar '.$rp($invBerikut->amount_paid).' (dari saldo) -> '
                .($lunasLagi ? 'TETAP LUNAS dari sisa saldo' : ($dipakai > 0 ? 'terbayar sebagian '.$rp($dipakai).', sisa ditagih' : 'DIBUKA LAGI: belum bayar')
                    .' (jatuh tempo '.$invBerikut->due_date->format('d/m/Y').')');
        } elseif ($invBerikut) {
            $rincian[] = "{$berikutnya->translatedFormat('F Y')}: tidak diubah (bukan terisi dari saldo)";
        }

        $rincian[] = 'Saldo: '.$rp($pelanggan->credit_balance).' -> '.$rp($sisa);

        if ($simulasi) {
            return ['status' => 'ok', 'pesan' => '', 'rincian' => $rincian];
        }

        DB::transaction(function () use ($pelanggan, $periode, $harga, $sumber, $invBerikut, $berikutBaru, $sisa) {
            $baru = Invoice::create([
                'customer_id' => $pelanggan->id,
                'period_month' => $periode->month,
                'period_year' => $periode->year,
                'amount' => $harga,
                'amount_paid' => $harga,
                'due_date' => $periode->copy()->day(min((int) $pelanggan->billing_day, $periode->daysInMonth)),
                'status' => InvoiceStatus::Paid,
                'paid_at' => $sumber->created_at,
                'payment_method' => $sumber->payment_method ?: 'tunai',
                'notes' => 'Dipulihkan: dibayar '.$sumber->created_at->format('d/m/Y H:i').' sebelum invoice bulan ini ada (sempat masuk saldo).',
                'biller_id' => $sumber->biller_id,
            ]);

            if ($berikutBaru) {
                $invBerikut->update($berikutBaru['lunas']
                    ? ['amount_paid' => $invBerikut->amount, 'status' => InvoiceStatus::Paid, 'paid_at' => $invBerikut->paid_at ?? now(), 'payment_method' => 'saldo_dimuka']
                    : ['amount_paid' => $berikutBaru['dipakai'], 'status' => InvoiceStatus::Unpaid, 'paid_at' => null, 'payment_method' => null]);
            }

            // Pembaruan langsung (bukan ->save()) supaya tidak memicu event
            // model Customer -- yang diubah cuma saldo.
            Customer::withoutGlobalScope(OutletScope::class)->whereKey($pelanggan->id)->update(['credit_balance' => round($sisa, 2)]);

            // Catatan di pembayaran aslinya: sebagian uangnya kini melunasi
            // invoice bulan terlewat, bukan lagi "kelebihan" -- supaya
            // pembatalan pembayaran di aplikasi tetap membalik dengan benar.
            $sumber->allocations = array_merge($sumber->allocations ?? [], [['invoice_id' => $baru->id, 'amount' => $harga]]);
            $sumber->credit_added = max(0, (float) $sumber->credit_added - $harga);
            $sumber->save();
        });

        return ['status' => 'ok', 'pesan' => '', 'rincian' => $rincian];
    }
}
