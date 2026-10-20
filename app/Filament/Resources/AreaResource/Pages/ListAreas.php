<?php

namespace App\Filament\Resources\AreaResource\Pages;

use App\Filament\Resources\AreaResource;
use Filament\Resources\Pages\ListRecords;

class ListAreas extends ListRecords
{
    protected static string $resource = AreaResource::class;

    // Tombol "Tambah Area" ada di table()->headerActions() (dekat Search),
    // mengikuti pola yang sama dengan OutletResource.
}
