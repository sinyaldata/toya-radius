<?php

namespace App\Enums;

enum RouterConnectionStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Terhubung',
            self::Disconnected => 'Terputus',
            self::Unknown => 'Belum Diketahui',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::Disconnected => 'danger',
            self::Unknown => 'gray',
        };
    }
}
