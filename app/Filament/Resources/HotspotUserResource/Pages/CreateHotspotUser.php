<?php

namespace App\Filament\Resources\HotspotUserResource\Pages;

use App\Filament\Resources\HotspotUserResource;
use App\Services\FreeRadiusService;
use Filament\Resources\Pages\CreateRecord;

class CreateHotspotUser extends CreateRecord
{
    protected static string $resource = HotspotUserResource::class;

    protected function afterCreate(): void
    {
        // Dorong ke tabel radius supaya user langsung bisa dipakai login
        if ($this->record->profile) {
            app(FreeRadiusService::class)->provisionVoucher($this->record, $this->record->profile);
        }
    }
}
