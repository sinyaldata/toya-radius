<x-filament-widgets::widget>
    <style>
        .tya-toplists { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        @media (min-width: 1024px) {
            .tya-toplists { grid-template-columns: 1fr 1fr; }
        }
        .tya-card { background: #fff; border-radius: 0.85rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03), 0 4px 14px rgba(0,0,0,0.02); overflow: hidden; }
        .dark .tya-card { background: #27272a; }
        /* Tinggi minimum yang SAMA dengan kartu "Aktivitas Login Pengguna"
           (lihat login-activity-widget.blade.php) supaya ketiga kartu di
           baris dashboard ini tingginya rata, walau isinya beda-beda
           panjang. Cuma mulai breakpoint lg (>=1024px) -- di HP/tablet
           kartu ini tetap menyesuaikan isi seperti biasa. */
        @media (min-width: 1024px) {
            .tya-card { display: flex; flex-direction: column; min-height: 420px; }
            .tya-empty { flex: 1 1 auto; display: flex; align-items: center; justify-content: center; }
        }
        .tya-card-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.85rem 1rem; border-bottom: 1px solid rgba(0,0,0,0.06); flex-wrap: wrap; }
        .dark .tya-card-header { border-bottom-color: rgba(255,255,255,0.08); }
        .tya-card-title { display: flex; align-items: center; gap: 0.4rem; font-size: 0.9rem; font-weight: 700; color: #18181b; }
        .dark .tya-card-title { color: #fff; }
        .tya-card-title svg { width: 16px; height: 16px; color: #f97316; }
        .tya-pill-group { display: flex; gap: 0.35rem; flex-wrap: wrap; }
        .tya-pill { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.6875rem; font-weight: 700; }
        .tya-pill.paid { background: #f97316; color: #fff; }
        .tya-pill.unpaid { background: #fee2e2; color: #b91c1c; }
        .tya-pill.overdue { background: #fef3c7; color: #92400e; }
        .tya-pill.total { background: #f4f4f5; color: #52525b; }
        .dark .tya-pill.unpaid { background: rgba(239,68,68,0.15); }
        .dark .tya-pill.overdue { background: rgba(245,158,11,0.15); }
        .dark .tya-pill.total { background: rgba(255,255,255,0.08); color: #d4d4d8; }

        .tya-table-wrap { overflow-x: auto; }
        table.tya-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
        table.tya-table th { text-align: left; padding: 0.5rem 1rem; font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: #a1a1aa; border-bottom: 1px solid rgba(0,0,0,0.06); white-space: nowrap; }
        .dark table.tya-table th { border-bottom-color: rgba(255,255,255,0.08); }
        table.tya-table td { padding: 0.6rem 1rem; border-bottom: 1px solid rgba(0,0,0,0.04); vertical-align: middle; }
        .dark table.tya-table td { border-bottom-color: rgba(255,255,255,0.05); }
        table.tya-table tr:last-child td { border-bottom: none; }

        .tya-avatar { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 9999px; font-size: 0.6875rem; font-weight: 700; flex: 0 0 auto; }
        .tya-avatar.hotspot { background: rgba(59,130,246,0.12); color: #2563eb; }
        .tya-avatar.pppoe { background: rgba(34,197,94,0.12); color: #16a34a; }
        .tya-row-flex { display: flex; align-items: center; gap: 0.5rem; }
        .tya-main-text { font-weight: 600; color: #18181b; }
        .dark .tya-main-text { color: #fff; }
        .tya-sub-text { color: #a1a1aa; font-size: 0.6875rem; margin-top: 0.1rem; }
        .tya-code { font-family: 'Courier New', monospace; font-weight: 700; color: #ea580c; }
        .tya-badge { display: inline-block; padding: 0.05rem 0.5rem; border-radius: 9999px; font-size: 0.625rem; font-weight: 700; background: rgba(34,197,94,0.12); color: #16a34a; margin-inline-start: 0.35rem; }

        .tya-pagination { display: flex; align-items: center; justify-content: center; gap: 0.3rem; padding: 0.75rem; border-top: 1px solid rgba(0,0,0,0.06); }
        .dark .tya-pagination { border-top-color: rgba(255,255,255,0.08); }
        .tya-pagination button { min-width: 26px; height: 26px; border-radius: 0.4rem; border: 1px solid rgba(0,0,0,0.1); background: #fff; font-size: 0.75rem; cursor: pointer; color: #52525b; }
        .dark .tya-pagination button { background: #18181b; border-color: rgba(255,255,255,0.1); color: #d4d4d8; }
        .tya-pagination button.active { background: #f97316; color: #fff; border-color: #f97316; }
        .tya-pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
        .tya-empty { padding: 2rem 1rem; text-align: center; color: #a1a1aa; font-size: 0.8125rem; }
    </style>

    <div class="tya-toplists">
        {{-- ===== TOP 20 INVOICE ===== --}}
        <div class="tya-card">
            <div class="tya-card-header">
                <div class="tya-card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Top 20 Invoice
                </div>
                @php($ic = $this->getInvoiceCounts())
                <div class="tya-pill-group">
                    <span class="tya-pill paid">Paid {{ $ic['paid'] }}</span>
                    <span class="tya-pill unpaid">Unpaid {{ $ic['unpaid'] }}</span>
                    <span class="tya-pill overdue">Overdue {{ $ic['overdue'] }}</span>
                </div>
            </div>

            @php($invoiceRows = $this->getInvoiceRows())

            @if ($invoiceRows->isEmpty())
                <div class="tya-empty">Belum ada invoice.</div>
            @else
                <div class="tya-table-wrap">
                    <table class="tya-table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Pelanggan</th>
                                <th>Dibayar Pada</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoiceRows as $invoice)
                                <tr>
                                    <td>
                                        <div class="tya-main-text">{{ $invoice->invoice_number }}</div>
                                        <div class="tya-sub-text">
                                            Rp{{ number_format($invoice->amount, 0, ',', '.') }}
                                            <span class="tya-badge" style="{{ $invoice->status->value !== 'paid' ? 'background:rgba(161,161,170,.15);color:#71717a;' : '' }}">
                                                {{ $invoice->status->label() }}
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="tya-row-flex">
                                            <span class="tya-avatar {{ $invoice->customer?->type?->value === 'pppoe' ? 'pppoe' : 'hotspot' }}">
                                                {{ strtoupper(substr($invoice->customer?->type?->value === 'pppoe' ? 'P' : 'H', 0, 1)) }}
                                            </span>
                                            <div>
                                                <div class="tya-main-text">{{ $invoice->customer?->name ?? '-' }}</div>
                                                <div class="tya-sub-text">{{ $invoice->customer?->outlet?->name ?? 'Stok Pusat' }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="tya-sub-text">{{ $invoice->paid_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @php($invoiceTotalPages = $this->getInvoiceTotalPages())
                @if ($invoiceTotalPages > 1)
                    <div class="tya-pagination">
                        <button wire:click="goToInvoicePage({{ $invoicePage - 1 }})" @disabled($invoicePage <= 1)>&lsaquo;</button>
                        @for ($p = 1; $p <= $invoiceTotalPages; $p++)
                            <button wire:click="goToInvoicePage({{ $p }})" class="{{ $p === $invoicePage ? 'active' : '' }}">{{ $p }}</button>
                        @endfor
                        <button wire:click="goToInvoicePage({{ $invoicePage + 1 }})" @disabled($invoicePage >= $invoiceTotalPages)>&rsaquo;</button>
                    </div>
                @endif
            @endif
        </div>

        {{-- ===== TOP 20 VOUCHER SALES ===== --}}
        <div class="tya-card">
            <div class="tya-card-header">
                <div class="tya-card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 8h6m-5 4h4m-7 4h10a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v3a2 2 0 100 4v3a2 2 0 002 2z" />
                    </svg>
                    Top 20 Voucher Sales
                </div>
                @php($vc = $this->getVoucherCounts())
                <div class="tya-pill-group">
                    <span class="tya-pill paid">Terjual {{ $vc['total'] }}</span>
                </div>
            </div>

            @php($voucherRows = $this->getVoucherRows())

            @if ($voucherRows->isEmpty())
                <div class="tya-empty">Belum ada penjualan voucher.</div>
            @else
                <div class="tya-table-wrap">
                    <table class="tya-table">
                        <thead>
                            <tr>
                                <th>Kode Trx</th>
                                <th>Kode Voucher</th>
                                <th>Penjual</th>
                                <th>Dibayar Pada</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($voucherRows as $trx)
                                <tr>
                                    <td>
                                        <div class="tya-main-text">{{ $trx->transaction_number }}</div>
                                        <div class="tya-sub-text">Rp{{ number_format($trx->total_amount, 0, ',', '.') }}</div>
                                    </td>
                                    <td>
                                        <div class="tya-code">{{ $trx->voucher?->username ?? '-' }}</div>
                                        <div class="tya-sub-text">{{ $trx->voucher?->profile?->name ?? '-' }}</div>
                                    </td>
                                    <td class="tya-sub-text">{{ $trx->outlet?->name ?? $trx->cashier?->name ?? 'Stok Pusat' }}</td>
                                    <td class="tya-sub-text">{{ $trx->transaction_date?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @php($voucherTotalPages = $this->getVoucherTotalPages())
                @if ($voucherTotalPages > 1)
                    <div class="tya-pagination">
                        <button wire:click="goToVoucherPage({{ $voucherPage - 1 }})" @disabled($voucherPage <= 1)>&lsaquo;</button>
                        @for ($p = 1; $p <= $voucherTotalPages; $p++)
                            <button wire:click="goToVoucherPage({{ $p }})" class="{{ $p === $voucherPage ? 'active' : '' }}">{{ $p }}</button>
                        @endfor
                        <button wire:click="goToVoucherPage({{ $voucherPage + 1 }})" @disabled($voucherPage >= $voucherTotalPages)>&rsaquo;</button>
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
