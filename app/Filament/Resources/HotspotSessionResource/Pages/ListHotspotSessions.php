<?php

namespace App\Filament\Resources\HotspotSessionResource\Pages;

use App\Filament\Resources\HotspotSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListHotspotSessions extends ListRecords
{
    protected static string $resource = HotspotSessionResource::class;

    protected function getHeaderActions(): array
    {
        // Tombol "Bersihkan Data Offline" DIPINDAH ke table()->headerActions()
        // di HotspotSessionResource.php — sekarang sejajar dengan Search,
        // bukan di sini lagi.
        return [];
    }
}
