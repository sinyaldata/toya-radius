<x-filament-panels::page>
    {{-- LAPORAN INVOICE -- desain ulang 2026-10-17 (seragam dengan Laporan
         Penjualan): baris filter ringkas [Tahun][tombol corong], 2 kartu
         ringkasan (di HP otomatis 2 kolom lewat blok KARTU-SLIDE-V2), lalu
         tabel rekap 12 bulan yang rapi. Logic halaman (getMatrix, filter,
         export) TIDAK diubah. Kelas baru berawalan .tya-ivr-. --}}
    <style>
        .tya-ivr { --ivr-card: #ffffff; --ivr-border: #ececef; --ivr-muted: #71717a; --ivr-text: #18181b; --ivr-soft: #fafafa; display: flex; flex-direction: column; gap: 1rem; }
        .dark .tya-ivr { --ivr-card: #1c1c20; --ivr-border: rgba(255,255,255,.08); --ivr-muted: #a1a1aa; --ivr-text: #f4f4f5; --ivr-soft: rgba(255,255,255,.03); }

        /* ---------- Filter: [Tahun][corong] ---------- */
        .tya-ivr-filter { background: var(--ivr-card); border: 1px solid var(--ivr-border); border-radius: 18px; padding: .9rem 1rem 1rem; box-shadow: 0 8px 18px -12px rgba(234,88,12,.35); }
        .tya-ivr-fjudul { display: flex; align-items: center; gap: .55rem; margin-bottom: .6rem; font-size: .8rem; font-weight: 700; color: var(--ivr-muted); }
        .tya-ivr-fjudul svg { width: 1rem; height: 1rem; color: #ea580c; }
        .tya-ivr-frow { display: flex; align-items: flex-end; gap: .6rem; }
        .tya-ivr-frow > .tya-ivr-fform { flex: 1 1 auto; min-width: 0; }
        /* isian Tahun: tanpa kotak ikon global, label disembunyikan (sudah ada judul) */
        .tya-ivr-filter .fi-fo-field:not(#tya-x) { padding-inline-start: 0 !important; min-height: 0 !important; }
        .tya-ivr-filter .fi-fo-field:not(#tya-x)::before,
        .tya-ivr-filter .fi-fo-field:not(#tya-x)::after { content: none !important; display: none !important; }
        .tya-ivr-filter .fi-fo-field:not(#tya-x) :is(.fi-fo-field-label, .fi-fo-field-label-ctn) { display: none !important; }
        .tya-ivr-filter .fi-fo-field:not(#tya-x) .fi-input-wrp { min-height: 46px !important; border-radius: 12px !important; }
        .tya-ivr-filter .fi-fo-field:not(#tya-x) .fi-select-input-value-remove-btn { display: none !important; }
        .tya-ivr-filter .fi-fo-field:not(#tya-x) .fi-select-input-btn { min-height: 44px !important; }
        /* tombol corong = sama dengan tombol filter di halaman lain */
        .tya-ivr-go { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 46px; border: 0; border-radius: 12px; cursor: pointer; color: #fff; background: linear-gradient(135deg, #fb923c, #ea580c); box-shadow: 0 6px 14px -6px rgba(234,88,12,.7); -webkit-tap-highlight-color: transparent; }
        .tya-ivr-go:active { transform: scale(.94); }
        .tya-ivr-go svg { width: 21px; height: 21px; }
        .tya-ivr-go .tya-ivr-putar { display: none; animation: tya-ivr-putar .8s linear infinite; }
        .tya-ivr-go[disabled] { opacity: .85; }
        .tya-ivr-go[disabled] .tya-ivr-corong { display: none; }
        .tya-ivr-go[disabled] .tya-ivr-putar { display: block; }
        @keyframes tya-ivr-putar { to { transform: rotate(360deg); } }

        /* ---------- Kartu ringkasan (PC; di HP diatur KARTU-SLIDE-V2) ---------- */
        .tya-ivr .tya-sc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        .tya-ivr .tya-sc-card { position: relative; overflow: hidden; display: block; background: var(--ivr-card); border: 1px solid var(--ivr-border); border-radius: 16px; padding: 1.1rem 1.25rem; }
        .tya-ivr .tya-sc-accent { display: none; }
        .tya-ivr .tya-sc-row { display: flex; align-items: center; justify-content: space-between; gap: .85rem; }
        .tya-ivr .tya-sc-label { font-size: .78rem; font-weight: 700; color: var(--ivr-muted); }
        .tya-ivr .tya-sc-value { font-size: 1.45rem; font-weight: 800; line-height: 1.15; margin-top: .25rem; color: var(--ivr-text); white-space: nowrap; }
        .tya-ivr .tya-sc-note { font-size: .72rem; margin-top: .3rem; color: var(--ivr-muted); }
        .tya-ivr .tya-sc-icon { width: 2.75rem; height: 2.75rem; border-radius: 13px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; }
        .tya-ivr .tya-sc-icon svg { width: 1.3rem; height: 1.3rem; }

        /* ---------- Tabel rekap ---------- */
        .tya-ivr-box { background: var(--ivr-card); border: 1px solid var(--ivr-border); border-radius: 18px; overflow: hidden; }
        .tya-ivr-head { display: flex; align-items: center; justify-content: space-between; gap: .6rem; padding: .9rem 1.1rem; border-bottom: 1px solid var(--ivr-border); }
        .tya-ivr-head b { display: block; font-size: .92rem; font-weight: 800; color: var(--ivr-text); }
        .tya-ivr-head small { display: block; font-size: .72rem; color: var(--ivr-muted); margin-top: .1rem; }
        .tya-ivr-chip { flex-shrink: 0; display: inline-flex; align-items: center; gap: .35rem; padding: .4rem .75rem; border-radius: 999px; background: #fff7ed; color: #c2410c; font-size: .74rem; font-weight: 800; white-space: nowrap; }
        .dark .tya-ivr-chip { background: rgba(249,115,22,.14); color: #fdba74; }
        .tya-ivr-chip svg { width: .9rem; height: .9rem; }
        .tya-ivr-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table.tya-ivr-tbl { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .78rem; white-space: nowrap; }
        table.tya-ivr-tbl th { padding: .6rem .65rem; font-size: .64rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--ivr-muted); background: var(--ivr-soft); border-bottom: 1px solid var(--ivr-border); text-align: right; }
        table.tya-ivr-tbl td { padding: .6rem .65rem; border-bottom: 1px solid var(--ivr-border); text-align: right; color: var(--ivr-text); background: var(--ivr-card); }
        table.tya-ivr-tbl tbody tr:last-child td { border-bottom: 0; }
        .tya-ivr-tbl .no { width: 2.2rem; text-align: center; color: var(--ivr-muted); }
        .tya-ivr-tbl .nm { position: sticky; left: 0; z-index: 1; text-align: left; font-weight: 700; max-width: 11rem; overflow: hidden; text-overflow: ellipsis; box-shadow: 1px 0 0 var(--ivr-border); }
        .tya-ivr-tbl th.nm { background: var(--ivr-soft); }
        .dark .tya-ivr-tbl th.nm { background: #222227; }
        .tya-ivr-tbl .nm small { display: block; font-weight: 500; font-size: .68rem; color: var(--ivr-muted); overflow: hidden; text-overflow: ellipsis; }
        .tya-ivr-tbl .isi { color: #16a34a; font-weight: 700; }
        .dark .tya-ivr-tbl .isi { color: #4ade80; }
        .tya-ivr-tbl .kosong { color: #d4d4d8; }
        .dark .tya-ivr-tbl .kosong { color: #3f3f46; }
        .tya-ivr-tbl .tot { font-weight: 800; }
        .tya-ivr-tbl tfoot td { background: #fff7ed; font-weight: 800; border-top: 1px solid var(--ivr-border); border-bottom: 0; }
        .dark .tya-ivr-tbl tfoot td { background: #2a1f17; }
        .tya-ivr-tbl tfoot .nm { color: #c2410c; }
        .dark .tya-ivr-tbl tfoot .nm { color: #fdba74; }
        .tya-ivr-empty { padding: 2.5rem 1rem; text-align: center; color: var(--ivr-muted); font-size: .84rem; }

        @media (max-width: 767px) {
            .tya-ivr { gap: .85rem; }
            .tya-ivr-head { padding: .8rem .9rem; }
            table.tya-ivr-tbl { font-size: .74rem; }
            table.tya-ivr-tbl th, table.tya-ivr-tbl td { padding: .55rem .55rem; }
            .tya-ivr-tbl .no { display: none; }
            .tya-ivr-tbl .nm { max-width: 8.5rem; }
            /* tidak ada ruang kosong di atas tombol Excel/PDF */
            html body .fi-page:has(.tya-ivr) .fi-header { padding-block: .4rem !important; min-height: 0 !important; }
            html body .fi-page:has(.tya-ivr) .fi-page-content { row-gap: .85rem !important; }
        }
    </style>

    @php
        $matrix = $this->getMatrix();
        $monthlyTotals = $this->getMonthlyTotals();
        $grandTotal = $this->getGrandTotal();
        $bulanLabel = \App\Filament\Pages\InvoiceReportPage::bulanLabels();
        $tahun = $this->filters['year'] ?? now()->year;
        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
        $bulanTerisi = collect($monthlyTotals)->filter(fn ($t) => (float) $t > 0)->count();
    @endphp

    <div class="tya-ivr">
        {{-- ===== Filter ===== --}}
        <form class="tya-ivr-filter" wire:submit.prevent="terapkanFilter">
            <div class="tya-ivr-fjudul">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
                Tahun laporan
            </div>
            <div class="tya-ivr-frow">
                <div class="tya-ivr-fform">{{ $this->form }}</div>
                <button type="submit" class="tya-ivr-go" title="Terapkan filter" aria-label="Terapkan filter" wire:loading.attr="disabled" wire:target="terapkanFilter">
                    <svg class="tya-ivr-corong" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 4.5h17l-6.5 8v6l-4 2v-8z"/></svg>
                    <svg class="tya-ivr-putar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 3a9 9 0 1 0 9 9"/></svg>
                </button>
            </div>
        </form>

        {{-- ===== Ringkasan ===== --}}
        <div class="tya-sc-grid">
            <div class="tya-sc-card">
                <div class="tya-sc-accent" style="background: #f97316;"></div>
                <div class="tya-sc-row">
                    <div style="min-width:0;">
                        <div class="tya-sc-label">Pelanggan Bayar</div>
                        <div class="tya-sc-value">{{ number_format($matrix->count(), 0, ',', '.') }}</div>
                        <div class="tya-sc-note">Lunas min. 1x di {{ $tahun }}</div>
                    </div>
                    <div class="tya-sc-icon" style="background: #f97316;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 11l2 2 4-4"/></svg>
                    </div>
                </div>
            </div>
            <div class="tya-sc-card">
                <div class="tya-sc-accent" style="background: #16a34a;"></div>
                <div class="tya-sc-row">
                    <div style="min-width:0;">
                        <div class="tya-sc-label">Total Pendapatan</div>
                        <div class="tya-sc-value">{{ $rp($grandTotal) }}</div>
                        <div class="tya-sc-note">{{ $bulanTerisi }} bulan ada pembayaran</div>
                    </div>
                    <div class="tya-sc-icon" style="background: #16a34a;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="6" width="17" height="13" rx="2.5"/><path d="M16 12.5h4.5"/><path d="M5 6l9-3 1.5 3"/></svg>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===== Rekap per bulan ===== --}}
        <div class="tya-ivr-box">
            <div class="tya-ivr-head">
                <div>
                    <b>Rekap Pembayaran per Bulan</b>
                    <small>Nominal yang sudah dibayar tiap pelanggan</small>
                </div>
                <span class="tya-ivr-chip">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
                    {{ $tahun }}
                </span>
            </div>

            @if ($matrix->isEmpty())
                <div class="tya-ivr-empty">Belum ada invoice lunas untuk tahun {{ $tahun }}.</div>
            @else
                <div class="tya-ivr-scroll">
                    <table class="tya-ivr-tbl">
                        <thead>
                            <tr>
                                <th class="no">No</th>
                                <th class="nm">Pelanggan</th>
                                @foreach ($bulanLabel as $label)
                                    <th>{{ $label }}</th>
                                @endforeach
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($matrix as $i => $row)
                                <tr>
                                    <td class="no">{{ $i + 1 }}</td>
                                    <td class="nm">
                                        {{ $row['customer']->name }}
                                        @if ($row['customer']->outlet)
                                            <small>{{ $row['customer']->outlet->name }}</small>
                                        @endif
                                    </td>
                                    @foreach ($row['bulanan'] as $nominal)
                                        @if ($nominal !== null)
                                            <td class="isi">{{ number_format($nominal, 0, ',', '.') }}</td>
                                        @else
                                            <td class="kosong">—</td>
                                        @endif
                                    @endforeach
                                    <td class="tot">{{ number_format($row['total'], 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="no"></td>
                                <td class="nm">TOTAL</td>
                                @foreach ($monthlyTotals as $total)
                                    <td>{{ number_format($total, 0, ',', '.') }}</td>
                                @endforeach
                                <td>{{ number_format($grandTotal, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
