<?php

namespace App\Filament\Resources\HotspotMemberResource\Pages;

use App\Filament\Resources\HotspotMemberResource;
use Filament\Resources\Pages\ListRecords;

class ListHotspotMembers extends ListRecords
{
    protected static string $resource = HotspotMemberResource::class;

    // Tombol Tambah/Export/Import dipindah ke table()->headerActions() di
    // HotspotMemberResource.php — tampil dekat Search di dalam tabel.
}
