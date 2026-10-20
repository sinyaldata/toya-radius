<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Models\MiniAtmTransaction;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class MiniAtmReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Laporan Mini ATM';

    protected static ?string $title = 'Laporan Pendapatan Mini ATM';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.admin.pages.reports.mini-atm-report';

    public string $from = '';

    public string $to = '';

    public string $type = '';

    public function mount(): void
    {
        $this->from = today()->startOfMonth()->toDateString();
        $this->to = today()->toDateString();
    }

    /** @return Collection<int, MiniAtmTransaction> */
    public function rows(): Collection
    {
        $from = $this->from !== '' ? $this->from : today()->toDateString();
        $to = $this->to !== '' ? $this->to : $from;

        return MiniAtmTransaction::query()
            ->with('user')
            ->active()
            ->whereIn('type', $this->type !== '' ? [$this->type] : MiniAtmTransaction::INCOME_TYPES)
            ->whereDate('transacted_at', '>=', $from)
            ->whereDate('transacted_at', '<=', $to)
            ->orderBy('transacted_at')
            ->get();
    }

    /** @return array{count: int, amount: float, fee: float, bank_fee: float, profit: float} */
    public function totals(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'amount' => (float) $rows->sum('amount'),
            'fee' => (float) $rows->sum('fee'),
            'bank_fee' => (float) $rows->sum('bank_fee'),
            'profit' => (float) $rows->sum('fee') - (float) $rows->sum('bank_fee'),
        ];
    }

    /** @return Collection<string, array<string, float|int>> */
    public function byType(Collection $rows): Collection
    {
        return $rows->groupBy('type')->map(fn (Collection $g) => $this->totals($g));
    }

    /** @return Collection<string, array<string, float|int>> */
    public function byDay(Collection $rows): Collection
    {
        return $rows->groupBy(fn ($t) => $t->transacted_at->toDateString())->map(fn (Collection $g) => $this->totals($g))->sortKeys();
    }
}
