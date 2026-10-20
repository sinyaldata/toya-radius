<?php

namespace App\Services;

use App\Enums\VoucherStatus;

/**
 * HAPUS-PENJUALAN -- perintah bot Telegram:
 *   /hapusjual KODEVOUCHER  -> tampilkan rincian + tombol konfirmasi
 * Hanya Chat ID yang terdaftar sebagai SUPER ADMIN (php artisan
 * toya:superadmin-bot) yang boleh memakai perintah ini.
 */
class HapusPenjualanBot
{
    protected static function tanda(int $voucherId, string $chatId): string
    {
        return substr(hash_hmac('sha256', $voucherId.'|'.$chatId, (string) config('app.key')), 0, 12);
    }

    protected static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    public static function perintah(string $chatId, string $kode, TelegramService $telegram): void
    {
        if (! HapusPenjualan::chatAdalahSuperAdmin($chatId)) {
            $telegram->sendMessage($chatId, "⛔ Perintah ini khusus <b>Super Admin</b>.\n\nChat ID kamu: <code>{$chatId}</code>\nMinta pengelola server mendaftarkannya dengan:\n<code>php artisan toya:superadmin-bot {$chatId}</code>");

            return;
        }

        $kode = trim($kode);

        if ($kode === '') {
            $telegram->sendMessage($chatId, "Ketik bersama kode vouchernya.\n\nContoh: <code>/hapusjual ABC123</code>\n\n⚠️ Penjualan & vouchernya dihapus PERMANEN dan tidak masuk laporan lagi.");

            return;
        }

        $voucher = HapusPenjualan::cariVoucher($kode);

        if (! $voucher) {
            $telegram->sendMessage($chatId, 'Voucher <code>'.static::e($kode).'</code> tidak ditemukan.');

            return;
        }

        $trx = HapusPenjualan::transaksiVoucher($voucher);

        if ($trx->isEmpty() && $voucher->status === VoucherStatus::BelumTerjual) {
            $telegram->sendMessage($chatId, 'Voucher <code>'.static::e($voucher->username).'</code> belum pernah terjual — tidak ada penjualan yang perlu dihapus.');

            return;
        }

        $status = $voucher->status instanceof VoucherStatus ? $voucher->status->label() : (string) $voucher->status;
        $terjual = $voucher->sold_at ? \Illuminate\Support\Carbon::parse($voucher->sold_at)->format('d/m/Y H:i') : '-';
        $baris = [
            '🗑 <b>Hapus penjualan PERMANEN?</b>',
            '',
            'Voucher : <code>'.static::e($voucher->username).'</code>',
            'Paket   : '.static::e($voucher->profile?->name ?? '-'),
            'Outlet  : '.static::e($voucher->outlet?->name ?? 'Stok Pusat'),
            'Status  : '.static::e($status),
            'Terjual : '.$terjual,
            'Transaksi: '.($trx->isEmpty() ? '-' : static::e($trx->pluck('transaction_number')->implode(', '))),
            'Nilai   : <b>'.HapusPenjualan::rupiah((float) $trx->sum('total_amount')).'</b>',
            '',
            '⚠️ Transaksi & voucher dihapus total, tidak masuk Laporan Penjualan lagi, dan TIDAK BISA dikembalikan. Saldo outlet tidak diubah.',
        ];

        $telegram->sendMessage($chatId, implode("\n", $baris), [
            'inline_keyboard' => [[
                ['text' => '🗑 Ya, hapus permanen', 'callback_data' => 'hpsjual:'.$voucher->id.':'.static::tanda($voucher->id, $chatId)],
                ['text' => 'Batal', 'callback_data' => 'hpsjual_batal'],
            ]],
        ]);
    }

    public static function callback(string $chatId, int $messageId, string $data, TelegramService $telegram): void
    {
        if ($data === 'hpsjual_batal') {
            $telegram->editMessageText($chatId, $messageId, 'Dibatalkan — tidak ada yang dihapus.');

            return;
        }

        if (! HapusPenjualan::chatAdalahSuperAdmin($chatId)) {
            $telegram->editMessageText($chatId, $messageId, '⛔ Perintah ini khusus Super Admin.');

            return;
        }

        $bagian = explode(':', $data);
        $id = (int) ($bagian[1] ?? 0);
        $tanda = (string) ($bagian[2] ?? '');

        if ($id <= 0 || ! hash_equals(static::tanda($id, $chatId), $tanda)) {
            $telegram->editMessageText($chatId, $messageId, 'Tombol tidak valid. Ulangi dengan /hapusjual KODE.');

            return;
        }

        $voucher = \App\Models\Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->find($id);

        if (! $voucher) {
            $telegram->editMessageText($chatId, $messageId, 'Voucher sudah tidak ada (mungkin sudah dihapus).');

            return;
        }

        try {
            $r = HapusPenjualan::hapusVoucher($voucher, 'telegram:'.$chatId);
        } catch (\Throwable $e) {
            report($e);
            $telegram->editMessageText($chatId, $messageId, '❌ Gagal menghapus: '.static::e($e->getMessage()));

            return;
        }

        $telegram->editMessageText($chatId, $messageId, implode("\n", [
            '✅ <b>Penjualan dihapus permanen</b>',
            '',
            'Voucher  : <code>'.static::e($r['kode']).'</code>',
            'Transaksi: '.(empty($r['transaksi']) ? '-' : static::e(implode(', ', $r['transaksi']))),
            'Nilai    : '.HapusPenjualan::rupiah($r['total']),
            '',
            'Tidak dihitung lagi di Laporan Penjualan.',
        ]));
    }
}
