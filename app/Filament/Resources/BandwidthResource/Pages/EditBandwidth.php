<?php

namespace App\Filament\Resources\BandwidthResource\Pages;

use App\Filament\Resources\BandwidthResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBandwidth extends EditRecord
{
    protected static string $resource = BandwidthResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->iconButton()];
    }
}
