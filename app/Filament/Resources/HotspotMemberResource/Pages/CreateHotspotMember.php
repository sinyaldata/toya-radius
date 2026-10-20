<?php

namespace App\Filament\Resources\HotspotMemberResource\Pages;

use App\Filament\Resources\HotspotMemberResource;
use App\Services\CustomerRadiusService;
use Filament\Resources\Pages\CreateRecord;

class CreateHotspotMember extends CreateRecord
{
    protected static string $resource = HotspotMemberResource::class;

    protected function afterCreate(): void
    {
        app(CustomerRadiusService::class)->provisionCustomer($this->record, $this->record->servicePackage);
    }
}
