<?php

namespace App\Filament\Widgets;

use App\Models\Transaction;
use Filament\Widgets\Widget;

/**
 * Dipisah dari TopListsWidget lama — lihat catatan di InvoiceListWidget.
 */
class VoucherSalesListWidget extends Widget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.voucher-sales-list';

    public int $voucherPage = 1;

    protected int $perPage = 5;

    public function goToVoucherPage(int $page): void
    {
        $this->voucherPage = max(1, $page);
    }

    public function getVoucherCounts(): array
    {
        return [
            'total' => static::baseQuery()->count(),
        ];
    }

    /**
     * Untuk akun OUTLET, OutletScope dibiarkan aktif supaya yang tampil
     * hanya penjualan outlet itu sendiri. Untuk admin/pemilik, scope
     * dilepas supaya melihat semua outlet.
     */
    protected static function baseQuery()
    {
        $query = Transaction::query();

        if (! \Illuminate\Support\Facades\Auth::user()?->isOutlet()) {
            $query->withoutGlobalScope(\App\Models\Scopes\OutletScope::class);
        }

        return $query;
    }

    protected function allVoucherSales()
    {
        return static::baseQuery()
            ->with(['voucher.profile', 'outlet', 'cashier'])
            ->latest('transaction_date')
            ->limit(20)
            ->get();
    }

    public function getVoucherRows()
    {
        return $this->allVoucherSales()->forPage($this->voucherPage, $this->perPage)->values();
    }

    public function getVoucherTotalPages(): int
    {
        return max(1, (int) ceil($this->allVoucherSales()->count() / $this->perPage));
    }
}
