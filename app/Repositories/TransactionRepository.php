<?php

namespace App\Repositories;

use App\Enums\PaymentStatus;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TransactionRepository implements TransactionRepositoryInterface
{
    public function __construct(protected Transaction $model) {}

    public function create(array $data): Transaction
    {
        return $this->model->create($data);
    }

    public function totalRevenueBetween(Carbon $from, Carbon $to, ?int $outletId = null): float
    {
        return (float) $this->model->newQuery()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('payment_status', PaymentStatus::Lunas)
            ->whereBetween('transaction_date', [$from, $to])
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->sum('total_amount');
    }

    public function topOutlets(Carbon $from, Carbon $to, int $limit = 5): Collection
    {
        return $this->model->newQuery()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->selectRaw('outlet_id, SUM(total_amount) as total_sales, COUNT(*) as total_transactions')
            ->where('payment_status', PaymentStatus::Lunas)
            ->whereBetween('transaction_date', [$from, $to])
            ->whereNotNull('outlet_id')
            ->groupBy('outlet_id')
            ->orderByDesc('total_sales')
            ->with('outlet:id,name,code')
            ->limit($limit)
            ->get();
    }

    public function salesChartData(Carbon $from, Carbon $to): Collection
    {
        // Akun OUTLET hanya boleh melihat penjualannya sendiri, jadi
        // OutletScope dibiarkan aktif untuk mereka. Admin/pemilik tetap
        // melihat gabungan semua outlet.
        $query = $this->model->newQuery();

        if (! \Illuminate\Support\Facades\Auth::user()?->isOutlet()) {
            $query->withoutGlobalScope(\App\Models\Scopes\OutletScope::class);
        }

        return $query
            ->selectRaw('DATE(transaction_date) as date, SUM(total_amount) as total, COUNT(*) as count')
            ->where('payment_status', PaymentStatus::Lunas)
            ->whereBetween('transaction_date', [$from, $to])
            ->groupBy(DB::raw('DATE(transaction_date)'))
            ->orderBy('date')
            ->get();
    }
}
