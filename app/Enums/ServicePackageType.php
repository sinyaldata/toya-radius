<?php

namespace App\Enums;

enum ServicePackageType: string
{
    case Hotspot = 'hotspot';
    case Pppoe = 'pppoe';
    case Both = 'both';
    // Paket khusus pelanggan Static IP (DHCP Lease MikroTik) -- beda
    // dari Pppoe/Hotspot/Both karena TIDAK dipasang sebagai PPP/Hotspot
    // Profile, cuma dipakai rate-limit-nya untuk Simple Queue per IP.
    case StaticIp = 'static_ip';

    public function label(): string
    {
        return match ($this) {
            self::Hotspot => 'Hotspot',
            self::Pppoe => 'PPPoE',
            self::Both => 'Hotspot & PPPoE',
            self::StaticIp => 'Static IP (DHCP)',
        };
    }

    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $c) => $c->value, self::cases()),
            array_map(fn (self $c) => $c->label(), self::cases()),
        );
    }
}
