<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\SaldoMutation;
use Illuminate\Support\Facades\DB;

/**
 * Kelola saldo outlet — top up (diisi manual oleh admin setelah reseller
 * setor uang) dan potongan otomatis (waktu generate voucher). SEMUA
 * perubahan saldo WAJIB lewat sini, supaya angka `outlets.saldo` dan
 * riwayat `saldo_mutations` selalu SINKRON (dibungkus 1 transaksi
 * database, tidak akan pernah "nyangkut" salah satu doang).
 */
class SaldoService
{
    public function topup(Outlet $outlet, float $amount, ?int $adminId = null, ?string $description = null): SaldoMutation
    {
        return DB::transaction(function () use ($outlet, $amount, $adminId, $description) {
            $outlet->increment('saldo', $amount);

            return SaldoMutation::create([
                'outlet_id' => $outlet->id,
                'type' => 'topup',
                'amount' => $amount,
                'balance_after' => $outlet->fresh()->saldo,
                'description' => $description ?: 'Top up saldo',
                'created_by' => $adminId,
            ]);
        });
    }

    /**
     * Potong saldo — dipakai waktu generate voucher. Melempar
     * \RuntimeException kalau saldo tidak cukup, supaya proses generate
     * voucher DIBATALKAN TOTAL (tidak ada voucher setengah jadi).
     */
    public function deduct(Outlet $outlet, float $amount, string $description): SaldoMutation
    {
        return DB::transaction(function () use ($outlet, $amount, $description) {
            $outlet->refresh();

            if ((float) $outlet->saldo < $amount) {
                $kurang = number_format($amount - (float) $outlet->saldo, 0, ',', '.');

                throw new \RuntimeException("Saldo tidak cukup — kurang Rp{$kurang}. Silakan top up dulu.");
            }

            $outlet->decrement('saldo', $amount);

            return SaldoMutation::create([
                'outlet_id' => $outlet->id,
                'type' => 'potongan',
                'amount' => $amount,
                'balance_after' => $outlet->fresh()->saldo,
                'description' => $description,
                'created_by' => null,
            ]);
        });
    }
}
