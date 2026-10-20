<?php

namespace App\Listeners;

use App\Models\LoginLog;
use Illuminate\Auth\Events\Logout;

class LogSuccessfulLogout
{
    public function handle(Logout $event): void
    {
        if (! $event->user) {
            return;
        }

        // Tandai waktu logout pada baris login terakhir user ini yang belum
        // punya catatan logout, supaya sepasang login-logout bisa ditelusuri.
        LoginLog::where('user_id', $event->user->getAuthIdentifier())
            ->where('successful', true)
            ->whereNull('logged_out_at')
            ->latest('logged_in_at')
            ->limit(1)
            ->update(['logged_out_at' => now()]);
    }
}
