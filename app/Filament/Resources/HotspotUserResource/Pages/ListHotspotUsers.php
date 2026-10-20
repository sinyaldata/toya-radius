<?php

namespace App\Filament\Resources\HotspotUserResource\Pages;

use App\Filament\Resources\HotspotUserResource;
use App\Filament\Resources\HotspotUserResource\Widgets\HotspotUserOverview;
use Filament\Resources\Pages\ListRecords;

class ListHotspotUsers extends ListRecords
{
    protected static string $resource = HotspotUserResource::class;

    // SELALU-20: setiap kali halaman Voucher dibuka mulai dari 20 baris per
    // halaman; pilihan 100/1000 sebelumnya tidak diingat (supaya tidak berat).
    public function getDefaultTableRecordsPerPageSelectOption(): int|string
    {
        session()->forget($this->getTablePerPageSessionKey());

        return 20;
    }

    // Tombol Tambah/Print/Export/Import/Generate SEMUA dipindah ke dalam
    // table()->headerActions() di HotspotUserResource.php — tampil dekat
    // kotak Search di dalam kartu tabel, bukan di baris atas dekat heading.

    protected function getHeaderWidgets(): array
    {
        return [
            HotspotUserOverview::class,
        ];
    }
}
