<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;

class Dashboard extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-layout-dashboard';

    // Hilangkan tulisan "Dashboard" — widget Sapaan (Selamat Pagi/Siang/dst)
    // di baris paling atas sudah cukup jadi penanda halaman ini.
    public function getTitle(): string
    {
        return '';
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function getColumns(): int|array
    {
        // 1 kolom di HP, 2 di tablet, 3 di layar besar — supaya Grafik
        // Pendapatan, Performa Outlet, dan Top Pelanggan PPPoE sejajar
        // dalam satu baris di desktop, muat dalam 1 layar.
        return [
            'default' => 1,
            'md' => 2,
            'lg' => 3,
        ];
    }
}
