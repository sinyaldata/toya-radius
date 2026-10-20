<?php

namespace App\Repositories;

use App\Enums\VoucherStatus;
use App\Models\Voucher;
use App\Repositories\Contracts\VoucherRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class VoucherRepository implements VoucherRepositoryInterface
{
    public function __construct(protected Voucher $model) {}

    public function find(int $id): ?Voucher
    {
        return $this->model->find($id);
    }

    public function findByUsername(string $username): ?Voucher
    {
        return $this->model->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->where('username', $username)->first();
    }

    public function create(array $data): Voucher
    {
        return $this->model->create($data);
    }

    public function createMany(array $rows): void
    {
        // insert() tidak trigger event Eloquent (by design, untuk performa batch generate ratusan/ribuan voucher)
        $this->model->newQuery()->insert($rows);
    }

    public function updateStatus(Voucher $voucher, VoucherStatus $status): bool
    {
        return $voucher->update(['status' => $status]);
    }

    public function paginateByStatus(?VoucherStatus $status = null, ?int $outletId = null, int $perPage = 25): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->latest()
            ->paginate($perPage);
    }

    public function countByStatus(VoucherStatus $status, ?int $outletId = null): int
    {
        return $this->model->newQuery()
            ->where('status', $status)
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->count();
    }

    /**
     * Voucher yang tanggal kadaluarsanya SUDAH LEWAT tapi belum ditandai
     * Expired.
     *
     * DIPERLUAS: sebelumnya syaratnya `status = Aktif` saja — artinya
     * voucher yang BELUM PERNAH dipakai login tidak pernah tersentuh,
     * walau tanggal kadaluarsanya sudah lewat berbulan-bulan. Itu jadi
     * masalah nyata untuk voucher hasil IMPOR, yang masa berlakunya
     * ditentukan dari awal lewat kolom "Kedaluwarsa" dan statusnya masih
     * Belum Terjual / Terjual — vouchernya tetap bisa dipakai login
     * selamanya.
     *
     * Sekarang SEMUA status ikut disapu, KECUALI yang memang sudah
     * selesai urusannya:
     * - Expired  : sudah ditandai, tidak perlu diproses lagi.
     * - Disabled : sudah diblokir manual oleh admin, jangan diubah
     *              statusnya jadi Expired (biar jejak "sengaja
     *              dinonaktifkan" tidak hilang).
     *
     * Voucher yang tanggal kadaluarsanya KOSONG tetap tidak tersentuh —
     * berlaku selamanya, sama seperti sebelumnya.
     */
    public function expiredButNotMarked(): Collection
    {
        return $this->model->newQuery()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->whereNotIn('status', [VoucherStatus::Expired, VoucherStatus::Disabled])
            ->whereNotNull('expired_at')
            ->where('expired_at', '<', now())
            ->get();
    }
}
