<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Filament\Widgets\Widget;

/**
 * Dipisah dari TopListsWidget lama (dulu 1 widget berisi Invoice + Voucher
 * sekaligus, selalu 1 baris sendiri lebar penuh) — supaya bisa jadi kolom
 * sendiri sejajar dengan Aktivitas Login & Top Voucher di baris ke-3.
 *
 * CATATAN (perbaikan): dulu diurutkan dengan latest() polos, yang berarti
 * urut dari created_at TERBARU. Akibatnya, tagihan LAMA yang baru saja
 * dilunasi (misalnya lewat bot Telegram) tidak pernah naik ke "Top 20" —
 * created_at-nya tetap tanggal lama, jadi kalau ada 20+ invoice yang
 * dibuat setelahnya, pembayaran itu tidak pernah kelihatan di sini sama
 * sekali walaupun sudah tercatat dengan benar di database. Sekarang
 * diurutkan dari updated_at TERBARU — setiap kali invoice disentuh
 * (dibuat, atau dilunasi lewat aplikasi/Telegram), dia naik ke atas.
 *
 * Pil "Paid/Unpaid/Overdue" di kop kartu sekarang jadi tombol filter
 * (permintaan user: sebelumnya cuma teks, tidak bisa diklik) — meng-klik
 * salah satu menyaring tabel ke status itu saja, klik lagi untuk kembali
 * ke "Semua".
 */
class InvoiceListWidget extends Widget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.invoice-list';

    // Invoice/tagihan bulanan bukan urusan outlet — sembunyikan.
    public static function canView(): bool
    {
        return ! \Illuminate\Support\Facades\Auth::user()?->isOutlet();
    }

    public int $invoicePage = 1;

    protected int $perPage = 5;

    /** 'semua' | 'paid' | 'unpaid' | 'overdue' */
    public string $invoiceFilter = 'semua';

    public function goToInvoicePage(int $page): void
    {
        $this->invoicePage = max(1, $page);
    }

    /**
     * Klik pil Paid/Unpaid/Overdue: kalau pil yang sama diklik lagi,
     * kembali ke "Semua" (toggle) — supaya tidak butuh tombol reset
     * terpisah.
     */
    public function setInvoiceFilter(string $filter): void
    {
        $this->invoiceFilter = $this->invoiceFilter === $filter ? 'semua' : $filter;
        $this->invoicePage = 1;
    }

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
        $status = match ($this->invoiceFilter) {
            'paid' => InvoiceStatus::Paid,
            'unpaid' => InvoiceStatus::Unpaid,
            'overdue' => InvoiceStatus::Overdue,
            default => null,
        };

        return Invoice::query()
            ->with(['customer.outlet'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('updated_at')
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
}
