<x-filament-panels::page>
    <form wire:submit.prevent="terapkanFilter">
        {{ $this->form }}

        <div style="display:flex; justify-content:flex-end; gap:.5rem; margin-top:1rem;">
            <x-filament::button
                type="submit"
                icon="lucide-list-filter"
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove wire:target="terapkanFilter">Terapkan Filter</span>
                <span wire:loading wire:target="terapkanFilter">Memproses...</span>
            </x-filament::button>
        </div>
    </form>

    @php($ringkasan = $this->getRingkasan())

    <style>
        .bkm-card { background: #fff; border: 1px solid rgb(228 228 231); border-radius: 0.85rem; overflow: hidden; margin-bottom: 1.5rem; }
        .dark .bkm-card { background: #27272a; border-color: rgba(255,255,255,0.08); }
        table.bkm-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        table.bkm-table th { text-align: left; padding: 0.6rem 0.75rem; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: #71717a; background: #fafafa; border-bottom: 1px solid rgb(228 228 231); }
        .dark table.bkm-table th { background: #18181b; color: #a1a1aa; border-bottom-color: rgba(255,255,255,0.08); }
        table.bkm-table td { padding: 0.6rem 0.75rem; border-bottom: 1px solid rgb(244 244 245); }
        .dark table.bkm-table td { border-bottom-color: rgba(255,255,255,0.05); }
        table.bkm-table tr:last-child td { border-bottom: none; }
        .bkm-badge { display: inline-flex; align-items: center; gap: .3rem; font-size: .68rem; font-weight: 600; padding: .2rem .55rem; border-radius: 999px; }
        .bkm-badge-lunas { background: rgba(34,197,94,.12); color: #16a34a; }
        .bkm-badge-belum { background: rgba(161,161,170,.15); color: #71717a; }
        .bkm-right { text-align: right; }
    </style>

    @if (! $ringkasan)
        <x-filament::section>
            <div class="text-center text-gray-500 py-6">
                @if (Auth::user()?->isSuperAdmin() || Auth::user()?->isAdmin())
                    Pilih Biller dulu di atas, lalu klik "Terapkan Filter".
                @else
                    Akun Anda belum terhubung ke data Biller manapun.
                @endif
            </div>
        </x-filament::section>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 my-6">
            <x-filament::section>
                <div class="text-sm text-gray-500">Total Diterbitkan ({{ $ringkasan['jumlah_invoice'] }} invoice)</div>
                <div class="text-2xl font-bold">Rp{{ number_format($ringkasan['total_diterbitkan'], 0, ',', '.') }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">Sudah Ditagih/Lunas ({{ $ringkasan['jumlah_lunas'] }} invoice)</div>
                <div class="text-2xl font-bold text-success-600">Rp{{ number_format($ringkasan['total_dibayar'], 0, ',', '.') }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">Komisi Anda</div>
                <div class="text-2xl font-bold" style="color:#ea580c;">Rp{{ number_format($ringkasan['total_komisi'], 0, ',', '.') }}</div>
            </x-filament::section>
        </div>

        <div class="bkm-card">
            <table class="bkm-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Pelanggan</th>
                        <th>Status</th>
                        <th class="bkm-right">Nominal</th>
                        <th class="bkm-right">Komisi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ringkasan['rincian'] as $baris)
                        <tr>
                            <td>{{ $baris['invoice']->invoice_number ?? $baris['invoice']->id }}</td>
                            <td>{{ $baris['pelanggan'] }}</td>
                            <td>
                                @if ($baris['lunas'])
                                    <span class="bkm-badge bkm-badge-lunas">Lunas</span>
                                @else
                                    <span class="bkm-badge bkm-badge-belum">Belum Lunas</span>
                                @endif
                            </td>
                            <td class="bkm-right">Rp{{ number_format($baris['invoice']->amount, 0, ',', '.') }}</td>
                            <td class="bkm-right">
                                @if ($baris['lunas'])
                                    Rp{{ number_format($baris['komisi'], 0, ',', '.') }}
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-gray-400 py-6">Tidak ada invoice untuk periode ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
