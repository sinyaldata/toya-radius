<?php

namespace App\Filament\Resources\InvoiceResource\Widgets;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Empat kartu di atas tabel Invoice:
 *   1. Pendapatan Hari Ini  — tagihan LUNAS yang dibayar hari ini.
 *   2. Pendapatan Bulan Ini — tagihan LUNAS yang dibayar bulan ini.
 *   3. Belum Bayar          — SISA tagihan Unpaid + Overdue.
 *   4. Jatuh Tempo          — SISA tagihan yang statusnya Overdue.
 *
 * Cara hitung (disamakan dengan DashboardService supaya angkanya tidak
 * beda antar halaman):
 * - PENDAPATAN memakai `paid_at` (kapan uang diterima), BUKAN due_date —
 *   jadi tagihan bulan lalu yang baru dibayar hari ini tetap masuk
 *   pendapatan HARI INI, sesuai kas yang benar-benar masuk.
 * - BELUM BAYAR & JATUH TEMPO memakai SISA tagihan (amount -
 *   amount_paid), supaya tagihan yang sudah dibayar sebagian tidak
 *   terhitung utuh lagi.
 *
 * Soal lokasi kelas & pollingInterval, lihat catatan lengkap di
 * PppoeUserResource\Widgets\PppoeUserOverview.
 */
class InvoiceOverview extends Widget
{
    // RINGKASAN-LANGSUNG: dimuat bersama halaman (tanpa kotak loading berkedip)
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.tya-stat-cards';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    // RINGKASAN-CEPAT: angka kartu disimpan 2 menit per tenant & per user, supaya
    // halaman tidak menghitung ulang ke database setiap kali dibuka.
    public function getCards(): array
    {
        $kunci = 'tya-ringkasan:'.static::class.':'
            .\Illuminate\Support\Facades\DB::connection()->getDatabaseName().':'
            .(auth()->id() ?? 0);

        return \Illuminate\Support\Facades\Cache::remember($kunci, 120, fn () => $this->hitungKartu());
    }

    protected function hitungKartu(): array
    {
        $hariIni = Carbon::today();

        try {
            $pendapatanHariIni = (float) Invoice::query()
                ->where('status', InvoiceStatus::Paid)
                ->whereDate('paid_at', $hariIni)
                ->sum('amount_paid');

            $pendapatanBulanIni = (float) Invoice::query()
                ->where('status', InvoiceStatus::Paid)
                ->whereBetween('paid_at', [
                    $hariIni->copy()->startOfMonth()->startOfDay(),
                    $hariIni->copy()->endOfMonth()->endOfDay(),
                ])
                ->sum('amount_paid');

            // selectRaw + value() dipakai (bukan sum(DB::raw(...)))
            // karena bentuk ini paling aman & konsisten hasilnya.
            $sisaBelumBayar = (float) (Invoice::query()
                ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
                ->selectRaw('COALESCE(SUM(amount - amount_paid), 0) AS total')
                ->value('total') ?? 0);

            $jumlahBelumBayar = Invoice::query()
                ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
                ->count();

            $sisaJatuhTempo = (float) (Invoice::query()
                ->where('status', InvoiceStatus::Overdue)
                ->selectRaw('COALESCE(SUM(amount - amount_paid), 0) AS total')
                ->value('total') ?? 0);

            $jumlahJatuhTempo = Invoice::query()
                ->where('status', InvoiceStatus::Overdue)
                ->count();
        } catch (\Throwable $e) {
            $pendapatanHariIni = 0;
            $pendapatanBulanIni = 0;
            $sisaBelumBayar = 0;
            $jumlahBelumBayar = 0;
            $sisaJatuhTempo = 0;
            $jumlahJatuhTempo = 0;
        }

        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');

        return [
            [
                'label' => 'Pendapatan Hari Ini',
                'value' => $rp($pendapatanHariIni),
                'icon' => 'lucide-banknote',
                'color' => '#fb923c',
                'note' => 'Tagihan yang dibayar hari ini',
            ],
            [
                'label' => 'Pendapatan Bulan Ini',
                'value' => $rp($pendapatanBulanIni),
                'icon' => 'lucide-trending-up',
                'color' => '#f97316',
                'note' => $hariIni->format('m/Y'),
            ],
            [
                'label' => 'Belum Bayar',
                'value' => $rp($sisaBelumBayar),
                'icon' => 'lucide-file-text',
                'color' => '#ea580c',
                'note' => number_format($jumlahBelumBayar).' tagihan',
            ],
            [
                'label' => 'Jatuh Tempo',
                'value' => $rp($sisaJatuhTempo),
                'icon' => 'lucide-alert-triangle',
                'color' => '#9a3412',
                'note' => number_format($jumlahJatuhTempo).' tagihan lewat tempo',
            ],
        ];
    }
}
