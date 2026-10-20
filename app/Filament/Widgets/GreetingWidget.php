<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class GreetingWidget extends Widget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.greeting-widget';

    public function getGreeting(): string
    {
        $hour = (int) now(config('app.timezone', 'Asia/Jakarta'))->format('H');

        return match (true) {
            $hour >= 4 && $hour < 11 => 'Selamat Pagi',
            $hour >= 11 && $hour < 15 => 'Selamat Siang',
            $hour >= 15 && $hour < 19 => 'Selamat Sore',
            default => 'Selamat Malam',
        };
    }

    public function getUserFirstName(): string
    {
        $name = auth()->user()?->name ?? 'Pengguna';

        return trim(explode(' ', $name)[0]);
    }

    public function getDateLabel(): string
    {
        return now(config('app.timezone', 'Asia/Jakarta'))
            ->translatedFormat('l, d F Y');
    }
}
