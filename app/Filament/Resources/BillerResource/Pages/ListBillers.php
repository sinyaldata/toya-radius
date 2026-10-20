<?php

namespace App\Filament\Resources\BillerResource\Pages;

use App\Filament\Resources\BillerResource;
use Filament\Resources\Pages\ListRecords;

class ListBillers extends ListRecords
{
    protected static string $resource = BillerResource::class;

    // Tombol Tambah Biller dipindah ke table()->headerActions() di
    // BillerResource.php — tampil dekat Search di dalam tabel.
}
