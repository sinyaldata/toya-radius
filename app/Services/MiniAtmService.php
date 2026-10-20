<?php

namespace App\Services;

use App\Models\MiniAtmAccount;
use App\Models\MiniAtmTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MiniAtmService
{
    /**
     * $storeId/$userId diisi bila dipakai di luar sesi login (mis. bot Telegram);
     * bila kosong dipakai toko & pengguna yang sedang login (halaman Mini ATM).
     */
    public function __construct(protected ?int $storeId = null, protected ?int $userId = null) {}

    public static function forStore(int $storeId, ?int $userId = null): static
    {
        return new static($storeId, $userId);
    }

    protected function sid(): ?int
    {
        return $this->storeId ?? auth()->user()?->store_id;
    }

    protected function q(): Builder
    {
        return $this->storeId
            ? MiniAtmTransaction::withoutGlobalScopes()->where('store_id', $this->storeId)
            : MiniAtmTransaction::query();
    }

    protected function accountQuery(): Builder
    {
        return MiniAtmAccount::withoutGlobalScopes()->where('store_id', $this->sid());
    }

    // ---------------------------------------------------------------- rekening

    /** Semua rekening toko (aktif dulu). Membuat "Rekening Utama" bila belum ada. */
    public function accounts(bool $onlyActive = true): Collection
    {
        if (! $this->accountQuery()->exists()) {
            MiniAtmAccount::withoutGlobalScopes()->create(['store_id' => $this->sid(), 'name' => 'Rekening Utama', 'is_active' => true]);
        }

        $q = $this->accountQuery();
        if ($onlyActive) {
            $q->where('is_active', true);
        }

        return $q->orderBy('id')->get();
    }

    public function defaultAccountId(): int
    {
        return (int) $this->accounts()->first()->id;
    }

    /** Cari rekening menurut nama/bank (huruf besar-kecil diabaikan). */
    public function findAccountByName(string $name): ?MiniAtmAccount
    {
        $name = strtolower(trim($name));

        return $this->accounts()->first(fn ($a) => strtolower($a->name) === $name)
            ?? $this->accounts()->first(fn ($a) => str_contains(strtolower($a->name.' '.$a->bank_name), $name));
    }

    /** @param array<string, mixed> $data */
    public function saveAccount(?int $id, array $data): MiniAtmAccount
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Nama rekening wajib diisi.');
        }

        $dup = $this->accountQuery()->whereRaw('LOWER(name) = ?', [strtolower($name)])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($dup) {
            throw new RuntimeException('Nama rekening "'.$name.'" sudah dipakai.');
        }

        $payload = [
            'name' => $name,
            'bank_name' => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
        ];

        if ($id) {
            $account = $this->accountQuery()->findOrFail($id);

            if (array_key_exists('is_active', $data) && ! $data['is_active'] && $account->is_active) {
                if (abs($this->accountBalances()[$id] ?? 0) > 0.009) {
                    throw new RuntimeException('Rekening masih memiliki saldo. Pindahkan saldonya dulu sebelum dinonaktifkan.');
                }
                $payload['is_active'] = false;
            } elseif (array_key_exists('is_active', $data)) {
                $payload['is_active'] = (bool) $data['is_active'];
            }

            $account->update($payload);

            return $account->refresh();
        }

        return MiniAtmAccount::withoutGlobalScopes()->create([...$payload, 'store_id' => $this->sid(), 'is_active' => true]);
    }

    /** @return array<int, float> saldo per rekening (id => saldo) */
    public function accountBalances(): array
    {
        $default = $this->defaultAccountId();
        $balances = [];

        foreach ($this->accounts(false) as $a) {
            $balances[(int) $a->id] = 0.0;
        }

        $sum = fn (Builder $q, string $col, string $agg) => $q->selectRaw("COALESCE($col, ?) as acc, SUM($agg) as total", [$default])->groupBy('acc')->pluck('total', 'acc');

        foreach ($sum($this->q()->active(), 'account_id', 'bank_effect') as $acc => $total) {
            $balances[(int) $acc] = ($balances[(int) $acc] ?? 0) + (float) $total;
        }
        foreach ($sum($this->q()->active()->where('type', 'pindah'), 'account_id', 'amount') as $acc => $total) {
            $balances[(int) $acc] = ($balances[(int) $acc] ?? 0) - (float) $total;
        }
        foreach ($this->q()->active()->where('type', 'pindah')->whereNotNull('to_account_id')->selectRaw('to_account_id as acc, SUM(amount) as total')->groupBy('to_account_id')->pluck('total', 'acc') as $acc => $total) {
            $balances[(int) $acc] = ($balances[(int) $acc] ?? 0) + (float) $total;
        }

        return $balances;
    }

    // ---------------------------------------------------------------- perhitungan

    /**
     * Pengaruh transaksi ke saldo cash & rekening (positif = bertambah).
     *
     * tarik    : kas keluar (nominal - jasa), rekening masuk (nominal - admin bank)
     * setor    : kas masuk (nominal + jasa), rekening keluar (nominal + admin bank)
     * transfer : kas masuk (nominal + jasa), rekening keluar (nominal + admin bank)
     * isi_saldo: kas -> rekening ; tarik_saldo: rekening -> kas
     * pindah   : rekening A -> rekening B (total tidak berubah)
     *
     * @return array{cash: float, bank: float}
     */
    public static function effects(string $type, float $amount, float $fee = 0, float $bankFee = 0, float $cash = 0, float $bank = 0): array
    {
        return match ($type) {
            'tarik' => ['cash' => -$amount + $fee, 'bank' => $amount - $bankFee],
            'setor', 'transfer' => ['cash' => $amount + $fee, 'bank' => -$amount - $bankFee],
            'isi_saldo' => ['cash' => -$amount, 'bank' => $amount],
            'tarik_saldo' => ['cash' => $amount, 'bank' => -$amount],
            'pindah' => ['cash' => 0.0, 'bank' => 0.0],
            'koreksi' => ['cash' => $cash, 'bank' => $bank],
            default => throw new RuntimeException('Jenis transaksi tidak dikenal.'),
        };
    }

    /**
     * Perubahan saldo per rekening akibat satu transaksi.
     *
     * @return array<int, float>
     */
    protected static function accountDelta(string $type, ?int $accountId, ?int $toAccountId, float $amount, float $bankEffect): array
    {
        if ($type === 'pindah') {
            return [(int) $accountId => -$amount, (int) $toAccountId => $amount];
        }

        return [(int) $accountId => $bankEffect];
    }

    /** @return array{cash: float, bank: float, total: float, accounts: array<int, float>} */
    public function balances(): array
    {
        $cash = (float) $this->q()->active()->sum('cash_effect');
        $accounts = $this->accountBalances();
        $bank = array_sum($accounts);

        return ['cash' => $cash, 'bank' => (float) $bank, 'total' => $cash + $bank, 'accounts' => $accounts];
    }

    /** Tolak bila perubahan membuat saldo cash / rekening minus. */
    protected function assertSufficient(float $cashDelta, array $accountDeltas): void
    {
        $b = $this->balances();

        if ($cashDelta < 0 && $b['cash'] + $cashDelta < -0.009) {
            throw new RuntimeException('Saldo cash tidak cukup (tersedia Rp '.number_format($b['cash'], 0, ',', '.').').');
        }

        $names = $this->accounts(false)->pluck('name', 'id');
        foreach ($accountDeltas as $accId => $delta) {
            if ($delta < 0 && ($b['accounts'][$accId] ?? 0) + $delta < -0.009) {
                throw new RuntimeException('Saldo '.($names[$accId] ?? 'rekening').' tidak cukup (tersedia Rp '.number_format($b['accounts'][$accId] ?? 0, 0, ',', '.').').');
            }
        }
    }

    // ---------------------------------------------------------------- transaksi

    /** @param array<string, mixed> $data */
    public function record(array $data): MiniAtmTransaction
    {
        $type = (string) $data['type'];
        $amount = (float) ($data['amount'] ?? 0);
        $fee = (float) ($data['fee'] ?? 0);
        $bankFee = (float) ($data['bank_fee'] ?? 0);

        if ($type !== 'koreksi' && $amount <= 0) {
            throw new RuntimeException('Nominal harus lebih dari 0.');
        }

        $accountId = (int) ($data['account_id'] ?? 0) ?: $this->defaultAccountId();
        $toAccountId = $type === 'pindah' ? (int) ($data['to_account_id'] ?? 0) : null;

        if ($type === 'pindah' && (! $toAccountId || $toAccountId === $accountId)) {
            throw new RuntimeException('Pilih rekening tujuan yang berbeda dari rekening asal.');
        }

        $fx = static::effects($type, $amount, $fee, $bankFee, (float) ($data['cash_effect'] ?? 0), (float) ($data['bank_effect'] ?? 0));

        $tx = DB::transaction(function () use ($data, $type, $amount, $fee, $bankFee, $fx, $accountId, $toAccountId) {
            if ($type !== 'koreksi') {
                $this->assertSufficient($fx['cash'], static::accountDelta($type, $accountId, $toAccountId, $amount, $fx['bank']));
            }

            $storeId = $this->sid();

            return MiniAtmTransaction::withoutGlobalScopes()->create([
                'store_id' => $storeId,
                'user_id' => $this->userId ?? auth()->id(),
                'number' => $this->nextNumber($storeId),
                'type' => $type,
                'account_id' => $accountId,
                'to_account_id' => $toAccountId,
                'bank_name' => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'account_name' => $data['account_name'] ?? null,
                'amount' => $type === 'koreksi' ? 0 : $amount,
                'fee' => $fee,
                'bank_fee' => $bankFee,
                'cash_effect' => $fx['cash'],
                'bank_effect' => $fx['bank'],
                'notes' => $data['notes'] ?? null,
                'transacted_at' => now(),
            ]);
        });

        $this->notifyTelegram($tx);

        return $tx;
    }

    /** Kirim notifikasi Telegram ke chat toko untuk Tarik/Setor/Transfer (tidak pernah menggagalkan transaksi). */
    protected function notifyTelegram(MiniAtmTransaction $tx): void
    {
        try {
            if (! in_array($tx->type, MiniAtmTransaction::INCOME_TYPES, true) || $tx->notes === 'via Telegram') {
                return; // transaksi dari bot sudah dibalas langsung oleh bot
            }

            $store = \App\Models\Store::query()->withoutGlobalScopes()->find($tx->store_id);
            if (! $store || blank($store->telegram_chat_id) || ! class_exists(\App\Services\TelegramNotifier::class)) {
                return;
            }

            $rp = fn ($v) => 'Rp'.number_format((float) $v, 0, ',', '.');
            $b = $this->balances();
            $icon = ['tarik' => '💵', 'setor' => '🏦', 'transfer' => '🔁'][$tx->type] ?? '🏧';
            $lines = [
                $icon.' <b>Mini ATM — '.(MiniAtmTransaction::TYPES[$tx->type] ?? $tx->type).'</b>',
                'No: '.$tx->number,
                'Nominal: '.$rp($tx->amount),
                'Jasa: '.$rp($tx->fee).($tx->bank_fee > 0 ? ' (adm bank '.$rp($tx->bank_fee).')' : ''),
            ];
            $bank = trim(($tx->bank_name ?? '').' '.($tx->account_number ?? '').($tx->account_name ? ' · '.$tx->account_name : ''));
            if ($bank !== '') {
                $lines[] = 'Bank: '.$bank;
            }
            $lines[] = $tx->type === 'tarik'
                ? 'Diserahkan ke pelanggan: '.$rp(max($tx->amount - $tx->fee, 0))
                : 'Diterima dari pelanggan: '.$rp($tx->amount + $tx->fee);
            $user = $tx->user_id ? \App\Models\User::query()->find($tx->user_id)?->name : null;
            $lines[] = 'Petugas: '.($user ?? '-').' · '.$tx->transacted_at->format('d/m/Y H:i');
            $lines[] = '';
            $lines[] = 'Saldo Cash: '.$rp($b['cash']).' | Rekening: '.$rp($b['bank']).' | Total: '.$rp($b['total']);

            app(\App\Services\TelegramNotifier::class)->send($store, (string) $store->telegram_chat_id, implode("\n", $lines));
        } catch (\Throwable $e) {
            logger()->warning('MiniATM notif Telegram gagal: '.$e->getMessage());
        }
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): MiniAtmTransaction
    {
        return DB::transaction(function () use ($id, $data) {
            $tx = $this->q()->lockForUpdate()->findOrFail($id);

            if ($tx->is_void) {
                throw new RuntimeException('Transaksi yang sudah dibatalkan tidak bisa diedit.');
            }

            $amount = (float) ($data['amount'] ?? $tx->amount);
            $fee = (float) ($data['fee'] ?? $tx->fee);
            $bankFee = (float) ($data['bank_fee'] ?? $tx->bank_fee);
            $accountId = (int) ($data['account_id'] ?? $tx->account_id) ?: $this->defaultAccountId();
            $toAccountId = $tx->type === 'pindah' ? (int) ($data['to_account_id'] ?? $tx->to_account_id) : null;

            if ($tx->type !== 'koreksi' && $amount <= 0) {
                throw new RuntimeException('Nominal harus lebih dari 0.');
            }
            if ($tx->type === 'pindah' && (! $toAccountId || $toAccountId === $accountId)) {
                throw new RuntimeException('Pilih rekening tujuan yang berbeda dari rekening asal.');
            }

            $fx = static::effects($tx->type, $amount, $fee, $bankFee, (float) ($data['cash_effect'] ?? $tx->cash_effect), (float) ($data['bank_effect'] ?? $tx->bank_effect));

            if ($tx->type !== 'koreksi') {
                $old = static::accountDelta($tx->type, (int) $tx->account_id ?: $this->defaultAccountId(), $tx->to_account_id, (float) $tx->amount, (float) $tx->bank_effect);
                $new = static::accountDelta($tx->type, $accountId, $toAccountId, $amount, $fx['bank']);

                $delta = [];
                foreach ([$old, $new] as $i => $set) {
                    foreach ($set as $acc => $v) {
                        $delta[$acc] = ($delta[$acc] ?? 0) + ($i === 0 ? -$v : $v);
                    }
                }

                $this->assertSufficient($fx['cash'] - (float) $tx->cash_effect, $delta);
            }

            $tx->update([
                'account_id' => $accountId,
                'to_account_id' => $toAccountId,
                'bank_name' => $data['bank_name'] ?? $tx->bank_name,
                'account_number' => $data['account_number'] ?? null,
                'account_name' => $data['account_name'] ?? null,
                'amount' => $tx->type === 'koreksi' ? 0 : $amount,
                'fee' => $fee,
                'bank_fee' => $bankFee,
                'cash_effect' => $fx['cash'],
                'bank_effect' => $fx['bank'],
                'notes' => $data['notes'] ?? null,
            ]);

            return $tx->refresh();
        });
    }

    protected function assertReversible(MiniAtmTransaction $tx): void
    {
        if ($tx->is_void || $tx->type === 'koreksi') {
            return;
        }

        $delta = [];
        foreach (static::accountDelta($tx->type, (int) $tx->account_id ?: $this->defaultAccountId(), $tx->to_account_id, (float) $tx->amount, (float) $tx->bank_effect) as $acc => $v) {
            $delta[$acc] = -$v;
        }

        try {
            $this->assertSufficient(-(float) $tx->cash_effect, $delta);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Tidak bisa dibatalkan/dihapus: '.lcfirst($e->getMessage()));
        }
    }

    public function void(int $id): void
    {
        DB::transaction(function () use ($id) {
            $tx = $this->q()->lockForUpdate()->findOrFail($id);

            if ($tx->is_void) {
                return;
            }

            $this->assertReversible($tx);
            $tx->update(['is_void' => true, 'voided_at' => now()]);
        });
    }

    /** Hapus permanen. Bila masih aktif, pengaruhnya ke saldo ikut dibatalkan (ditolak jika saldo jadi minus). */
    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $tx = $this->q()->lockForUpdate()->findOrFail($id);
            $this->assertReversible($tx);
            $tx->delete();
        });
    }

    public function voidByNumber(string $number): MiniAtmTransaction
    {
        $tx = $this->q()->where('number', strtoupper(trim($number)))->first();

        if (! $tx) {
            throw new RuntimeException('Nomor transaksi '.$number.' tidak ditemukan.');
        }

        if ($tx->is_void) {
            throw new RuntimeException($tx->number.' sudah dibatalkan sebelumnya.');
        }

        $this->void($tx->id);

        return $tx->refresh();
    }

    /** @return array{count: int, amount: float, fee: float, bank_fee: float, profit: float, by_type: array<string, array{count: int, fee: float}>} */
    public function summary(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->q()->active()
            ->whereIn('type', MiniAtmTransaction::INCOME_TYPES)
            ->whereBetween('transacted_at', [$from, $to])
            ->get();

        $byType = [];
        foreach ($rows->groupBy('type') as $type => $g) {
            $byType[$type] = ['count' => $g->count(), 'fee' => (float) $g->sum('fee')];
        }

        $fee = (float) $rows->sum('fee');
        $bankFee = (float) $rows->sum('bank_fee');

        return [
            'count' => $rows->count(),
            'amount' => (float) $rows->sum('amount'),
            'fee' => $fee,
            'bank_fee' => $bankFee,
            'profit' => $fee - $bankFee,
            'by_type' => $byType,
        ];
    }

    protected function nextNumber(?int $storeId): string
    {
        $prefix = 'ATM-'.now()->format('Ymd').'-';
        $n = MiniAtmTransaction::withoutGlobalScopes()->where('store_id', $storeId)->where('number', 'like', $prefix.'%')->count() + 1;

        while (MiniAtmTransaction::withoutGlobalScopes()->where('store_id', $storeId)->where('number', $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT))->exists()) {
            $n++;
        }

        return $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
