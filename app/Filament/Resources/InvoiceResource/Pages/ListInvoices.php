<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Widgets\InvoiceOverview;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    // Tombol "Generate Tagihan Bulanan" & "Cek Overdue" DIPINDAH ke
    // table()->headerActions() di InvoiceResource.php — sekarang sejajar
    // dengan Search, sama seperti pola tombol di halaman lain.

    /**
     * Empat kartu ringkasan (Pendapatan Hari Ini / Bulan Ini / Belum
     * Bayar / Jatuh Tempo) di ATAS tabel.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            InvoiceOverview::class,
        ];
    }
}
