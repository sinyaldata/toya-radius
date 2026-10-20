<?php

namespace App\Filament\Resources\HotspotUserResource\Pages;

use App\Filament\Resources\HotspotUserResource;
use App\Services\FreeRadiusService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHotspotUser extends EditRecord
{
    protected static string $resource = HotspotUserResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->iconButton()];
    }

    protected function afterSave(): void
    {
        // Sinkronkan ulang ke radius kalau username/password/profile berubah
        if ($this->record->profile) {
            app(FreeRadiusService::class)->provisionVoucher($this->record, $this->record->profile);
        }
    }
}
