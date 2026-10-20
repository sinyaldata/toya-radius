<?php

namespace App\Filament\Resources\PppoeUserResource\Pages;

use App\Filament\Resources\PppoeUserResource;
use App\Services\CustomerRadiusService;
use Filament\Resources\Pages\CreateRecord;

class CreatePppoeUser extends CreateRecord
{
    protected static string $resource = PppoeUserResource::class;

    protected function afterCreate(): void
    {
        app(CustomerRadiusService::class)->provisionCustomer($this->record, $this->record->servicePackage);
    }
}
