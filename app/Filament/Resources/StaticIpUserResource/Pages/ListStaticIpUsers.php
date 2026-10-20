<?php

namespace App\Filament\Resources\StaticIpUserResource\Pages;

use App\Filament\Resources\StaticIpUserResource;
use Filament\Resources\Pages\ListRecords;

class ListStaticIpUsers extends ListRecords
{
    protected static string $resource = StaticIpUserResource::class;
}
