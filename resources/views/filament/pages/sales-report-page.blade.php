<x-filament-panels::page>
    {{-- LAPORAN PENJUALAN -- desain ulang 2026-10-17 (sesuai referensi user):
         kartu "Filter Laporan" yang bisa dilipat, kotak ikon tiap isian,
         chip rentang tanggal, kartu ringkasan berwarna + persen naik/turun
         dibanding periode sebelumnya (panjang sama), lalu ringkasan invoice
         dan rincian per outlet. Semua kelas baru berawalan .tya-lap- supaya
         tidak kena aturan lama "border-radius: 0" di global-ui-polish.
         Logic laporan (ReportService, export, cetak) TIDAK diubah. --}}
    <style>
        .tya-lap { --lap-card: #ffffff; --lap-border: #ececef; --lap-muted: #71717a; --lap-text: #18181b; --lap-soft: #fafafa; display: flex; flex-direction: column; gap: 1.25rem; }
        .dark .tya-lap { --lap-card: #1c1c20; --lap-border: rgba(255,255,255,.08); --lap-muted: #a1a1aa; --lap-text: #f4f4f5; --lap-soft: rgba(255,255,255,.03); }

        /* ---------- Kartu filter ---------- */
        .tya-lap-filter { background: var(--lap-card); border: 1px solid var(--lap-border); border-radius: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.04); overflow: hidden; }
        .tya-lap-fhead { display: flex; align-items: center; gap: .8rem; width: 100%; padding: 1rem 1.25rem; background: none; border: 0; cursor: pointer; text-align: left; color: var(--lap-text); font-family: inherit; }
        .tya-lap-fhead-ic { width: 2.6rem; height: 2.6rem; border-radius: 12px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; background: linear-gradient(135deg, #fb923c, #ea580c); box-shadow: 0 4px 12px rgba(249,115,22,.3); }
        .tya-lap-fhead-ic svg { width: 1.25rem; height: 1.25rem; }
        .tya-lap-fhead-tx { flex: 1; min-width: 0; }
        .tya-lap-fhead-tx b { display: block; font-size: .98rem; font-weight: 800; }
        .tya-lap-fhead-tx small { display: block; font-size: .76rem; color: var(--lap-muted); margin-top: .1rem; }
        .tya-lap-fhead-chev { width: 2.1rem; height: 2.1rem; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #fff7ed; color: #ea580c; transition: transform .2s ease; }
        .dark .tya-lap-fhead-chev { background: rgba(249,115,22,.14); color: #fb923c; }
        .tya-lap-fhead-chev svg { width: 1rem; height: 1rem; }
        .tya-lap-filter.tutup .tya-lap-fhead-chev { transform: rotate(180deg); }
        .tya-lap-fbody { padding: .25rem 1.25rem 1.25rem; border-top: 1px solid var(--lap-border); }
        .tya-lap-filter.tutup .tya-lap-fbody { display: none; }
        .tya-lap-fbody .fi-sc.fi-grid { row-gap: .9rem !important; }
        /* kotak ikon tepat sejajar tengah kotak isian */
        .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-input-wrp-prefix > .fi-icon:not(.fi-loading-indicator) { top: 50% !important; transform: translateY(-50%) !important; }
        .tya-lap-apply { display: flex; justify-content: flex-end; margin-top: 1.1rem; }
        .tya-lap-apply .fi-btn { border-radius: 12px !important; min-height: 2.75rem; padding-inline: 1.4rem !important; font-weight: 700 !important; }

        /* ---------- Judul bagian + chip tanggal ---------- */
        .tya-lap-sec { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-top: .25rem; }
        .tya-lap-sec h2 { flex: 1 1 9rem; margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--lap-text); line-height: 1.25; }
        .tya-lap-sec h2 small { display: block; font-size: .74rem; font-weight: 500; color: var(--lap-muted); margin-top: .15rem; }
        .tya-lap-chip { display: inline-flex; align-items: center; gap: .4rem; padding: .45rem .8rem; border-radius: 999px; background: #fff7ed; color: #c2410c; font-size: .76rem; font-weight: 700; white-space: nowrap; }
        .dark .tya-lap-chip { background: rgba(249,115,22,.14); color: #fdba74; }
        .tya-lap-chip svg { width: .95rem; height: .95rem; }

        /* ---------- Kartu ringkasan ---------- */
        .tya-lap-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; }
        @media (min-width: 1100px) { .tya-lap-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .tya-lap-stat { --c: #f97316; --bg: #fff7ed; --bd: #fed7aa; position: relative; display: flex; align-items: center; gap: .85rem; padding: 1rem 1.1rem; border-radius: 16px; background: var(--bg); border: 1px solid var(--bd); min-width: 0; }
        .tya-lap-stat.biru { --c: #2563eb; --bg: #eff6ff; --bd: #bfdbfe; }
        .tya-lap-stat.kuning { --c: #d97706; --bg: #fffbeb; --bd: #fde68a; }
        .tya-lap-stat.hijau { --c: #16a34a; --bg: #f0fdf4; --bd: #bbf7d0; }
        .tya-lap-stat.ungu { --c: #7c3aed; --bg: #f5f3ff; --bd: #ddd6fe; }
        .dark .tya-lap-stat { --bg: color-mix(in srgb, var(--c) 11%, #1c1c20); --bd: color-mix(in srgb, var(--c) 32%, transparent); }
        .tya-lap-stat-ic { width: 2.9rem; height: 2.9rem; border-radius: 13px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; background: var(--c); box-shadow: 0 4px 12px color-mix(in srgb, var(--c) 35%, transparent); }
        .tya-lap-stat-ic svg { width: 1.4rem; height: 1.4rem; }
        .tya-lap-stat-tx { flex: 1; min-width: 0; }
        .tya-lap-stat-tx small { display: block; font-size: .76rem; font-weight: 600; color: var(--lap-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tya-lap-stat-tx b { display: block; font-size: clamp(1.05rem, 1.6vw, 1.3rem); font-weight: 800; color: var(--lap-text); line-height: 1.2; margin-top: .15rem; white-space: nowrap; }
        .tya-lap-stat-tx em { display: block; font-style: normal; font-size: .72rem; color: var(--lap-muted); margin-top: .2rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tya-lap-delta { align-self: flex-start; display: inline-flex; align-items: center; gap: .15rem; font-size: .78rem; font-weight: 800; white-space: nowrap; padding: .15rem .45rem; border-radius: 999px; }
        .tya-lap-delta svg { width: .85rem; height: .85rem; }
        .tya-lap-delta.naik { color: #16a34a; background: rgba(22,163,74,.1); }
        .tya-lap-delta.turun { color: #dc2626; background: rgba(220,38,38,.1); }
        .tya-lap-delta.tetap { color: var(--lap-muted); background: rgba(113,113,122,.1); }
        .dark .tya-lap-delta.naik { color: #4ade80; }
        .dark .tya-lap-delta.turun { color: #f87171; }
        .tya-lap-note { font-size: .72rem; color: var(--lap-muted); margin: -.35rem 0 0; }

        /* ---------- Kartu tabel (invoice & per outlet) ---------- */
        .tya-lap-box { background: var(--lap-card); border: 1px solid var(--lap-border); border-radius: 18px; overflow: hidden; }
        .tya-lap-box-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .95rem 1.25rem; border-bottom: 1px solid var(--lap-border); }
        .tya-lap-box-head b { font-size: .92rem; font-weight: 800; color: var(--lap-text); }
        .tya-lap-print { display: inline-flex; align-items: center; gap: .35rem; background: #f97316; color: #fff; font-size: .8rem; font-weight: 700; padding: .5rem .9rem; border-radius: 10px; text-decoration: none; }
        .tya-lap-print:hover { background: #ea580c; }
        .tya-lap-print svg { width: .9rem; height: .9rem; }
        .tya-lap-rows > div { display: flex; justify-content: space-between; gap: 1rem; padding: .75rem 1.25rem; font-size: .84rem; border-bottom: 1px solid var(--lap-border); }
        .tya-lap-rows > div:last-child { border-bottom: 0; }
        .tya-lap-rows span { color: var(--lap-muted); }
        .tya-lap-rows b { color: var(--lap-text); text-align: right; white-space: nowrap; }
        .tya-lap-rows .gabung { background: #fff7ed; }
        .dark .tya-lap-rows .gabung { background: rgba(249,115,22,.1); }
        .tya-lap-rows .gabung span { color: var(--lap-text); font-weight: 700; }
        .tya-lap-rows .gabung b { color: #ea580c; }

        table.tya-lap-tbl { width: 100%; border-collapse: collapse; font-size: .82rem; }
        table.tya-lap-tbl th { text-align: left; padding: .65rem 1.25rem; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: var(--lap-muted); background: var(--lap-soft); border-bottom: 1px solid var(--lap-border); white-space: nowrap; }
        table.tya-lap-tbl td { padding: .7rem 1.25rem; border-bottom: 1px solid var(--lap-border); color: var(--lap-text); }
        table.tya-lap-tbl tr:last-child td { border-bottom: 0; }
        .tya-lap-tbl .r { text-align: right; white-space: nowrap; }
        .tya-lap-tbl .nm { font-weight: 700; }
        .tya-lap-tbl .km { color: #d97706; }
        .tya-lap-tbl .pf { color: #16a34a; font-weight: 800; }
        .tya-lap-foot { display: flex; justify-content: space-between; gap: .5rem; padding: .8rem 1.25rem; background: var(--lap-soft); font-weight: 800; font-size: .82rem; color: var(--lap-text); border-top: 1px solid var(--lap-border); }
        .tya-lap-empty { padding: 2.5rem 1rem; text-align: center; color: var(--lap-muted); font-size: .84rem; }

        /* ---------- Tombol Cetak / Excel / PDF di kepala halaman ---------- */
        .fi-page:has(.tya-lap) .fi-header-actions-ctn .fi-btn { border-radius: 12px !important; font-weight: 700 !important; }

        /* ================= MODE HP ================= */
        @media (max-width: 639px) {
            .tya-lap { gap: 1rem; }

            /* Tombol Cetak/Excel/PDF: diatur global (blok TOMBOL-EKSPOR-IKON) --
               kotak ikon ringkas rata tengah, logo Excel hijau & PDF merah. */
            html body .fi-page:has(.tya-lap) .fi-header .fi-header-actions-ctn { order: -1 !important; }
            html body .fi-page:has(.tya-lap) .fi-header > div:not(.fi-header-actions-ctn) { display: none !important; }
            /* tombol pas di tengah + buang ruang kosong di bawahnya */
            html body .fi-page:has(.tya-lap) .fi-header { padding-block: .75rem !important; min-height: 0 !important; }
            html body .fi-page:has(.tya-lap) .fi-page-header-main-ctn { padding-block: .5rem 0 !important; row-gap: .75rem !important; }
            html body .fi-page:has(.tya-lap) .fi-page-main { gap: .75rem !important; row-gap: .75rem !important; }
            html body .fi-page:has(.tya-lap) .fi-page-content { row-gap: .75rem !important; padding-block-start: .85rem !important; }

            .tya-lap-fhead { padding: .9rem 1rem; }
            .tya-lap-fbody { padding: .1rem 1rem 1rem; }

            /* kotak ikon lebih kecil supaya 2 kolom muat */
            /* :not(#tya-x) = trik menaikkan prioritas di atas aturan global
               FORM-IKON-KOTAK tanpa mengubah aturan global itu sendiri. */
            .tya-lap-fbody .fi-fo-field:not(#tya-x) { padding-inline-start: 40px !important; min-height: 0 !important; }
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-input-wrp-prefix > .fi-icon:not(.fi-loading-indicator) {
                inset-inline-start: -40px !important; width: 33px !important; height: 33px !important; padding: 7px !important; border-radius: 10px !important;
            }
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-input-wrp { min-height: 40px !important; border-radius: 10px !important; }
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-input-wrp :is(.fi-input, .fi-select-input, select) { min-height: 38px !important; font-size: 13px !important; }
            .tya-lap-fbody .fi-fo-field:not(#tya-x) :is(.fi-fo-field-label, .fi-fo-field-label-content) { white-space: nowrap !important; font-size: 12px !important; }
            .tya-lap-fbody .fi-sc.fi-grid { column-gap: .6rem !important; }

            /* Pilihan Bulan/Tahun: teks tidak boleh patah ("Okto/ber"),
               tombol hapus (x) disembunyikan di HP -- tinggal pilih lagi. */
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-select-input-value-remove-btn { display: none !important; }
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-select-input-btn { padding-inline-start: .6rem !important; padding-inline-end: 1.5rem !important; background-position: right .2rem center !important; background-size: 1.25em 1.25em !important; }
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-select-input-btn .fi-select-input-value-ctn { padding: 0 !important; min-height: 0 !important; }
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-select-input-ctn > .fi-select-input-value-ctn { padding-inline-start: .6rem !important; padding-inline-end: 1.5rem !important; }
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-select-input-value-label { flex: 1 1 auto !important; }
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-select-input-value-ctn,
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-select-input-value-ctn > span,
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-select-input-value-label,
            .tya-lap-fbody .fi-fo-field:not(#tya-x) .fi-select-input-placeholder { white-space: nowrap !important; text-wrap: nowrap !important; word-break: normal !important; overflow: hidden !important; text-overflow: ellipsis !important; min-width: 0 !important; }

            /* Tanggal: ikon kalender bawaan disembunyikan (ketuk kolomnya
               tetap membuka kalender di HP) supaya tanggal tampil utuh. */
            .tya-lap-fbody .fi-fo-field:not(#tya-x) input[type="date"]::-webkit-calendar-picker-indicator { display: none !important; -webkit-appearance: none; }
            .tya-lap-fbody .fi-fo-field:not(#tya-x) input[type="date"] { padding-inline: .55rem !important; font-size: 13px !important; min-width: 0 !important; width: 100% !important; }
            .tya-lap-fbody .fi-fo-field:not(#tya-x) input[type="date"]::-webkit-date-and-time-value { text-align: left; }

            .tya-lap-fhead-tx small { font-size: .72rem; }
            .tya-lap-apply .fi-btn { width: 100% !important; justify-content: center !important; }

            .tya-lap-sec h2 { font-size: 1rem; }
            .tya-lap-stats { grid-template-columns: 1fr !important; gap: .65rem; }
            .tya-lap-stat { padding: .85rem 1rem; }
            .tya-lap-stat-tx b { font-size: 1.2rem; }

            .tya-lap-box-head { padding: .85rem 1rem; }
            .tya-lap-rows > div { padding: .7rem 1rem; font-size: .8rem; }
            table.tya-lap-tbl th, table.tya-lap-tbl td { padding: .5rem .6rem; font-size: .72rem; }
            table.tya-lap-tbl th { font-size: .6rem; }
            .tya-lap-tbl .no { display: none; }
            .tya-lap-print span { display: none; }
            .tya-lap-print { padding: .5rem; }
            .tya-lap-foot { padding: .7rem 1rem; font-size: .74rem; }
        }
    </style>

    @php
        $summary = $this->getSummary();
        $banding = $this->getSummarySebelumnya();
        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
        $tglPendek = function ($t) {
            if (blank($t)) {
                return null;
            }
            $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            $c = \Carbon\Carbon::parse($t);

            return $c->day.' '.$bln[$c->month].' '.$c->year;
        };
        $rentang = trim(($tglPendek($this->filters['from'] ?? null) ?? '…').' – '.($tglPendek($this->filters['to'] ?? null) ?? '…'));

        // Persen perubahan dibanding periode sebelumnya (panjang hari sama).
        // null = tidak ditampilkan (tidak ada data pembanding / sebelumnya 0).
        $delta = function (string $kunci) use ($summary, $banding) {
            if (! $banding || ! isset($banding['summary'][$kunci])) {
                return null;
            }
            $lama = (float) $banding['summary'][$kunci];
            $baru = (float) ($summary[$kunci] ?? 0);
            if ($lama == 0.0) {
                return null;
            }

            return round(($baru - $lama) / abs($lama) * 100, 1);
        };

        $ikon = [
            'transaksi' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3.5h2.6l2.4 11.2a1.6 1.6 0 001.6 1.3h8.3a1.6 1.6 0 001.6-1.2l1.6-6.8H6"/></svg>',
            'pendapatan' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/></svg>',
            'komisi' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.4"/><circle cx="17" cy="17" r="2.4"/></svg>',
            'profit' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-6M3 20h18"/></svg>',
            'rata' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l5-5 4 4 8-8"/><path d="M15 8h5v5"/></svg>',
        ];
        $kartu = [
            ['transaksi', '', 'Total Transaksi', number_format($summary['total_transaksi'], 0, ',', '.'), 'total_transaksi', 'Rata-rata '.$rp($summary['rata_rata_transaksi']).' / transaksi'],
            ['pendapatan', 'biru', 'Total Pendapatan', $rp($summary['total_pendapatan']), 'total_pendapatan', null],
            ['komisi', 'kuning', 'Total Komisi', $rp($summary['total_komisi']), 'total_komisi', null],
            ['profit', 'hijau', 'Profit', $rp($summary['hasil_setelah_komisi']), 'hasil_setelah_komisi', null],
        ];
    @endphp

    <div class="tya-lap">
        {{-- ===== Filter ===== --}}
        <div class="tya-lap-filter" x-data="{ tutup: false }" x-bind:class="{ 'tutup': tutup }">
            <button type="button" class="tya-lap-fhead" x-on:click="tutup = ! tutup" x-bind:aria-expanded="(! tutup).toString()">
                <span class="tya-lap-fhead-ic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 4.5h17l-6.5 8v6l-4 2v-8z"/></svg>
                </span>
                <span class="tya-lap-fhead-tx">
                    <b>Filter Laporan</b>
                    <small>Pilih periode dan parameter laporan</small>
                </span>
                <span class="tya-lap-fhead-chev">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg>
                </span>
            </button>

            <div class="tya-lap-fbody">
                <form wire:submit.prevent="terapkanFilter">
                    {{ $this->form }}

                    {{-- Field filter sengaja tidak "live" -- laporan baru dihitung
                         ulang setelah tombol ini diklik. --}}
                    <div class="tya-lap-apply">
                        <x-filament::button type="submit" icon="lucide-list-filter" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="terapkanFilter">Terapkan Filter</span>
                            <span wire:loading wire:target="terapkanFilter">Memproses...</span>
                        </x-filament::button>
                    </div>
                </form>
            </div>
        </div>

        {{-- ===== Ringkasan penjualan voucher ===== --}}
        <div class="tya-lap-sec">
            <h2>Ringkasan Penjualan Voucher</h2>
            <span class="tya-lap-chip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
                {{ $rentang }}
            </span>
        </div>

        <div class="tya-lap-stats">
            @foreach ($kartu as [$k, $warna, $label, $nilai, $kunci, $catatan])
                @php($d = $delta($kunci))
                <div class="tya-lap-stat {{ $warna }}">
                    <span class="tya-lap-stat-ic">{!! $ikon[$k] !!}</span>
                    <span class="tya-lap-stat-tx">
                        <small>{{ $label }}</small>
                        <b>{{ $nilai }}</b>
                        @if ($catatan)
                            <em>{{ $catatan }}</em>
                        @endif
                    </span>
                    @if ($d !== null)
                        <span class="tya-lap-delta {{ $d > 0 ? 'naik' : ($d < 0 ? 'turun' : 'tetap') }}" title="Dibanding periode sebelumnya">
                            @if ($d > 0)
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5.5 11.5L12 5l6.5 6.5"/></svg>
                            @elseif ($d < 0)
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5.5 12.5L12 19l6.5-6.5"/></svg>
                            @endif
                            {{ rtrim(rtrim(number_format(abs($d), 1, ',', '.'), '0'), ',') }}%
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
        @if ($banding)
            <p class="tya-lap-note">Persen = dibanding periode sebelumnya ({{ $tglPendek($banding['from']) }} – {{ $tglPendek($banding['to']) }}).</p>
        @endif

        {{-- Bagian di bawah ini KHUSUS pemilik/admin. Akun outlet hanya
             boleh melihat laporan Penjualan Voucher di atas. --}}
        @unless (auth()->user()?->isOutlet())
            @php($invoiceSummary = $this->getInvoiceSummary())

            <div class="tya-lap-box">
                <div class="tya-lap-box-head"><b>Pelanggan Bulanan (Invoice)</b></div>
                <div class="tya-lap-rows">
                    <div><span>Total Invoice Lunas</span><b>{{ number_format($invoiceSummary['total_invoice'], 0, ',', '.') }}</b></div>
                    <div><span>Total Pendapatan Tagihan Bulanan</span><b>{{ $rp($invoiceSummary['total_pendapatan']) }}</b></div>
                    <div><span>Rata-rata per Invoice</span><b>{{ $rp($invoiceSummary['rata_rata_invoice']) }}</b></div>
                    <div class="gabung"><span>Total Gabungan (Voucher + Tagihan Bulanan)</span><b>{{ $rp($summary['total_pendapatan'] + $invoiceSummary['total_pendapatan']) }}</b></div>
                </div>
            </div>

            @php($outlets = $this->getOutletBreakdown())

            <div class="tya-lap-box">
                <div class="tya-lap-box-head">
                    <b>Rincian Pendapatan per Outlet</b>
                    <a class="tya-lap-print" href="{{ route('laporan.outlet.print', array_filter($this->filters)) }}" target="_blank">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        <span>Print</span>
                    </a>
                </div>

                @if ($outlets->isEmpty())
                    <div class="tya-lap-empty">Belum ada data pendapatan outlet pada periode ini.</div>
                @else
                    <div style="overflow-x: auto;">
                        <table class="tya-lap-tbl">
                            <thead>
                                <tr>
                                    <th class="no">No</th>
                                    <th>Outlet / Reseller</th>
                                    <th class="r">Trx</th>
                                    <th class="r">Pendapatan</th>
                                    <th class="r">Komisi</th>
                                    <th class="r">Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($outlets as $i => $o)
                                    <tr>
                                        <td class="no">{{ $i + 1 }}</td>
                                        <td class="nm">{{ $o['nama'] }}</td>
                                        <td class="r">{{ number_format($o['total_transaksi'], 0, ',', '.') }}</td>
                                        <td class="r">{{ $rp($o['pendapatan_total']) }}</td>
                                        <td class="r km">{{ $rp($o['komisi']) }}</td>
                                        <td class="r pf">{{ $rp($o['pendapatan_bersih']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="tya-lap-foot">
                        <span>TOTAL ({{ $outlets->count() }} outlet)</span>
                        <span>{{ $rp($outlets->sum('pendapatan_bersih')) }} (bersih)</span>
                    </div>
                @endif
            </div>
        @endunless
    </div>
</x-filament-panels::page>
