<?php

namespace App\Filament\Actions;

use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\HapusPenjualan;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * HAPUS-PENJUALAN -- tombol "Hapus Penjualan" (permanen) di menu
 * Voucher Terjual & Transaksi. Hanya tampil & hanya jalan untuk SUPER ADMIN.
 */
class HapusPenjualanAction
{
    protected static function bolehkah(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    protected static function oleh(): string
    {
        $u = auth()->user();

        return 'web:'.($u?->email ?? $u?->name ?? $u?->id ?? '-');
    }

    protected static function dasar(): Action
    {
        return Action::make('hapus_penjualan')
            ->label('Hapus Penjualan')
            ->iconButton()
            ->icon('lucide-trash-2')
            ->color('danger')
            ->tooltip('Hapus penjualan (permanen)')
            ->visible(fn () => static::bolehkah())
            ->requiresConfirmation()
            ->modalIcon('lucide-trash-2')
            ->modalHeading('Hapus penjualan permanen?')
            ->modalSubmitActionLabel('Ya, hapus permanen');
    }

    protected static function beritahu(array $r): void
    {
        Notification::make()
            ->title('Penjualan dihapus permanen')
            ->body(trim(($r['kode'] ? 'Voucher '.$r['kode'].' • ' : '').HapusPenjualan::rupiah($r['total']).' tidak dihitung lagi di laporan.'))
            ->success()
            ->send();
    }

    /** Untuk tabel Voucher Terjual (record = Voucher). */
    public static function untukVoucher(): Action
    {
        return static::dasar()
            ->modalDescription(fn (Voucher $record) => 'Voucher '.$record->username.' beserta transaksi penjualannya akan DIHAPUS PERMANEN dan tidak masuk Laporan Penjualan lagi. Saldo outlet tidak diubah. Tindakan ini tidak bisa dibatalkan.')
            ->action(function (Voucher $record) {
                abort_unless(static::bolehkah(), 403);
                static::beritahu(HapusPenjualan::hapusVoucher($record, static::oleh()));
            });
    }

    /** Untuk tabel Transaksi (record = Transaction). */
    public static function untukTransaksi(): Action
    {
        return static::dasar()
            ->modalDescription(fn (Transaction $record) => 'Transaksi '.$record->transaction_number.' beserta vouchernya akan DIHAPUS PERMANEN dan tidak masuk Laporan Penjualan lagi. Saldo outlet tidak diubah. Tindakan ini tidak bisa dibatalkan.')
            ->action(function (Transaction $record) {
                abort_unless(static::bolehkah(), 403);
                static::beritahu(HapusPenjualan::hapusTransaksi($record, static::oleh()));
            });
    }
}
