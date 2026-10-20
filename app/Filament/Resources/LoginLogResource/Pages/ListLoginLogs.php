<?php

namespace App\Filament\Resources\LoginLogResource\Pages;

use App\Filament\Resources\LoginLogResource;
use Filament\Resources\Pages\ListRecords;

class ListLoginLogs extends ListRecords
{
    protected static string $resource = LoginLogResource::class;
}
