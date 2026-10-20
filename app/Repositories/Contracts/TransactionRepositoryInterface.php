<?php

namespace App\Repositories\Contracts;

use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

interface TransactionRepositoryInterface
{
    public function create(array $data): Transaction;

    public function totalRevenueBetween(Carbon $from, Carbon $to, ?int $outletId = null): float;

    public function topOutlets(Carbon $from, Carbon $to, int $limit = 5): Collection;

    public function salesChartData(Carbon $from, Carbon $to): Collection;
}
