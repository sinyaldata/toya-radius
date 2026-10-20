<?php

namespace App\Enums;

enum CustomerType: string
{
    case Hotspot = 'hotspot';
    case Pppoe = 'pppoe';
    // Pelanggan yang diikat lewat DHCP Static Lease di MikroTik (MAC
    // address -> IP tetap) -- TIDAK login pakai username/password
    // seperti PPPoE, jadi tidak ikut ditulis ke radcheck/radreply.
    // Lihat CustomerRadiusService untuk cara provisioning/isolirnya
    // (lewat MikrotikService, bukan RADIUS).
    case StaticIp = 'static_ip';

    public function label(): string
    {
        return match ($this) {
            self::Hotspot => 'User Hotspot',
            self::Pppoe => 'PPPoE',
            self::StaticIp => 'Static IP (DHCP)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Hotspot => 'info',
            self::Pppoe => 'primary',
            self::StaticIp => 'warning',
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
