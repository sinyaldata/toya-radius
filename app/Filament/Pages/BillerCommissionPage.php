<?php

namespace App\Filament\Pages;

use App\Enums\InvoiceStatus;
use App\Enums\PartnerType;
use App\Models\Invoice;
use App\Models\Outlet;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * BARU (permintaan user 2026-09-19: "Bagaimana caranya Biller
 * Mengecek Total Tagihan yang sudah ditagih ke pelanggan, beserta
 * komisinya") -- sebelum ini TIDAK ADA cara buat Biller melihat
 * ringkasan tagihan & komisinya sendiri, walau strukturnya (biller_id
 * di Invoice, komisi per Outlet/per Pelanggan lewat
 * billedOutlets()/billedCustomers() di model Outlet) sudah ada dari
 * sebelumnya -- cuma belum pernah ada yang MENAMPILKANNYA.
 *
 * Halaman ini otomatis membatasi diri ke Biller yang sedang login
 * (lewat mount(), sama pola dengan InvoiceReportPage yang membatasi
 * akun Outlet ke outlet_id miliknya sendiri) -- Biller SATU tidak bisa
 * lihat data Biller LAIN. Admin/Super Admin TETAP bisa buka halaman
 * ini juga (buat verifikasi/keperluan lain), tapi tanpa pembatasan
 * otomatis itu -- lihat mount().
 *
 * KOMISI dihitung dari invoice yang SUDAH LUNAS saja (bukan semua
 * invoice yang diterbitkan) -- komisi cuma masuk akal buat uang yang
 * SUNGGUHAN sudah diterima, bukan tagihan yang masih menggantung.
 * Nominalnya per-invoice: kalau pelanggan invoice itu ada di
 * billedCustomers() (penugasan langsung per-pelanggan), pakai
 * commission_amount dari situ; kalau tidak, jatuh ke commission_amount
 * outlet pelanggan itu lewat billedOutlets() (penugasan level outlet);
 * kalau dua-duanya tidak ada, komisinya 0 (invoice ini ditagih tapi
 * tidak masuk penugasan resmi mana pun -- jarang terjadi, tapi jangan
 * sampai malah error).
 */
class BillerCommissionPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-hand-coins';

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Komisi Biller';

    protected static ?string $title = 'Tagihan & Komisi Saya';

    protected string $view = 'filament.pages.biller-commission-page';

    public ?array $filters = [
        'year' => null,
        'month' => null,
        'biller_id' => null,
    ];

    /**
     * Hanya muncul di menu buat akun Outlet-role yang outlet-nya
     * BERTIPE Biller -- Admin/Super Admin TETAP bisa akses (canAccess()
     * di bawah), cuma tidak perlu lihat menunya sendiri (mereka lebih
     * mungkin lihat lewat BillerResource kalau memang perlu).
     *
     * PENTING (pelajaran dari kejadian sebelumnya di aplikasi ini):
     * dipakai METHOD LANGSUNG (bukan cuma properti
     * $shouldRegisterNavigation) -- properti sendirian pernah terbukti
     * tidak selalu kebaca dengan benar di aplikasi ini untuk alasan
     * yang belum jelas, method langsung di kelas ini dijamin menang
     * apa pun penyebabnya.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        // Admin/Super Admin JUGA lihat menunya sekarang (permintaan
        // user: "admin juga dong dibuatkan biar tau") -- sebelumnya
        // disembunyikan dari mereka, sekarang tetap muncul, cuma
        // form-nya otomatis dapat field tambahan buat pilih Biller
        // (lihat form() di bawah -- itu sudah ada dari awal, tidak
        // berubah).
        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return true;
        }

        return $user->isOutlet() && $user->outlet?->partner_type === PartnerType::Biller;
    }

    /**
     * Siapa pun yang login BOLEH buka halaman ini lewat URL langsung
     * (Admin/Super Admin buat verifikasi, Biller buat dirinya sendiri)
     * -- yang TIDAK boleh cuma akun Outlet/Reseller BIASA (bukan
     * Biller), supaya mereka tidak bisa mengintip data komisi biller
     * lain lewat tebak-tebak URL.
     */
    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return true;
        }

        return $user->isOutlet() && $user->outlet?->partner_type === PartnerType::Biller;
    }

    public function mount(): void
    {
        $this->filters['year'] = (int) now()->year;
        $this->filters['month'] = (int) now()->month;

        $user = Auth::user();

        // Akun Biller: dikunci ke outlet miliknya sendiri, field
        // pemilihan Biller di form TIDAK ditampilkan sama sekali (lihat
        // form() di bawah) -- tidak ada cara buat dia pilih biller lain.
        if ($user?->isOutlet() && $user->outlet?->partner_type === PartnerType::Biller) {
            $this->filters['biller_id'] = $user->outlet_id;
        }

        $this->form->fill($this->filters);
    }

    public function form(Schema $schema): Schema
    {
        $user = Auth::user();
        $adalahAdmin = $user?->isSuperAdmin() || $user?->isAdmin();

        return $schema->components([
            // Cuma Admin/Super Admin yang lihat field pilih Biller ini
            // -- akun Biller sendiri otomatis terkunci ke dirinya
            // sendiri lewat mount(), tidak butuh (dan tidak boleh)
            // pilih biller lain.
            Forms\Components\Select::make('biller_id')
                ->label('Biller')
                ->options(fn () => Outlet::query()
                    ->where('partner_type', PartnerType::Biller)
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->required()
                ->searchable()
                ->native(false)
                ->visible($adalahAdmin),
            Forms\Components\Select::make('year')
                ->label('Tahun')
                ->options(fn () => $this->yearOptions())
                ->required()
                ->native(false),
            Forms\Components\Select::make('month')
                ->label('Bulan')
                ->options([
                    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
                ])
                ->required()
                ->native(false),
        ])->statePath('filters')->columns(3);
    }

    protected function yearOptions(): array
    {
        $years = Invoice::query()->distinct()->pluck('period_year')->map(fn ($y) => (int) $y);

        if (! $years->contains((int) now()->year)) {
            $years->push((int) now()->year);
        }

        return $years->sortDesc()->mapWithKeys(fn ($y) => [$y => (string) $y])->all();
    }

    /**
     * Tombol "Terapkan Filter" -- sama pola dengan InvoiceReportPage,
     * TIDAK live supaya perhitungan tidak jalan berkali-kali tiap satu
     * dropdown disentuh.
     */
    public function terapkanFilter(): void
    {
        $this->filters = array_merge($this->filters, $this->form->getState());
    }

    /**
     * Ringkasan + rincian per-invoice buat Biller & periode yang
     * dipilih. Balik null kalau belum ada biller_id sama sekali
     * (kejadian: Admin buka halaman ini tapi belum pilih Biller-nya).
     */
    public function getRingkasan(): ?array
    {
        $billerId = $this->filters['biller_id'] ?? null;

        if (! $billerId) {
            return null;
        }

        $biller = Outlet::find($billerId);

        if (! $biller) {
            return null;
        }

        $year = (int) ($this->filters['year'] ?? now()->year);
        $month = (int) ($this->filters['month'] ?? now()->month);
        $awalBulan = \Illuminate\Support\Carbon::create($year, $month, 1)->startOfMonth();
        $akhirBulan = $awalBulan->copy()->endOfMonth();

        // Peta komisi per-pelanggan & per-outlet, diambil SEKALI di
        // awal (bukan per-invoice di dalam loop di bawah) -- biar tidak
        // query berulang kali kalau invoice-nya banyak.
        $komisiPerPelanggan = $biller->billedCustomers()->pluck('biller_customer_commissions.commission_amount', 'customers.id');
        $komisiPerOutlet = $biller->billedOutlets()->pluck('biller_outlet_commissions.commission_amount', 'outlets.id');

        // FIX (user melapor: "komisi cuma dihitung dari 1 invoice,
        // padahal seharusnya banyak") -- SEBELUMNYA disaring pakai
        // period_year/period_month (periode TAGIHAN diterbitkan) --
        // itu salah tempat: biller yang menagih di bulan ini bisa saja
        // mengumpulkan invoice dari BEBERAPA periode tagihan berbeda
        // sekaligus (termasuk invoice nunggak dari bulan-bulan
        // sebelumnya yang baru berhasil ditagih bulan ini) -- disaring
        // period_year/period_month cuma menangkap yang PERIODE
        // TAGIHANNYA persis bulan ini, melewatkan semua invoice lain
        // yang justru BARU DIBAYAR bulan ini.
        //
        // Sekarang: invoice yang SUDAH LUNAS disaring dari paid_at
        // (KAPAN BENERAN DIBAYAR, bukan periode tagihannya) yang jatuh
        // di bulan/tahun yang dipilih -- ini yang dimaksud "sudah
        // ditagih" oleh user. Invoice yang MASIH BELUM LUNAS ikut
        // ditampilkan juga (apapun periode tagihannya) supaya biller
        // tetap bisa lihat apa yang masih harus ditagih.
        $invoices = Invoice::query()
            ->where('biller_id', $billerId)
            ->where(function ($q) use ($awalBulan, $akhirBulan, $year, $month) {
                // Lunas: disaring dari KAPAN BENERAN DIBAYAR (paid_at).
                $q->whereBetween('paid_at', [$awalBulan, $akhirBulan])
                    // Belum lunas: tetap disaring dari periode tagihan
                    // (period_year/period_month) seperti semula --
                    // supaya daftarnya tidak numpuk SEMUA invoice
                    // belum-lunas dari kapan pun, cuma yang periode
                    // tagihannya di bulan yang sedang dipilih.
                    ->orWhere(function ($q2) use ($year, $month) {
                        $q2->where('status', '!=', InvoiceStatus::Paid)
                            ->where('period_year', $year)
                            ->where('period_month', $month);
                    });
            })
            ->with('customer:id,name,outlet_id')
            ->orderByDesc('paid_at')
            ->get()
            // Invoice pelanggan yang sudah terhapus (jarang, tapi jaga-
            // jaga) dilewati -- tidak ada nama buat ditampilkan.
            ->filter(fn ($inv) => $inv->customer !== null);

        $totalDiterbitkan = 0.0;
        $totalDibayar = 0.0;
        $totalKomisi = 0.0;
        $jumlahLunas = 0;
        $rincian = [];

        foreach ($invoices as $inv) {
            $totalDiterbitkan += (float) $inv->amount;
            $lunas = $inv->status === InvoiceStatus::Paid;

            $komisi = 0.0;

            if ($lunas) {
                $totalDibayar += (float) $inv->amount;
                $jumlahLunas++;

                $komisi = (float) (
                    $komisiPerPelanggan[$inv->customer->id]
                    ?? $komisiPerOutlet[$inv->customer->outlet_id]
                    ?? 0
                );
                $totalKomisi += $komisi;
            }

            $rincian[] = [
                'invoice' => $inv,
                'pelanggan' => $inv->customer->name,
                'lunas' => $lunas,
                'komisi' => $komisi,
            ];
        }

        return [
            'biller' => $biller,
            'jumlah_invoice' => $invoices->count(),
            'jumlah_lunas' => $jumlahLunas,
            'total_diterbitkan' => $totalDiterbitkan,
            'total_dibayar' => $totalDibayar,
            'total_komisi' => $totalKomisi,
            'rincian' => $rincian,
        ];
    }
}
