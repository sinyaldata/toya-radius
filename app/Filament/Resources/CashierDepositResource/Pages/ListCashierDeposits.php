<?php

namespace App\Filament\Resources\CashierDepositResource\Pages;

use App\Filament\Resources\CashierDepositResource;
use App\Filament\Resources\CashierDepositResource\Widgets\CashierOverview;
use Filament\Resources\Pages\ListRecords;

class ListCashierDeposits extends ListRecords
{
    protected static string $resource = CashierDepositResource::class;

    // Tombol "Terima Setoran" ada di table()->headerActions() (sejajar
    // dengan Search), mengikuti pola halaman lain di aplikasi ini.

    protected function getHeaderWidgets(): array
    {
        return [
            CashierOverview::class,
        ];
    }
}
