<?php

namespace App\Repositories\Contracts;

use App\Enums\VoucherStatus;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface VoucherRepositoryInterface
{
    public function find(int $id): ?Voucher;

    public function findByUsername(string $username): ?Voucher;

    public function create(array $data): Voucher;

    public function createMany(array $rows): void;

    public function updateStatus(Voucher $voucher, VoucherStatus $status): bool;

    public function paginateByStatus(?VoucherStatus $status = null, ?int $outletId = null, int $perPage = 25): LengthAwarePaginator;

    public function countByStatus(VoucherStatus $status, ?int $outletId = null): int;

    public function expiredButNotMarked(): Collection;
}
