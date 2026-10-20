<?php

namespace App\Filament\Resources\ResellerResource\Pages;

use App\Filament\Resources\ResellerResource;
use Filament\Resources\Pages\ListRecords;

class ListResellers extends ListRecords
{
    protected static string $resource = ResellerResource::class;

    // Tombol Tambah Reseller dipindah ke table()->headerActions() di
    // ResellerResource.php — tampil dekat Search di dalam tabel.
}
