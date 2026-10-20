<?php

namespace App\Observers;

use App\Models\CashierDeposit;
use App\Services\TelegramAdminNotifier;

/**
 * Begitu kasir mencatat setoran dari outlet, admin langsung diberi tahu
 * lewat Telegram bahwa outlet tersebut sudah bayar (permintaan user).
 *
 * Ditaruh di OBSERVER, bukan di tombol Simpan halaman Kasir, supaya
 * pemberitahuannya ikut jalan dari jalur manapun setoran itu dibuat —
 * form Kasir, impor, perintah artisan, atau apa pun nanti. Kalau
 * ditempel di satu tombol saja, jalur lain diam-diam tidak mengirim
 * apa-apa dan tidak ada yang sadar.
 */
class CashierDepositObserver
{
    public function created(CashierDeposit $deposit): void
    {
        // Jangan sampai gagal kirim notifikasi membuat setoran yang sudah
        // tersimpan ikut gagal/ter-rollback. Uang yang sudah diterima
        // kasir jauh lebih penting daripada pesan Telegram.
        try {
            $this->kirimNotifikasi($deposit);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function kirimNotifikasi(CashierDeposit $deposit): void
    {
        $deposit->loadMissing(['outlet', 'receivedBy']);

        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');

        $namaOutlet = $deposit->outlet?->name ?? 'Mitra tidak dikenal';
        $tanggal = $deposit->deposit_date?->format('d/m/Y') ?? '-';
        $metode = $deposit->payment_method ? ucfirst((string) $deposit->payment_method) : '-';
        $kasir = $deposit->receivedBy?->name
            ?? (str_starts_with((string) $deposit->notes, 'Dicatat via bot Telegram') ? 'via bot Telegram' : '-');

        $teks = "✅ <b>SETORAN DITERIMA — LUNAS</b>\n\n";
        // SETORAN-KASIR-BOT: tulis jenis mitra yang sebenarnya (Outlet / Reseller / Biller).
        $tipeMitra = $deposit->outlet?->partner_type;
        $tipeMitra = $tipeMitra instanceof \BackedEnum ? $tipeMitra->value : (string) $tipeMitra;
        $labelMitra = match ($tipeMitra) {
            'reseller' => 'Reseller',
            'biller' => 'Biller',
            default => 'Outlet',
        };
        $teks .= "{$labelMitra}: <b>{$namaOutlet}</b>\n";
        $teks .= "Jumlah: <b>{$rp($deposit->amount)}</b>\n";
        $teks .= "Tanggal setor: {$tanggal}\n";
        $teks .= "Metode: {$metode}\n";
        $teks .= "Diterima kasir: {$kasir}\n";

        if ($deposit->reference) {
            $teks .= "No. Referensi: <code>{$deposit->reference}</code>\n";
        }

        if ($deposit->notes) {
            $teks .= "Catatan: {$deposit->notes}\n";
        }

        // Total setoran outlet ini pada BULAN setoran (bukan bulan
        // berjalan) — kalau kasir mencatat setoran tanggal mundur, angka
        // yang muncul tetap nyambung dengan tanggal yang tertulis di atas.
        $awal = $deposit->deposit_date?->copy()->startOfMonth();
        $akhir = $deposit->deposit_date?->copy()->endOfMonth();

        if ($awal && $akhir && $deposit->outlet) {
            $totalBulanIni = (float) CashierDeposit::where('outlet_id', $deposit->outlet_id)
                ->whereBetween('deposit_date', [$awal, $akhir])
                ->sum('amount');

            $teks .= "\nTotal setoran {$namaOutlet} bulan {$awal->format('m/Y')}: <b>{$rp($totalBulanIni)}</b>";
        }

        app(TelegramAdminNotifier::class)->kirim($teks);
    }
}
