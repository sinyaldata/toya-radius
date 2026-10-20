<x-filament-panels::page>
    <style>
        .mr-filters { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
        .mr-cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
        .mr-card { border: 1px solid rgba(128,128,128,.25); border-radius: 14px; padding: .9rem 1rem; background: rgba(128,128,128,.05); }
        .mr-card small { display: block; font-size: .72rem; opacity: .7; margin-bottom: .2rem; }
        .mr-card strong { font-size: 1.2rem; font-weight: 700; }
        .mr-card.is-profit strong { color: #16a34a; }
        .mr-card.is-cost strong { color: #dc2626; }
        .mr-h { font-weight: 700; margin: 1.25rem 0 .5rem; }
        .mr-wrap { overflow-x: auto; border: 1px solid rgba(128,128,128,.25); border-radius: 12px; }
        .mr-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .mr-table th, .mr-table td { padding: .55rem .75rem; text-align: left; white-space: nowrap; border-bottom: 1px solid rgba(128,128,128,.15); }
        .mr-table th { font-size: .72rem; text-transform: uppercase; opacity: .7; }
        .mr-table .r { text-align: right; }
        .mr-table tfoot td { font-weight: 700; }
        @media (max-width: 767px) { .mr-cards { grid-template-columns: 1fr 1fr; } .mr-filters { grid-template-columns: 1fr; } }
        @media print { .fi-sidebar, .fi-topbar, .mr-filters { display: none !important; } }
    </style>

    @php
        $rows = $this->rows();
        $tot = $this->totals($rows);
        $byType = $this->byType($rows);
        $byDay = $this->byDay($rows);
        $fmt = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $labels = \App\Models\MiniAtmTransaction::TYPES;
    @endphp

    <div class="mr-filters">
        <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
        <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="type">
                <option value="">Semua jenis</option>
                @foreach (\App\Models\MiniAtmTransaction::INCOME_TYPES as $k)
                    <option value="{{ $k }}">{{ $labels[$k] }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    <div class="mr-cards">
        <div class="mr-card"><small>Jumlah Transaksi</small><strong>{{ number_format($tot['count'], 0, ',', '.') }}</strong></div>
        <div class="mr-card"><small>Total Jasa</small><strong>{{ $fmt($tot['fee']) }}</strong></div>
        <div class="mr-card is-cost"><small>Biaya Admin Bank</small><strong>{{ $fmt($tot['bank_fee']) }}</strong></div>
        <div class="mr-card is-profit"><small>Pendapatan Bersih</small><strong>{{ $fmt($tot['profit']) }}</strong></div>
    </div>

    <div class="mr-h">Per Jenis Transaksi</div>
    <div class="mr-wrap">
        <table class="mr-table">
            <thead><tr><th>Jenis</th><th class="r">Trx</th><th class="r">Total Nominal</th><th class="r">Jasa</th><th class="r">Adm. Bank</th><th class="r">Bersih</th></tr></thead>
            <tbody>
                @forelse ($byType as $k => $g)
                    <tr><td>{{ $labels[$k] ?? $k }}</td><td class="r">{{ $g['count'] }}</td><td class="r">{{ $fmt($g['amount']) }}</td><td class="r">{{ $fmt($g['fee']) }}</td><td class="r">{{ $fmt($g['bank_fee']) }}</td><td class="r">{{ $fmt($g['profit']) }}</td></tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;padding:1.25rem;opacity:.6;">Tidak ada data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($byDay->count() > 1)
        <div class="mr-h">Per Hari</div>
        <div class="mr-wrap">
            <table class="mr-table">
                <thead><tr><th>Tanggal</th><th class="r">Trx</th><th class="r">Total Nominal</th><th class="r">Jasa</th><th class="r">Adm. Bank</th><th class="r">Bersih</th></tr></thead>
                <tbody>
                    @foreach ($byDay as $day => $g)
                        <tr><td>{{ \Carbon\Carbon::parse($day)->translatedFormat('d M Y') }}</td><td class="r">{{ $g['count'] }}</td><td class="r">{{ $fmt($g['amount']) }}</td><td class="r">{{ $fmt($g['fee']) }}</td><td class="r">{{ $fmt($g['bank_fee']) }}</td><td class="r">{{ $fmt($g['profit']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="mr-h">Rincian Transaksi</div>
    <div class="mr-wrap">
        <table class="mr-table">
            <thead><tr><th>Waktu</th><th>No.</th><th>Jenis</th><th>Bank / Rekening</th><th class="r">Nominal</th><th class="r">Jasa</th><th class="r">Adm. Bank</th><th class="r">Bersih</th><th>Petugas</th></tr></thead>
            <tbody>
                @forelse ($rows as $t)
                    <tr>
                        <td>{{ $t->transacted_at->format('d/m H:i') }}</td>
                        <td>{{ $t->number }}</td>
                        <td>{{ $t->type_label }}</td>
                        <td>{{ trim(($t->bank_name ?? '') . ' ' . ($t->account_number ?? '')) ?: '-' }}</td>
                        <td class="r">{{ $fmt($t->amount) }}</td>
                        <td class="r">{{ $fmt($t->fee) }}</td>
                        <td class="r">{{ $fmt($t->bank_fee) }}</td>
                        <td class="r">{{ $fmt($t->profit) }}</td>
                        <td>{{ $t->user?->name ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="text-align:center;padding:1.25rem;opacity:.6;">Tidak ada transaksi pada periode ini.</td></tr>
                @endforelse
            </tbody>
            @if ($rows->count())
                <tfoot><tr><td colspan="4">Total</td><td class="r">{{ $fmt($tot['amount']) }}</td><td class="r">{{ $fmt($tot['fee']) }}</td><td class="r">{{ $fmt($tot['bank_fee']) }}</td><td class="r">{{ $fmt($tot['profit']) }}</td><td></td></tr></tfoot>
            @endif
        </table>
    </div>
</x-filament-panels::page>
