<?php

namespace App\Filament\Pages;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class ProfitLossReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Laba/Rugi';

    // Halaman ini KHUSUS pemilik/admin — akun outlet tidak boleh melihat
    // (disembunyikan dari menu DAN diblokir kalau URL-nya diakses langsung).
    public static function canAccess(): bool
    {
        return ! \Illuminate\Support\Facades\Auth::user()?->isOutlet();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected static ?string $title = 'Laporan Laba/Rugi';

    protected string $view = 'filament.pages.profit-loss-report-page';

    public ?array $filters = [
        'from' => null,
        'to' => null,
    ];

    public function mount(): void
    {
        $this->filters['from'] = now()->startOfMonth()->toDateString();
        $this->filters['to'] = now()->toDateString();

        $this->form->fill($this->filters);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
            Forms\Components\DatePicker::make('to')->label('Sampai Tanggal'),
        ])->statePath('filters')->columns(2);
    }

    /**
     * Rekap Laba/Rugi:
     * - Pendapatan Penjualan Voucher = total_amount transaksi LUNAS (harga jual ke pembeli)
     * - Modal Voucher Terjual = harga pokok (cost_price) dari paket voucher yg terjual, dari transaksi yg sama
     * - Pendapatan Invoice PPPoE = invoice berstatus Lunas, berdasarkan tanggal DIBAYAR
     * - Komisi Outlet/Reseller = sesuai commission_type outlet itu (Persentase atau Selisih Harga Jual-Beli Voucher), lihat Outlet::hitungKomisi())
     * - Pengeluaran = seluruh Expense pada rentang tanggal (expense_date)
     * - Laba Bersih = (Pendapatan Voucher + Pendapatan Invoice) - Modal Voucher - Komisi Outlet - Pengeluaran
     */
    public function getSummary(): array
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        $transactions = Transaction::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->with(['voucher.profile', 'outlet'])
            ->where('payment_status', PaymentStatus::Lunas)
            ->when($from, fn ($q, $v) => $q->whereDate('transaction_date', '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate('transaction_date', '<=', $v))
            ->get();

        $pendapatanVoucher = $transactions->sum('total_amount');

        $modalVoucher = $transactions->sum(function ($t) {
            return (float) ($t->voucher?->profile?->cost_price ?? 0);
        });

        $komisiOutlet = $transactions->sum(function ($t) {
            if (! $t->outlet) {
                return 0;
            }

            // FIX: pakai method bersama Outlet::hitungKomisi() -- sudah
            // per-transaksi dari awal di sini, jadi tinggal dipakai apa
            // adanya (otomatis pilih rumus percent/flat sesuai
            // commission_type outlet ini).
            return $t->outlet->hitungKomisi((float) $t->total_amount, $t->voucher);
        });

        $pendapatanInvoice = Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->when($from, fn ($q, $v) => $q->whereDate('paid_at', '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate('paid_at', '<=', $v))
            ->sum('amount');

        // Pendapatan di luar voucher & tagihan (pasang baru, jasa, dll) —
        // menu Keuangan > Pendapatan Lain.
        $pendapatanLain = \App\Models\OtherIncome::query()
            ->when($from, fn ($q, $v) => $q->whereDate('income_date', '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate('income_date', '<=', $v))
            ->sum('amount');

        $pengeluaran = Expense::query()
            ->when($from, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->sum('amount');

        $totalPendapatan = $pendapatanVoucher + $pendapatanInvoice + (float) $pendapatanLain;
        $totalBeban = $modalVoucher + $komisiOutlet + $pengeluaran;
        $labaBersih = $totalPendapatan - $totalBeban;

        return [
            'pendapatan_voucher' => $pendapatanVoucher,
            'pendapatan_invoice' => $pendapatanInvoice,
            'pendapatan_lain' => (float) $pendapatanLain,
            'total_pendapatan' => $totalPendapatan,
            'modal_voucher' => $modalVoucher,
            'komisi_outlet' => $komisiOutlet,
            'pengeluaran' => (float) $pengeluaran,
            'total_beban' => $totalBeban,
            'laba_bersih' => $labaBersih,
        ];
    }

    public function getExpenseBreakdown(): \Illuminate\Support\Collection
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        return Expense::query()
            ->when($from, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->get()
            ->groupBy(fn ($e) => $e->category->label())
            ->map(fn ($rows) => $rows->sum('amount'))
            ->sortDesc();
    }
}
