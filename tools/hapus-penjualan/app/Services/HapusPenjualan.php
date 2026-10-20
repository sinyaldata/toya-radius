<?php

namespace App\Services;

use App\Models\Scopes\OutletScope;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HAPUS-PENJUALAN -- hapus PERMANEN penjualan voucher (khusus SUPER ADMIN):
 * transaksi penjualannya dihapus (jadi tidak masuk Laporan Penjualan,
 * dasbor & komisi lagi), lalu vouchernya dihapus -- data RADIUS & log
 * voucher ikut bersih lewat Voucher::deleting(). Saldo outlet TIDAK diubah.
 * Setiap penghapusan dicatat di storage/logs (siapa, kapan, apa).
 */
class HapusPenjualan
{
    public const KUNCI_CHAT_SUPERADMIN = 'telegram_superadmin_chat_id';

    public static function cariVoucher(string $kode): ?Voucher
    {
        $kode = trim($kode);

        if ($kode === '') {
            return null;
        }

        return Voucher::withoutGlobalScope(OutletScope::class)
            ->with(['profile', 'outlet'])
            ->where('username', $kode)
            ->first();
    }

    /** @return \Illuminate\Support\Collection<int, Transaction> */
    public static function transaksiVoucher(Voucher $voucher)
    {
        return Transaction::withoutGlobalScope(OutletScope::class)
            ->where('voucher_id', $voucher->id)
            ->get();
    }

    /**
     * @return array{kode: ?string, transaksi: array<int, string>, total: float, oleh: string}
     */
    public static function hapusVoucher(Voucher $voucher, string $oleh): array
    {
        return DB::transaction(function () use ($voucher, $oleh) {
            $trx = static::transaksiVoucher($voucher);

            $ringkas = [
                'kode' => (string) $voucher->username,
                'transaksi' => $trx->pluck('transaction_number')->filter()->values()->all(),
                'total' => (float) $trx->sum('total_amount'),
                'oleh' => $oleh,
            ];

            foreach ($trx as $t) {
                $t->delete();
            }

            $voucher->delete();

            static::catat($ringkas);

            return $ringkas;
        });
    }

    /**
     * @return array{kode: ?string, transaksi: array<int, string>, total: float, oleh: string}
     */
    public static function hapusTransaksi(Transaction $transaksi, string $oleh): array
    {
        $voucher = $transaksi->voucher_id
            ? Voucher::withoutGlobalScope(OutletScope::class)->find($transaksi->voucher_id)
            : null;

        if ($voucher) {
            return static::hapusVoucher($voucher, $oleh);
        }

        return DB::transaction(function () use ($transaksi, $oleh) {
            $ringkas = [
                'kode' => null,
                'transaksi' => array_filter([(string) $transaksi->transaction_number]),
                'total' => (float) $transaksi->total_amount,
                'oleh' => $oleh,
            ];

            $transaksi->delete();
            static::catat($ringkas);

            return $ringkas;
        });
    }

    protected static function catat(array $ringkas): void
    {
        Log::warning('HAPUS-PENJUALAN: penjualan dihapus permanen', $ringkas + [
            'tenant' => app()->bound('currentTenant') ? (app('currentTenant')?->subdomain ?? 'utama') : 'utama',
            'waktu' => now()->toDateTimeString(),
        ]);
    }

    public static function rupiah(float $n): string
    {
        return 'Rp'.number_format($n, 0, ',', '.');
    }

    /** Daftar Chat ID Telegram super admin (dibaca langsung, tanpa cache). */
    public static function chatSuperAdmin(): array
    {
        try {
            $raw = (string) (Setting::query()->where('key', static::KUNCI_CHAT_SUPERADMIN)->value('value') ?? '');
        } catch (\Throwable $e) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: [])));
    }

    public static function chatAdalahSuperAdmin(string $chatId): bool
    {
        if ($chatId === '') {
            return false;
        }

        foreach (static::chatSuperAdmin() as $id) {
            if (hash_equals($id, $chatId)) {
                return true;
            }
        }

        return false;
    }
}
