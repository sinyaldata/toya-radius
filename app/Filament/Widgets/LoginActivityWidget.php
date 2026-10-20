<?php

namespace App\Filament\Widgets;

use App\Models\LoginLog;
use Filament\Widgets\Widget;

class LoginActivityWidget extends Widget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.login-activity-widget';

    public function getHeading(): string
    {
        return 'Aktivitas Login Pengguna';
    }

    public function getRows()
    {
        // Query tunggal dengan eager load relasi user — bukan N+1.
        $user = \Illuminate\Support\Facades\Auth::user();

        return LoginLog::query()
            ->with('user:id,name')
            // Akun outlet hanya melihat riwayat login dirinya sendiri,
            // bukan log pengguna lain.
            ->when($user?->isOutlet(), fn ($q) => $q->where('user_id', $user->id))
            ->latest('logged_in_at')
            ->limit(6)
            ->get();
    }
}
