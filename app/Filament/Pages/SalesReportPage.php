<?php

namespace App\Filament\Pages;

use App\Exports\SalesReportExport;
use App\Models\Outlet;
use App\Models\User;
use App\Services\ReportService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class SalesReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Penjualan';

    protected static ?string $title = 'Laporan Penjualan';

    protected string $view = 'filament.pages.sales-report-page';

    public ?array $filters = [
        'from' => null,
        'to' => null,
        'outlet_id' => null,
        'area_id' => null,
        'bulan' => null,
        'tahun' => null,
    ];

    public function mount(): void
    {
        $this->filters['from'] = now()->startOfMonth()->toDateString();
        $this->filters['to'] = now()->toDateString();
        $this->filters['bulan'] = (int) now()->month;
        $this->filters['tahun'] = (int) now()->year;

        if (Auth::user()?->isOutlet()) {
            $this->filters['outlet_id'] = Auth::user()->outlet_id;
        }

        $this->form->fill($this->filters);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            // Pilihan cepat Bulan/Tahun — begitu dipilih, "Dari/Sampai
            // Tanggal" di bawah otomatis mengikuti rentang bulan itu.
            Forms\Components\Select::make('bulan')
                ->label('Bulan')
                ->options([
                    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
                ])
                ->prefixIcon('lucide-calendar-days')
                ->native(false)
                ->live()
                ->afterStateUpdated(fn (callable $set, callable $get) => static::terapkanBulanTahun($set, $get)),
            Forms\Components\Select::make('tahun')
                ->label('Tahun')
                ->options(fn () => collect(range((int) now()->year, (int) now()->year - 5))
                    ->mapWithKeys(fn ($t) => [$t => $t]))
                ->prefixIcon('lucide-calendar')
                ->native(false)
                ->live()
                ->afterStateUpdated(fn (callable $set, callable $get) => static::terapkanBulanTahun($set, $get)),
            Forms\Components\Select::make('outlet_id')
                ->label('Outlet')
                ->placeholder('Semua outlet')
                ->options(fn () => Outlet::pluck('name', 'id'))
                ->prefixIcon('lucide-store')
                ->columnSpan(['default' => 2, 'lg' => 1])
                ->disabled(fn () => Auth::user()?->isOutlet())
                ->searchable(),
            // Saring per AREA/WILAYAH (master datanya di menu
            // Mitra > Area / Wilayah). Kalau Outlet juga dipilih,
            // keduanya berlaku bersamaan.
            Forms\Components\Select::make('area_id')
                ->label('Area / Wilayah')
                ->placeholder('Semua area')
                ->options(fn () => \App\Models\Area::orderBy('name')->pluck('name', 'id'))
                ->prefixIcon('lucide-map-pin')
                ->columnSpan(['default' => 2, 'lg' => 1])
                ->disabled(fn () => Auth::user()?->isOutlet())
                ->searchable()
                ->preload(),
            Forms\Components\DatePicker::make('from')->label('Dari Tanggal')->prefixIcon('lucide-calendar'),
            Forms\Components\DatePicker::make('to')->label('Sampai Tanggal')->prefixIcon('lucide-calendar'),
        ])->statePath('filters')->columns(['default' => 2, 'lg' => 3]);
    }

    /**
     * Isi otomatis rentang "Dari/Sampai Tanggal" dari pilihan Bulan &
     * Tahun. Untuk bulan berjalan, batas akhirnya hari ini (bukan akhir
     * bulan) supaya tidak menampilkan rentang tanggal yang belum terjadi.
     */
    protected static function terapkanBulanTahun(callable $set, callable $get): void
    {
        $bulan = (int) ($get('bulan') ?: now()->month);
        $tahun = (int) ($get('tahun') ?: now()->year);

        $awal = \Carbon\Carbon::create($tahun, $bulan, 1)->startOfMonth();
        $akhir = $awal->copy()->endOfMonth();

        if ($akhir->isFuture()) {
            $akhir = now();
        }

        $set('from', $awal->toDateString());
        $set('to', $akhir->toDateString());
    }

    /**
     * Tombol "Terapkan Filter". Field filter di halaman ini TIDAK
     * live (sengaja, supaya laporan tidak dihitung ulang tiap kali
     * satu dropdown disentuh) -- jadi perubahan Outlet/Area/Tanggal
     * baru benar-benar dipakai setelah tombol ini diklik. Livewire
     * otomatis mengirim semua isian yang berubah bersama klik ini,
     * lalu $this->filters dipakai ulang untuk menghitung laporan.
     */
    public function terapkanFilter(): void
    {
        $this->filters = array_merge($this->filters, $this->form->getState());

        // Balik ke halaman 1 supaya hasil filter baru tidak "kosong"
        // gara-gara masih berada di halaman ke-sekian dari data lama.
        // Dijaga method_exists() karena trait pagination Livewire tidak
        // selalu ikut ter-load di halaman Filament biasa.
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    public function getRows()
    {
        return app(ReportService::class)->query($this->filters)->paginate(25);
    }

    public function getSummary(): array
    {
        return app(ReportService::class)->summarize($this->filters);
    }

    /**
     * LAPORAN-PENJUALAN-V2: ringkasan periode SEBELUMNYA dengan panjang hari
     * yang sama (mis. 1-17 Okt dibanding 14-30 Sep), dipakai untuk persen
     * naik/turun di kartu ringkasan. null kalau tanggal belum lengkap.
     */
    public function getSummarySebelumnya(): ?array
    {
        $dari = $this->filters['from'] ?? null;
        $sampai = $this->filters['to'] ?? null;

        if (blank($dari) || blank($sampai)) {
            return null;
        }

        try {
            $awal = \Carbon\Carbon::parse($dari)->startOfDay();
            $akhir = \Carbon\Carbon::parse($sampai)->startOfDay();

            if ($akhir->lt($awal)) {
                return null;
            }

            $hari = $awal->diffInDays($akhir) + 1;
            $akhirLalu = $awal->copy()->subDay();
            $awalLalu = $akhirLalu->copy()->subDays($hari - 1);

            $filterLalu = array_merge($this->filters, [
                'from' => $awalLalu->toDateString(),
                'to' => $akhirLalu->toDateString(),
                'bulan' => (int) $awalLalu->month,
                'tahun' => (int) $awalLalu->year,
            ]);

            return [
                'from' => $filterLalu['from'],
                'to' => $filterLalu['to'],
                'summary' => app(ReportService::class)->summarize($filterLalu),
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function getOutletBreakdown()
    {
        return app(ReportService::class)->outletBreakdown($this->filters);
    }

    public function getInvoiceSummary(): array
    {
        return app(ReportService::class)->invoiceSummary($this->filters);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print')
                ->label('Cetak')
                ->icon('lucide-printer')
                ->color('primary')
                ->url(fn () => route('laporan.penjualan.print', array_filter($this->filters)))
                ->openUrlInNewTab(),

            Actions\Action::make('export_excel')
                ->label('Export Excel')
                ->icon('lucide-columns-3')
                ->color('primary')
                ->action(fn () => Excel::download(new SalesReportExport($this->filters), 'laporan-penjualan.xlsx')),

            Actions\Action::make('export_pdf')
                ->label('Export PDF')
                ->icon('lucide-file')
                ->color('primary')
                ->action(function () {
                    $rows = app(ReportService::class)->query($this->filters)->get();
                    $summary = $this->getSummary();

                    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.sales-report-pdf', [
                        'rows' => $rows,
                        'summary' => $summary,
                        'filters' => $this->filters,
                    ]);

                    return response()->streamDownload(
                        fn () => print ($pdf->output()),
                        'laporan-penjualan.pdf'
                    );
                }),
        ];
    }
}
