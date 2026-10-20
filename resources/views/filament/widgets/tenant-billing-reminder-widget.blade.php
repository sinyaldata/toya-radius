<x-filament-widgets::widget>
    <style>
        .tya-billing-alert { display: flex; align-items: flex-start; gap: 0.85rem; padding: 1rem 1.15rem; border-radius: 0.85rem; border: 1px solid; }
        .tya-billing-alert.warn { background: #fffbeb; border-color: #fde68a; }
        .dark .tya-billing-alert.warn { background: rgba(245,158,11,0.1); border-color: rgba(245,158,11,0.35); }
        .tya-billing-alert.danger { background: #fef2f2; border-color: #fecaca; }
        .dark .tya-billing-alert.danger { background: rgba(239,68,68,0.1); border-color: rgba(239,68,68,0.35); }
        .tya-billing-icon { flex: 0 0 34px; width: 34px; height: 34px; border-radius: 9999px; display: flex; align-items: center; justify-content: center; }
        .tya-billing-alert.warn .tya-billing-icon { background: rgba(245,158,11,0.18); color: #b45309; }
        .tya-billing-alert.danger .tya-billing-icon { background: rgba(239,68,68,0.18); color: #b91c1c; }
        .tya-billing-icon svg { width: 18px; height: 18px; }
        .tya-billing-title { font-size: 0.9rem; font-weight: 700; margin: 0 0 0.2rem; }
        .tya-billing-alert.warn .tya-billing-title { color: #92400e; }
        .dark .tya-billing-alert.warn .tya-billing-title { color: #fcd34d; }
        .tya-billing-alert.danger .tya-billing-title { color: #991b1b; }
        .dark .tya-billing-alert.danger .tya-billing-title { color: #fca5a5; }
        .tya-billing-text { font-size: 0.8125rem; margin: 0; color: #57534e; line-height: 1.5; }
        .dark .tya-billing-text { color: #d6d3d1; }
        .tya-billing-text b { color: inherit; }
    </style>

    @php($tenant = $this->getTenant())

    @if ($tenant)
        @php($sisaHari = $tenant->daysUntilBillingDue())
        @php($terlambat = $sisaHari !== null && $sisaHari < 0)

        <div class="tya-billing-alert {{ $terlambat ? 'danger' : 'warn' }}">
            <div class="tya-billing-icon">
                @if ($terlambat)
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
                    </svg>
                @endif
            </div>

            <div>
                @if ($terlambat)
                    <p class="tya-billing-title">Langganan sudah lewat jatuh tempo {{ abs($sisaHari) }} hari</p>
                @elseif ($sisaHari === 0)
                    <p class="tya-billing-title">Langganan jatuh tempo HARI INI</p>
                @else
                    <p class="tya-billing-title">Langganan jatuh tempo {{ $sisaHari }} hari lagi</p>
                @endif

                <p class="tya-billing-text">
                    Tanggal jatuh tempo: <b>{{ $tenant->billing_due_date->translatedFormat('d F Y') }}</b>
                    @if ($tenant->plan)
                        &middot; Paket: <b>{{ $tenant->plan->name }}</b>
                        @if ($tenant->plan->price_per_month)
                            (Rp{{ number_format($tenant->plan->price_per_month, 0, ',', '.') }}/bulan)
                        @endif
                    @endif
                    <br>
                    Segera lakukan pembayaran supaya layanan tidak terganggu. Hubungi admin Toya Radius kalau sudah transfer atau ada pertanyaan soal tagihan ini.
                </p>
            </div>
        </div>
    @endif
</x-filament-widgets::widget>
