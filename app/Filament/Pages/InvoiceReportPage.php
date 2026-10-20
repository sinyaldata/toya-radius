<?php

namespace App\Filament\Pages;

use App\Enums\InvoiceStatus;
use App\Exports\InvoiceReportExport;
use App\Models\Invoice;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class InvoiceReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Invoice';

    protected static ?string $title = 'Laporan Invoice';

    protected string $view = 'filament.pages.invoice-report-page';

    // outlet_id/area_id sengaja masih disimpan di state (dipakai buat
    // pembatasan otomatis akun Outlet lewat mount() di bawah + global
    // scope OutletScope pada model Customer) walau field-nya sudah
    // tidak ditampilkan lagi di form filter (permintaan user: filter
    // yang tampil cuma Tahun).
    public ?array $filters = [
        'year' => null,
        'outlet_id' => null,
        'area_id' => null,
    ];

    /**
     * Label bulan singkat 1-12 -- dipakai di sini (header tabel) dan di
     * Blade view lewat static::bulanLabels().
     */
    protected static array $bulanLabel = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    public static function bulanLabels(): array
    {
        return static::$bulanLabel;
    }

    public function mount(): void
    {
        $this->filters['year'] = (int) now()->year;

        if (Auth::user()?->isOutlet()) {
            $this->filters['outlet_id'] = Auth::user()->outlet_id;
        }

        $this->form->fill($this->filters);
    }

    public function form(Schema $schema): Schema
    {
        // Filter yang ditampilkan cuma Tahun (permintaan user, biar
        // ringkas) -- outlet_id/area_id tidak lagi ada field-nya di
        // sini, tapi TETAP dipertahankan sebagai bagian dari
        // pembatasan otomatis akun Outlet (lihat mount() & komentar di
        // properti $filters).
        return $schema->components([
            Forms\Components\Select::make('year')
                ->label('Tahun')
                ->options(fn () => $this->yearOptions())
                ->required()
                ->native(false),
        ])->statePath('filters')->columns(1);
    }

    /**
     * Daftar tahun buat dropdown filter -- diambil dari tahun-tahun yang
     * benar-benar ada invoice-nya (biar tidak ada opsi tahun kosong yang
     * membingungkan), tapi tahun berjalan SELALU disertakan walau belum
     * ada satu invoice pun tahun ini.
     */
    protected function yearOptions(): array
    {
        $years = Invoice::query()->distinct()->pluck('period_year')->map(fn ($y) => (int) $y);

        if (! $years->contains((int) now()->year)) {
            $years->push((int) now()->year);
        }

        return $years->sortDesc()->mapWithKeys(fn ($y) => [$y => (string) $y])->all();
    }

    /**
     * Tombol "Terapkan Filter". Field filter di halaman ini TIDAK live
     * (sengaja, sama seperti laporan lain) supaya matriks 12 bulan tidak
     * dihitung ulang tiap kali satu dropdown disentuh.
     */
    public function terapkanFilter(): void
    {
        $this->filters = array_merge($this->filters, $this->form->getState());
    }

    /**
     * Bangun matriks PELANGGAN x BULAN (1-12) untuk tahun yang dipilih.
     *
     * Baris yang dimunculkan HANYA pelanggan yang punya setidaknya satu
     * invoice LUNAS di tahun ini (bukan semua pelanggan aktif) -- supaya
     * laporan tidak penuh baris kosong buat pelanggan yang belum pernah
     * bayar tahun ini.
     *
     * Tiap sel diisi NOMINAL yang sudah dibayar (amount_paid) invoice
     * bulan itu KALAU statusnya Lunas -- kosong (null) kalau belum
     * bayar/invoice bulan itu belum pernah dibuat.
     *
     * PEMBAYARAN DIMUKA (borongan banyak bulan sekaligus) otomatis
     * tertangani lewat mekanisme credit_balance yang SUDAH ADA di
     * InvoiceService::recordPayment() + generateMonthlyInvoices() --
     * kelebihan bayar disimpan di pelanggan, lalu OTOMATIS melunasi
     * invoice-invoice bulan berikutnya begitu invoice bulan itu dibuat
     * (setiap tanggal 1). Jadi begitu bulan itu tiba dan invoice-nya
     * tercipta+lunas otomatis, kolom bulan itu di laporan ini akan
     * TERISI SENDIRI tanpa perlu logika tambahan apa pun di sini --
     * laporan ini murni membaca hasil akhirnya dari tabel invoices.
     */
    public function getMatrix(): \Illuminate\Support\Collection
    {
        $year = (int) ($this->filters['year'] ?? now()->year);

        $invoices = Invoice::query()
            ->with(['customer.outlet'])
            ->where('period_year', $year)
            ->where('status', InvoiceStatus::Paid)
            ->whereHas('customer', function ($q) {
                $q->when(
                    $this->filters['outlet_id'] ?? null,
                    fn ($cq, $v) => $cq->where('outlet_id', $v)
                )->when(
                    $this->filters['area_id'] ?? null,
                    fn ($cq, $v) => $cq->whereHas('outlet', fn ($oq) => $oq->where('area_id', $v))
                );
            })
            ->get()
            // Pelanggan yang sudah dihapus tapi invoice lamanya masih ada
            // (jarang, tapi jaga-jaga) tidak punya relasi customer lagi --
            // lewati saja baris itu daripada bikin error null->name.
            ->filter(fn ($inv) => $inv->customer !== null);

        return $invoices
            ->groupBy('customer_id')
            ->map(function ($rows) {
                $customer = $rows->first()->customer;
                $bulanan = array_fill(1, 12, null);

                foreach ($rows as $inv) {
                    $bulanan[(int) $inv->period_month] = (float) $inv->amount_paid;
                }

                return [
                    'customer' => $customer,
                    'bulanan' => $bulanan,
                    'total' => array_sum(array_filter($bulanan, fn ($v) => $v !== null)),
                ];
            })
            ->sortBy(fn ($row) => $row['customer']->name)
            ->values();
    }

    /**
     * Total per kolom bulan (baris paling bawah tabel) + grand total
     * setahun -- dihitung dari matriks yang sama supaya selalu konsisten
     * dengan apa yang ditampilkan di tabel.
     */
    public function getMonthlyTotals(): array
    {
        $totals = array_fill(1, 12, 0.0);

        foreach ($this->getMatrix() as $row) {
            foreach ($row['bulanan'] as $bulan => $nominal) {
                if ($nominal !== null) {
                    $totals[$bulan] += (float) $nominal;
                }
            }
        }

        return $totals;
    }

    public function getGrandTotal(): float
    {
        return array_sum($this->getMonthlyTotals());
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export_excel')
                ->label('Export Excel')
                ->icon('lucide-file-spreadsheet')
                ->color('success')
                ->action(fn () => Excel::download(
                    new InvoiceReportExport($this->getMatrix(), (int) ($this->filters['year'] ?? now()->year)),
                    'laporan-invoice-'.($this->filters['year'] ?? now()->year).'.xlsx'
                )),

            Actions\Action::make('export_pdf')
                ->label('Export PDF')
                ->icon('lucide-file')
                ->color('danger')
                ->action(function () {
                    $tahun = (int) ($this->filters['year'] ?? now()->year);

                    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.invoice-report-pdf', [
                        'matrix' => $this->getMatrix(),
                        'bulanLabel' => static::bulanLabels(),
                        'monthlyTotals' => $this->getMonthlyTotals(),
                        'grandTotal' => $this->getGrandTotal(),
                        'tahun' => $tahun,
                    ])->setPaper('a4', 'landscape');

                    return response()->streamDownload(
                        fn () => print ($pdf->output()),
                        "laporan-invoice-{$tahun}.pdf"
                    );
                }),
        ];
    }
}
