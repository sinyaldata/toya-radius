<x-filament-panels::page>
    <style>
        .matm-cards { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .75rem; }
        .matm-card.is-month { border-color: rgba(22,163,74,.45); background: rgba(22,163,74,.07); }
        .matm-card.is-month strong { color: #16a34a; }
        @media (max-width: 1280px) { .matm-cards { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .matm-accs { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: .6rem; margin-top: .75rem; }
        .matm-acc { border: 1px solid rgba(37,99,235,.35); border-radius: 12px; padding: .65rem .85rem; background: rgba(37,99,235,.06); }
        .matm-acc small { display: flex; justify-content: space-between; gap: .5rem; font-size: .72rem; opacity: .8; }
        .matm-acc strong { display: block; font-size: 1.05rem; margin-top: .15rem; }
        .matm-acc.is-low strong { color: #dc2626; }
        .matm-card.is-total { border-color: rgba(217,119,6,.5); background: rgba(217,119,6,.08); }
        .matm-card.is-total strong { color: #b45309; font-size: 1.5rem; }
        .matm-card { border: 1px solid rgba(128,128,128,.25); border-radius: 14px; padding: 1rem; background: rgba(128,128,128,.05); }
        .matm-card small { display: block; font-size: .75rem; opacity: .7; margin-bottom: .25rem; }
        .matm-card strong { font-size: 1.35rem; font-weight: 700; }
        .matm-card.is-cash strong { color: #16a34a; }
        .matm-card.is-bank strong { color: #2563eb; }
        .matm-card.is-profit strong { color: #d97706; }
        .matm-bar { display: flex; gap: .75rem; align-items: center; justify-content: space-between; flex-wrap: wrap; margin: 1rem 0 .5rem; }
        .matm-wrap { overflow-x: auto; border: 1px solid rgba(128,128,128,.25); border-radius: 12px; }
        .matm-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .matm-table th, .matm-table td { padding: .55rem .75rem; text-align: left; white-space: nowrap; border-bottom: 1px solid rgba(128,128,128,.15); }
        .matm-table th { font-size: .72rem; text-transform: uppercase; opacity: .7; }
        .matm-table .r { text-align: right; }
        .matm-tag { display: inline-block; padding: .1rem .5rem; border-radius: 999px; font-size: .72rem; font-weight: 600; background: rgba(128,128,128,.15); }
        .matm-tag.tarik { background: rgba(22,163,74,.15); color: #16a34a; }
        .matm-tag.setor { background: rgba(217,119,6,.15); color: #d97706; }
        .matm-tag.transfer { background: rgba(37,99,235,.15); color: #2563eb; }
        .is-void td { opacity: .45; text-decoration: line-through; }
        .matm-act { color: #d97706; font-size: .78rem; font-weight: 600; cursor: pointer; background: none; border: 0; padding: .1rem .35rem; }
        .matm-act.is-del { color: #dc2626; }
        .matm-act:hover { text-decoration: underline; }
        @media (max-width: 639px) {
            .matm-cards { grid-template-columns: 1fr 1fr; }
            .matm-card.is-total { grid-column: span 2; order: -1; }
            .matm-card strong { font-size: 1.1rem; }
        }
    </style>

    @php
        $bal = $this->balances();
        $sum = $this->daySummary();
        $mon = $this->monthSummary();
        $rows = $this->transactions();
        $accs = $this->accountCards();
        $fmt = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    @endphp

    <div class="matm-cards">
        <div class="matm-card is-total"><small>Total Saldo (Cash + Rekening)</small><strong>{{ $fmt($bal['total']) }}</strong></div>
        <div class="matm-card is-cash"><small>Saldo Uang Cash</small><strong>{{ $fmt($bal['cash']) }}</strong></div>
        <div class="matm-card is-bank"><small>Saldo Rekening{{ count($accs) > 1 ? ' (' . count($accs) . ' rekening)' : '' }}</small><strong>{{ $fmt($bal['bank']) }}</strong></div>
        <div class="matm-card is-profit"><small>Pendapatan Bersih Hari Ini ({{ $sum['count'] }} trx)</small><strong>{{ $fmt($sum['profit']) }}</strong></div>
        <div class="matm-card is-month"><small>Pendapatan Bersih {{ $mon['label'] }} ({{ $mon['count'] }} trx)</small><strong>{{ $fmt($mon['profit']) }}</strong>@if ($mon['bank_fee'] > 0)<small style="margin-top:.2rem;">Jasa {{ $fmt($mon['fee']) }} − adm {{ $fmt($mon['bank_fee']) }}</small>@endif</div>
    </div>

    @if (count($accs) > 1)
        <div class="matm-accs">
            @foreach ($accs as $a)
                <div class="matm-acc {{ $a['balance'] < 0 ? 'is-low' : '' }}">
                    <small><span>{{ $a['name'] }}</span><button type="button" class="matm-act" wire:click="mountAction('editAccount', { id: {{ $a['id'] }} })">Ubah</button></small>
                    <strong>{{ $fmt($a['balance']) }}</strong>
                    @if ($a['bank'] !== '')<small>{{ $a['bank'] }}</small>@endif
                </div>
            @endforeach
        </div>
    @endif

    <div class="matm-bar">
        <strong>Riwayat Transaksi</strong>
        <x-filament::input.wrapper style="max-width: 11rem;">
            <x-filament::input type="date" wire:model.live="date" />
        </x-filament::input.wrapper>
    </div>

    <div class="matm-wrap">
        <table class="matm-table">
            <thead>
                <tr>
                    <th>Waktu</th><th>No.</th><th>Jenis</th><th>Rekening Saya</th><th>Bank / Rekening Tujuan</th>
                    <th class="r">Nominal</th><th class="r">Jasa</th><th class="r">Adm. Bank</th><th>Petugas</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $t)
                    <tr class="{{ $t->is_void ? 'is-void' : '' }}">
                        <td>{{ $t->transacted_at->format('H:i') }}</td>
                        <td>{{ $t->number }}</td>
                        <td><span class="matm-tag {{ $t->type }}">{{ $t->type_label }}</span></td>
                        <td>{{ $t->type === 'pindah' ? ($t->account?->name . ' → ' . $t->toAccount?->name) : ($t->account?->name ?? '-') }}</td>
                        <td>{{ trim(($t->bank_name ?? '') . ' ' . ($t->account_number ?? '') . ' ' . ($t->account_name ? '· ' . $t->account_name : '')) ?: '-' }}</td>
                        <td class="r">{{ $t->type === 'koreksi' ? '-' : $fmt($t->amount) }}</td>
                        <td class="r">{{ $fmt($t->fee) }}</td>
                        <td class="r">{{ $fmt($t->bank_fee) }}</td>
                        <td>{{ $t->user?->name ?? '-' }}</td>
                        <td style="white-space: nowrap;">
                            @if (! $t->is_void)
                                <button type="button" class="matm-act" wire:click="mountAction('editTx', { id: {{ $t->id }} })">Edit</button>
                                <button type="button" class="matm-act" wire:click="voidTransaction({{ $t->id }})" wire:confirm="Batalkan transaksi {{ $t->number }}? Saldo akan dikembalikan.">Batalkan</button>
                            @else
                                <span class="matm-tag">Batal</span>
                            @endif
                            <button type="button" class="matm-act is-del" wire:click="mountAction('deleteTx', { id: {{ $t->id }} })">Hapus</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" style="text-align:center; padding: 1.5rem; opacity:.6;">Belum ada transaksi pada tanggal ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
