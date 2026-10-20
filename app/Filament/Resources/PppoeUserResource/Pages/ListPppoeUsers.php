<?php

namespace App\Filament\Resources\PppoeUserResource\Pages;

use App\Filament\Resources\PppoeUserResource;
use App\Filament\Resources\PppoeUserResource\Widgets\PppoeUserOverview;
use Filament\Resources\Pages\ListRecords;

class ListPppoeUsers extends ListRecords
{
    protected static string $resource = PppoeUserResource::class;

    // Tombol Tambah/Export/Import dipindah ke table()->headerActions() di
    // PppoeUserResource.php — tampil dekat Search di dalam tabel.

    /**
     * Dua kartu (Sesi Online & Suspended) di ATAS tabel.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            PppoeUserOverview::class,
        ];
    }
}
