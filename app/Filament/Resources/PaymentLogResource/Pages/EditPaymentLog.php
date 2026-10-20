<?php

namespace App\Filament\Resources\PaymentLogResource\Pages;

use App\Filament\Resources\PaymentLogResource;
use Filament\Resources\Pages\EditRecord;

class EditPaymentLog extends EditRecord
{
    protected static string $resource = PaymentLogResource::class;

    // SENGAJA tidak ada DeleteAction di sini — menghapus baris payment_log
    // langsung TIDAK akan mengembalikan status invoice yang tadinya kena
    // alokasi. Pembatalan yang benar HARUS lewat tombol "Batalkan" di
    // tabel (memanggil InvoiceService::undoPayment(), bukan delete biasa).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
