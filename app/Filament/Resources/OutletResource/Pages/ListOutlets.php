<?php

namespace App\Filament\Resources\OutletResource\Pages;

use App\Filament\Resources\OutletResource;
use Filament\Resources\Pages\ListRecords;

class ListOutlets extends ListRecords
{
    protected static string $resource = OutletResource::class;

    // Tombol Tambah Outlet dipindah ke table()->headerActions() di
    // OutletResource.php — tampil dekat Search di dalam tabel.
}
