<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @php($s = $this->getSummary())
    @php($expenseBreakdown = $this->getExpenseBreakdown())

    <style>
        .plr-card { background: #fff; border: 1px solid rgb(228 228 231); border-radius: 0.85rem; overflow: hidden; margin: 1.5rem 0; }
        .dark .plr-card { background: #27272a; border-color: rgba(255,255,255,0.08); }
        table.plr-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        table.plr-table td { padding: 0.7rem 1.5rem; border-bottom: 1px solid rgb(244 244 245); }
        .dark table.plr-table td { border-bottom-color: rgba(255,255,255,0.05); }
        table.plr-table tr:last-child td { border-bottom: none; }
        .plr-label { color: #52525b; }
        .dark .plr-label { color: #a1a1aa; }
        .plr-amount { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
        .plr-section-head td { background: #fafafa; font-weight: 700; text-transform: uppercase; font-size: 0.6875rem; letter-spacing: 0.04em; color: #71717a; }
        .dark .plr-section-head td { background: #18181b; color: #a1a1aa; }
        .plr-sub { padding-left: 2.5rem !important; color: #71717a; font-size: 0.8125rem; }
        .plr-total-row td { font-weight: 700; border-top: 2px solid rgb(228 228 231); }
        .dark .plr-total-row td { border-top-color: rgba(255,255,255,0.15); }
        .plr-minus { color: #dc2626; }
        .plr-plus { color: #16a34a; }
        .plr-net { padding: 1.25rem 1.5rem; font-size: 1.5rem; font-weight: 800; display: flex; justify-content: space-between; align-items: center; }
        .plr-net.positive { background: #f0fdf4; color: #15803d; }
        .plr-net.negative { background: #fef2f2; color: #b91c1c; }
        .dark .plr-net.positive { background: rgba(34,197,94,0.1); }
        .dark .plr-net.negative { background: rgba(239,68,68,0.1); }
    </style>

    <div class="plr-card">
        <table class="plr-table">
            <tr class="plr-section-head"><td colspan="2">Pendapatan</td></tr>
            <tr>
                <td class="plr-sub">Penjualan Voucher (Hotspot)</td>
                <td class="plr-amount plr-plus">Rp{{ number_format($s['pendapatan_voucher'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="plr-sub">Tagihan PPPoE Lunas</td>
                <td class="plr-amount plr-plus">Rp{{ number_format($s['pendapatan_invoice'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="plr-sub">Pendapatan Lain (pasang baru, jasa, dll)</td>
                <td class="plr-amount plr-plus">Rp{{ number_format($s['pendapatan_lain'] ?? 0, 0, ',', '.') }}</td>
            </tr>
            <tr class="plr-total-row">
                <td>Total Pendapatan</td>
                <td class="plr-amount">Rp{{ number_format($s['total_pendapatan'], 0, ',', '.') }}</td>
            </tr>

            <tr class="plr-section-head"><td colspan="2">Beban / Modal</td></tr>
            <tr>
                <td class="plr-sub">Modal (Harga Pokok) Voucher Terjual</td>
                <td class="plr-amount plr-minus">Rp{{ number_format($s['modal_voucher'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="plr-sub">Komisi Outlet/Reseller</td>
                <td class="plr-amount plr-minus">Rp{{ number_format($s['komisi_outlet'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="plr-sub">Pengeluaran Operasional</td>
                <td class="plr-amount plr-minus">Rp{{ number_format($s['pengeluaran'], 0, ',', '.') }}</td>
            </tr>
            <tr class="plr-total-row">
                <td>Total Beban</td>
                <td class="plr-amount">Rp{{ number_format($s['total_beban'], 0, ',', '.') }}</td>
            </tr>
        </table>

        <div class="plr-net {{ $s['laba_bersih'] >= 0 ? 'positive' : 'negative' }}">
            <span>{{ $s['laba_bersih'] >= 0 ? 'Laba Bersih' : 'Rugi Bersih' }}</span>
            <span>Rp{{ number_format(abs($s['laba_bersih']), 0, ',', '.') }}</span>
        </div>
    </div>

    @if ($expenseBreakdown->isNotEmpty())
        <x-filament::section heading="Rincian Pengeluaran per Kategori">
            <table class="plr-table" style="width:100%">
                @foreach ($expenseBreakdown as $category => $amount)
                    <tr>
                        <td class="plr-label">{{ $category }}</td>
                        <td class="plr-amount">Rp{{ number_format($amount, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </table>
        </x-filament::section>
    @endif

    <p class="text-xs text-gray-400 mt-4">
        Modal voucher dihitung dari harga pokok (cost price) profile pada saat transaksi terjadi.
        Komisi dihitung dari persentase komisi Outlet yang berlaku saat ini pada tiap transaksinya.
        Pastikan harga pokok tiap profile sudah diisi dengan benar di menu Profile agar laporan ini akurat.
    </p>
</x-filament-panels::page>
