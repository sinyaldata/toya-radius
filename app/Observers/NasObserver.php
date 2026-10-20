<?php

namespace App\Observers;

use App\Models\Nas;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * FreeRADIUS cuma baca daftar NAS (client) sekali waktu dia NYALA —
 * perubahan di tabel `nas` (tambah/ubah/hapus) TIDAK otomatis
 * dikenali sampai di-restart. Supaya admin/tenant tidak perlu restart
 * manual tiap kali bikin NAS baru, restart dilakukan OTOMATIS di sini.
 *
 * Restart FreeRADIUS makan waktu 1-3 detik dan sempat memutus SEMUA
 * sesi yang lagi aktif SEBENTAR (klien akan otomatis reconnect) — bisa
 * diterima karena bikin/ubah NAS itu aksi admin yang jarang terjadi,
 * bukan hal yang dilakukan tiap menit.
 */
class NasObserver
{
    public function created(Nas $nas): void
    {
        $this->restartFreeRadius();
    }

    public function updated(Nas $nas): void
    {
        $this->restartFreeRadius();
    }

    public function deleted(Nas $nas): void
    {
        $this->restartFreeRadius();
    }

    protected function restartFreeRadius(): void
    {
        try {
            $result = Process::run('sudo /bin/systemctl restart freeradius');

            if (! $result->successful()) {
                Log::warning('Gagal restart FreeRADIUS otomatis setelah perubahan NAS', [
                    'error' => $result->errorOutput(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal restart FreeRADIUS otomatis setelah perubahan NAS', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
