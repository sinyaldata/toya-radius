<?php

namespace App\Filament\Resources\CashierDepositResource\Widgets;

use App\Models\CashierDeposit;
use App\Models\Outlet;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Empat kartu ringkasan di halaman Kasir. Dua kartu terakhir menjawab
 * langsung pertanyaan "siapa saja yang sudah menyetorkan uang":
 * berapa mitra yang sudah setor bulan ini, dan berapa yang belum.
 *
 * "Mitra aktif" dipakai sebagai pembagi (bukan semua mitra) supaya
 * mitra lama yang sudah tidak beroperasi tidak selamanya terhitung
 * sebagai "belum setor".
 *
 * Soal lokasi kelas & pollingInterval, lihat catatan lengkap di
 * PppoeUserResource\Widgets\PppoeUserOverview.
 */
class CashierOverview extends Widget
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
        $awalBulan = $hariIni->copy()->startOfMonth();
        $akhirBulan = $hariIni->copy()->endOfMonth();

        try {
            $setoranHariIni = (float) CashierDeposit::whereDate('deposit_date', $hariIni)->sum('amount');

            $setoranBulanIni = (float) CashierDeposit::whereBetween('deposit_date', [$awalBulan, $akhirBulan])
                ->sum('amount');

            // distinct outlet_id = jumlah MITRA yang menyetor, bukan
            // jumlah transaksi (satu mitra bisa menyetor berkali-kali).
            $sudahSetor = CashierDeposit::whereBetween('deposit_date', [$awalBulan, $akhirBulan])
                ->distinct()
                ->count('outlet_id');

            $mitraAktif = Outlet::where('is_active', true)->count();
        } catch (\Throwable $e) {
            // Paling mungkin terjadi kalau migration cashier_deposits
            // belum dijalankan — halaman tetap terbuka, angkanya 0.
            $setoranHariIni = 0;
            $setoranBulanIni = 0;
            $sudahSetor = 0;
            $mitraAktif = 0;
        }

        $belumSetor = max(0, $mitraAktif - $sudahSetor);
        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');

        return [
            [
                'label' => 'Setoran Hari Ini',
                'value' => $rp($setoranHariIni),
                'icon' => 'lucide-hand-coins',
                'color' => '#16a34a',
                'note' => $hariIni->format('d/m/Y'),
            ],
            [
                'label' => 'Setoran Bulan Ini',
                'value' => $rp($setoranBulanIni),
                'icon' => 'lucide-wallet',
                'color' => '#f97316',
                'note' => $hariIni->format('m/Y'),
            ],
            [
                'label' => 'Mitra Sudah Setor',
                'value' => number_format($sudahSetor).' / '.number_format($mitraAktif),
                'icon' => 'lucide-check-circle',
                'color' => '#2563eb',
                'note' => 'Bulan ini',
            ],
            [
                'label' => 'Belum Setor',
                'value' => number_format($belumSetor),
                'icon' => 'lucide-alert-circle',
                'color' => '#dc2626',
                'note' => 'Mitra aktif yang belum menyetor',
            ],
        ];
    }
}
