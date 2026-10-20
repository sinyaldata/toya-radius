<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Transaction;
use Filament\Widgets\Widget;

class TopListsWidget extends Widget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.top-lists-widget';

    public int $invoicePage = 1;

    public int $voucherPage = 1;

    protected int $perPage = 5;

    public function goToInvoicePage(int $page): void
    {
        $this->invoicePage = max(1, $page);
    }

    public function goToVoucherPage(int $page): void
    {
        $this->voucherPage = max(1, $page);
    }

    // ===== Invoice =====

    public function getInvoiceCounts(): array
    {
        return [
            'paid' => Invoice::where('status', InvoiceStatus::Paid)->count(),
            'unpaid' => Invoice::where('status', InvoiceStatus::Unpaid)->count(),
            'overdue' => Invoice::where('status', InvoiceStatus::Overdue)->count(),
        ];
    }

    protected function allInvoices()
    {
        return Invoice::query()
            ->with(['customer.outlet'])
            ->latest()
            ->limit(20)
            ->get();
    }

    public function getInvoiceRows()
    {
        return $this->allInvoices()->forPage($this->invoicePage, $this->perPage)->values();
    }

    public function getInvoiceTotalPages(): int
    {
        return max(1, (int) ceil($this->allInvoices()->count() / $this->perPage));
    }

    // ===== Voucher Sales =====

    public function getVoucherCounts(): array
    {
        return [
            'total' => Transaction::query()->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->count(),
        ];
    }

    protected function allVoucherSales()
    {
        return Transaction::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
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
