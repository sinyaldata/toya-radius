<x-filament-widgets::widget>
    <style>
        .tya-card { background: #fff; border-radius: 0.85rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03), 0 4px 14px rgba(0,0,0,0.02); overflow: hidden; }
        .dark .tya-card { background: #27272a; }
        .tya-card-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.85rem 1rem; border-bottom: 1px solid rgba(0,0,0,0.06); flex-wrap: wrap; }
        .dark .tya-card-header { border-bottom-color: rgba(255,255,255,0.08); }
        .tya-card-title { display: flex; align-items: center; gap: 0.4rem; font-size: 0.9rem; font-weight: 700; color: #18181b; }
        .dark .tya-card-title { color: #fff; }
        .tya-card-title svg { width: 16px; height: 16px; color: #f97316; }

        /* Samakan tinggi kartu dengan widget Aktivitas Login yang
           sebelahnya (permintaan user) -- sebelumnya cuma widget itu
           yang punya min-height, jadi tinggi ketiga kartu di baris ini
           beda-beda mengikuti isinya masing-masing. flex-column +
           margin-top:auto pada paginasi bikin tombol halaman selalu
           menempel di dasar kartu, jadi ketiganya rata rapi.
           Cuma di layar lebar (>=1024px) di mana ketiganya memang
           sebaris -- di HP mereka bertumpuk vertikal, tidak perlu
           dipaksa tinggi. */
        @media (min-width: 1024px) {
            /* Ketiga kartu di baris ini otomatis menyamakan diri ke
               YANG PALING TINGGI. Struktur Filament: .fi-wi (grid) >
               .fi-wi-widget (kolom) > .tya-card. Kolomnya sudah
               otomatis meregang setinggi baris (grid align-items:
               stretch bawaan), jadi cukup kartunya disuruh mengisi
               penuh kolom itu. min-height saja tidak cukup: begitu satu
               kartu isinya melebihi 420px (mis. teks melipat 2 baris),
               yang lain tetap berhenti di 420px dan jadi lebih pendek. */
            .tya-card { display: flex; flex-direction: column; height: 100%; min-height: 420px; }
            .tya-pagination { margin-top: auto; }
        }
        .tya-pill-group { display: flex; gap: 0.35rem; flex-wrap: wrap; }
        .tya-pill {
            display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.55rem;
            border-radius: 9999px; font-size: 0.6875rem; font-weight: 700;
            border: none; cursor: pointer; font-family: inherit; line-height: 1.4;
            opacity: 0.55; transition: opacity .15s, box-shadow .15s;
        }
        .tya-pill.paid { background: #f97316; color: #fff; }
        .tya-pill.unpaid { background: #fee2e2; color: #b91c1c; }
        .tya-pill.overdue { background: #fef3c7; color: #92400e; }
        .dark .tya-pill.unpaid { background: rgba(239,68,68,0.15); }
        .dark .tya-pill.overdue { background: rgba(245,158,11,0.15); }
        .tya-pill:hover { opacity: 0.85; }
        .tya-pill.active { opacity: 1; box-shadow: 0 0 0 2px rgba(0,0,0,0.18); }
        .dark .tya-pill.active { box-shadow: 0 0 0 2px rgba(255,255,255,0.35); }

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
        .tya-badge { display: inline-block; padding: 0.05rem 0.5rem; border-radius: 9999px; font-size: 0.625rem; font-weight: 700; background: rgba(34,197,94,0.12); color: #16a34a; margin-inline-start: 0.35rem; }

        .tya-pagination { display: flex; align-items: center; justify-content: center; gap: 0.3rem; padding: 0.75rem; border-top: 1px solid rgba(0,0,0,0.06); }
        .dark .tya-pagination { border-top-color: rgba(255,255,255,0.08); }
        .tya-pagination button { min-width: 26px; height: 26px; border-radius: 0.4rem; border: 1px solid rgba(0,0,0,0.1); background: #fff; font-size: 0.75rem; cursor: pointer; color: #52525b; }
        .dark .tya-pagination button { background: #18181b; border-color: rgba(255,255,255,0.1); color: #d4d4d8; }
        .tya-pagination button.active { background: #f97316; color: #fff; border-color: #f97316; }
        .tya-pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
        .tya-empty { padding: 2rem 1rem; text-align: center; color: #a1a1aa; font-size: 0.8125rem; }

        /* ===== Kartu daftar RINGKAS (Aktivitas Login / Top Invoice / Top Voucher Sales) =====
           PERBAIKAN (2026-10-02, laporan user: "di laptop 13 inci kartu ini
           melonjong"). Di laptop 13" dashboard memakai 3 kolom dengan sidebar
           terbuka, jadi tiap kartu cuma +-300px. Tabel 3-4 kolom dipaksa muat:
           nomor invoice/transaksi patah jadi 3 baris, tanggal melipat, muncul
           batang geser mendatar, dan karena tinggi ketiga kartu disamakan ke
           yang tertinggi, semuanya jadi memanjang 600-750px.
           Sekarang: ukuran diukur dari LEBAR KARTU itu sendiri (container
           query, bukan lebar layar), dan kalau kartu <= 480px tiap baris tabel
           disusun ulang jadi 2 baris yang rapat. Kartu yang lebih lebar (>480px)
           tampil PERSIS seperti semula -- SEMUA aturan di bawah (termasuk
           nowrap/ellipsis) sengaja hanya berlaku di dalam @container, supaya
           layar lebar tidak ikut berubah. (Versi pertama memakai batas 560px
           dan salah menyalakan mode ringkas di layar +-507px/kartu.) */
        .tya-kartu-daftar { container-type: inline-size; container-name: tya-kartu; }
        .tya-tgl-pendek { display: none; }

        @container tya-kartu (max-width: 480px) {
            .tya-nowrap { white-space: nowrap; }
            .tya-ellipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
            .tya-min0 { min-width: 0; }
            .tya-table-wrap { overflow-x: visible; }
            table.tya-table thead { display: none; }
            table.tya-table, table.tya-table tbody { display: block; width: 100%; }
            table.tya-table tr {
                display: grid; grid-template-columns: minmax(0, 1fr) auto; column-gap: 0.6rem; row-gap: 0.2rem;
                align-items: center; padding: 0.55rem 1rem; border-bottom: 1px solid rgba(0,0,0,0.05);
            }
            .dark table.tya-table tr { border-bottom-color: rgba(255,255,255,0.06); }
            table.tya-table tr:last-child { border-bottom: none; }
            table.tya-table td { display: block; padding: 0; border: 0; min-width: 0; }
            .tya-tgl-penuh { display: none; }
            .tya-tgl-pendek { display: inline; }
            /* Nomor + nominal sebaris (bukan ditumpuk) */
            table.tya-table td .tya-main-text + .tya-sub-text { margin-top: 0; }

            /* --- Top Invoice: baris 1 = nomor + nominal + status ; baris 2 = pelanggan | tanggal --- */
            table.tya-tbl-invoice tr { grid-template-areas: "inv inv" "cust tgl"; }
            table.tya-tbl-invoice td:nth-child(1) { grid-area: inv; display: flex; flex-wrap: wrap; align-items: baseline; column-gap: 0.5rem; row-gap: 0; }
            /* Nominal + status turun BERSAMA kalau tidak muat sebaris (bukan lencananya patah sendiri) */
            table.tya-tbl-invoice td:nth-child(1) .tya-sub-text { white-space: nowrap; }
            table.tya-tbl-invoice td:nth-child(2) { grid-area: cust; }
            table.tya-tbl-invoice td:nth-child(3) { grid-area: tgl; text-align: right; white-space: nowrap; }

            /* --- Top Voucher Sales: baris 1 = no. trx + nominal | tanggal ; baris 2 = kode + paket | penjual --- */
            table.tya-tbl-voucher tr { grid-template-areas: "trx tgl" "kode jual"; }
            table.tya-tbl-voucher td:nth-child(1) { grid-area: trx; display: flex; align-items: baseline; gap: 0.5rem; }
            table.tya-tbl-voucher td:nth-child(2) { grid-area: kode; display: flex; align-items: baseline; gap: 0.5rem; }
            table.tya-tbl-voucher td:nth-child(3) { grid-area: jual; text-align: right; max-width: 7.5rem; }
            table.tya-tbl-voucher td:nth-child(4) { grid-area: tgl; text-align: right; white-space: nowrap; }
            table.tya-tbl-voucher td:nth-child(3), table.tya-tbl-voucher td:nth-child(2) .tya-sub-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            table.tya-tbl-voucher td:nth-child(2) .tya-sub-text { margin-top: 0; min-width: 0; }

            /* --- Aktivitas Login: judul & email 1 baris, nama tidak diulang di baris meta --- */
            .tya-login-title, .tya-login-email { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .tya-meta .name, .tya-meta .name + span { display: none; }
            .tya-timeline-item { margin-bottom: 0.8rem; }
        }
    </style>

        {{-- INVOICE-SLIDE-MOBILE (2026-10-13): versi HP (< 768px) = kartu geser
         seperti referensi user. Tabel di bawahnya tetap dipakai di layar lebar.
         Datanya sama persis (filter Paid/Unpaid/Overdue ikut berlaku), hanya
         semua halaman (maks. 20) digabung jadi satu deret geser. --}}
    @php
        $tyaSemua = collect();
        $tyaHalamanAsal = $this->invoicePage ?? 1;
        try {
            $tyaTotal = max(1, (int) $this->getInvoiceTotalPages());
            for ($tyaP = 1; $tyaP <= $tyaTotal; $tyaP++) {
                $this->invoicePage = $tyaP;
                $tyaSemua = $tyaSemua->merge($this->getInvoiceRows());
            }
        } catch (\Throwable $tyaE) {
            $tyaSemua = collect($this->getInvoiceRows());
        } finally {
            $this->invoicePage = $tyaHalamanAsal;
        }
        $tyaSemua = $tyaSemua->unique('id')->take(20)->values();
        try {
            $tyaIc = $this->getInvoiceCounts();
        } catch (\Throwable $tyaE) {
            $tyaIc = [];
        }
        $tyaWarna = ['#f97316', '#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6'];
        try {
            $tyaLihatSemua = \App\Filament\Resources\InvoiceResource::getUrl();
        } catch (\Throwable $tyaE) {
            $tyaLihatSemua = null;
        }
        $tyaJudul = match ($invoiceFilter ?? null) {
            'paid' => 'Pembayaran Invoice Terbaru',
            'unpaid' => 'Invoice Belum Dibayar',
            'overdue' => 'Invoice Lewat Jatuh Tempo',
            default => 'Top 20 Invoice',
        };
    @endphp
    <style>
        .tya-inv-hp { display: none; }
        @media (max-width: 767px) {
            .tya-inv-hp { display: block; background: #fff; border: 1px solid #ececef; border-radius: 16px; padding: 14px 0 12px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.06); }
            .dark .tya-inv-hp { background: #18181b; border-color: rgba(255, 255, 255, 0.08); box-shadow: none; }
            .tya-kartu-daftar.tya-inv-lebar { display: none !important; }
            .tya-inv-kepala { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 0 14px 10px; }
            .tya-inv-judul { display: flex; align-items: center; gap: 8px; min-width: 0; font-size: 15px; font-weight: 700; color: #18181b; }
            .tya-inv-judul span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .dark .tya-inv-judul { color: #fafafa; }
            .tya-inv-judul svg { width: 20px; height: 20px; flex: 0 0 auto; color: #f97316; }
            .tya-inv-semua { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 2px; font-size: 12.5px; font-weight: 500; color: #52525b; text-decoration: none; }
            .dark .tya-inv-semua { color: #a1a1aa; }
            .tya-inv-pill { display: flex; gap: 6px; padding: 0 14px 10px; overflow-x: auto; scrollbar-width: none; }
            .tya-inv-pill::-webkit-scrollbar { display: none; }
            .tya-inv-bungkus { position: relative; }
            .tya-inv-trek { display: flex; gap: 10px; overflow-x: auto; scroll-snap-type: x mandatory; scroll-padding-inline: 14px; padding: 2px 14px 4px; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
            .tya-inv-trek::-webkit-scrollbar { display: none; }
            .tya-inv-item { flex: 0 0 64%; max-width: 230px; scroll-snap-align: start; display: grid; grid-template-columns: 40px minmax(0, 1fr); column-gap: 9px; align-items: center; padding: 11px; border: 1px solid #ececef; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); }
            .dark .tya-inv-item { background: #202023; border-color: rgba(255, 255, 255, 0.08); box-shadow: none; }
            .tya-inv-av { width: 40px; height: 40px; border-radius: 9999px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; font-weight: 700; grid-row: 1 / span 3; align-self: start; }
            .tya-inv-nama { font-size: 13.5px; font-weight: 700; color: #18181b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .dark .tya-inv-nama { color: #fafafa; }
            .tya-inv-paket { font-size: 12px; color: #71717a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .tya-inv-rp { font-size: 14.5px; font-weight: 800; color: #ea580c; margin-top: 2px; white-space: nowrap; }
            .dark .tya-inv-rp { color: #fb923c; }
            .tya-inv-bawah { grid-column: 1 / -1; display: flex; align-items: center; gap: 8px; margin-top: 8px; min-width: 0; }
            .tya-inv-status { flex: 0 0 auto; padding: 2px 8px; border-radius: 9999px; font-size: 10.5px; font-weight: 700; background: #16a34a; color: #fff; }
            .tya-inv-status.unpaid { background: #fee2e2; color: #b91c1c; }
            .tya-inv-status.overdue { background: #fef3c7; color: #92400e; }
            .tya-inv-waktu { font-size: 11.5px; color: #71717a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .dark .tya-inv-paket, .dark .tya-inv-waktu { color: #a1a1aa; }
            .tya-inv-lanjut { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 34px; height: 34px; border: 0; border-radius: 9999px; background: rgba(255, 255, 255, 0.95); box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); color: #3f3f46; font-size: 20px; line-height: 1; display: flex; align-items: center; justify-content: center; }
            .dark .tya-inv-lanjut { background: #27272a; color: #e4e4e7; }
            .tya-inv-titik { display: flex; justify-content: center; gap: 6px; padding-top: 10px; }
            .tya-inv-titik span { width: 7px; height: 7px; border-radius: 9999px; background: #d4d4d8; transition: width .2s, background .2s; }
            .tya-inv-titik span.aktif { width: 18px; background: #f97316; }
            .dark .tya-inv-titik span { background: #3f3f46; }
            .dark .tya-inv-titik span.aktif { background: #f97316; }
            .tya-inv-kosong { padding: 16px 14px; text-align: center; font-size: 13px; color: #a1a1aa; }
        }
    </style>

    <div class="tya-inv-hp" data-tya-inv-hp
         x-data="{
            i: 0, n: 1,
            hitung() {
                const t = this.$refs.trek; if (!t || !t.firstElementChild) { this.n = 1; this.i = 0; return; }
                const w = t.firstElementChild.offsetWidth + 10;
                const sisa = Math.max(0, t.scrollWidth - t.clientWidth);
                this.n = Math.max(1, Math.round(sisa / w) + 1);
                this.i = Math.min(this.n - 1, Math.round(t.scrollLeft / w));
            },
            titik() { return Math.min(this.n, 5); },
            aktif(k) { const d = this.titik(); return d <= 1 ? k === 0 : k === Math.round(this.i / Math.max(1, this.n - 1) * (d - 1)); },
         }"
         x-init="$nextTick(() => hitung())">
        <div class="tya-inv-kepala">
            <div class="tya-inv-judul">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.42 0-8 2.24-8 5v1a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1c0-2.76-3.58-5-8-5z"/></svg>
                <span>{{ $tyaJudul }}</span>
            </div>
            @if ($tyaLihatSemua)
                <a class="tya-inv-semua" href="{{ $tyaLihatSemua }}">Lihat Semua <span aria-hidden="true">&rsaquo;</span></a>
            @endif
        </div>

        <div class="tya-inv-pill">
            <button type="button" class="tya-pill paid {{ ($invoiceFilter ?? '') === 'paid' ? 'active' : '' }}" wire:click="setInvoiceFilter('paid')">Paid {{ $tyaIc['paid'] ?? '' }}</button>
            <button type="button" class="tya-pill unpaid {{ ($invoiceFilter ?? '') === 'unpaid' ? 'active' : '' }}" wire:click="setInvoiceFilter('unpaid')">Unpaid {{ $tyaIc['unpaid'] ?? '' }}</button>
            <button type="button" class="tya-pill overdue {{ ($invoiceFilter ?? '') === 'overdue' ? 'active' : '' }}" wire:click="setInvoiceFilter('overdue')">Overdue {{ $tyaIc['overdue'] ?? '' }}</button>
        </div>

        @if ($tyaSemua->isEmpty())
            <div class="tya-inv-kosong">Belum ada invoice untuk status ini.</div>
        @else
            <div class="tya-inv-bungkus">
                <div class="tya-inv-trek" x-ref="trek" x-on:scroll.passive="hitung()">
                    @foreach ($tyaSemua as $tyaInv)
                        @php
                            $tyaNama = $tyaInv->customer?->name ?? '-';
                            $tyaHuruf = mb_strtoupper(mb_substr(trim($tyaNama), 0, 1)) ?: '?';
                            $tyaStatus = $tyaInv->status?->value ?? 'unpaid';
                            $tyaWaktu = $tyaInv->paid_at ?? $tyaInv->created_at;
                        @endphp
                        <div class="tya-inv-item">
                            <div class="tya-inv-av" style="background: {{ $tyaWarna[$loop->index % count($tyaWarna)] }}">{{ $tyaHuruf }}</div>
                            <div class="tya-inv-nama">{{ $tyaNama }}</div>
                            <div class="tya-inv-paket">{{ $tyaInv->customer?->servicePackage?->name ?? $tyaInv->invoice_number }}</div>
                            <div class="tya-inv-rp">Rp {{ number_format($tyaInv->amount, 0, ',', '.') }}</div>
                            <div class="tya-inv-bawah">
                                <span class="tya-inv-status {{ $tyaStatus }}">{{ $tyaStatus === 'paid' ? 'Lunas' : $tyaInv->status?->label() }}</span>
                                <span class="tya-inv-waktu">{{ $tyaWaktu ? $tyaWaktu->copy()->locale('id')->diffForHumans() : '-' }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="tya-inv-lanjut" aria-label="Berikutnya" x-show="i < n - 1"
                        x-on:click="$refs.trek.scrollBy({ left: $refs.trek.firstElementChild.offsetWidth + 10, behavior: 'smooth' })">&rsaquo;</button>
            </div>
            <div class="tya-inv-titik" x-show="n > 1">
                <template x-for="k in titik()" :key="k">
                    <span :class="{ aktif: aktif(k - 1) }"></span>
                </template>
            </div>
        @endif
    </div>

    <div class="tya-card tya-kartu-daftar tya-inv-lebar">
        <div class="tya-card-header">
            <div class="tya-card-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Top 20 Invoice
            </div>
            @php($ic = $this->getInvoiceCounts())
            <div class="tya-pill-group">
                <button type="button" class="tya-pill paid {{ $invoiceFilter === 'paid' ? 'active' : '' }}" wire:click="setInvoiceFilter('paid')">Paid {{ $ic['paid'] }}</button>
                <button type="button" class="tya-pill unpaid {{ $invoiceFilter === 'unpaid' ? 'active' : '' }}" wire:click="setInvoiceFilter('unpaid')">Unpaid {{ $ic['unpaid'] }}</button>
                <button type="button" class="tya-pill overdue {{ $invoiceFilter === 'overdue' ? 'active' : '' }}" wire:click="setInvoiceFilter('overdue')">Overdue {{ $ic['overdue'] }}</button>
            </div>
        </div>

        @php($invoiceRows = $this->getInvoiceRows())

        @if ($invoiceRows->isEmpty())
            <div class="tya-empty">
                @if ($invoiceFilter !== 'semua')
                    Tidak ada invoice dengan status ini. <button type="button" wire:click="setInvoiceFilter('{{ $invoiceFilter }}')" style="color:#f97316;text-decoration:underline;background:none;border:none;cursor:pointer;font:inherit;">Tampilkan semua</button>
                @else
                    Belum ada invoice.
                @endif
            </div>
        @else
            <div class="tya-table-wrap">
                <table class="tya-table tya-tbl-invoice">
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
                                    <div class="tya-main-text tya-nowrap">{{ $invoice->invoice_number }}</div>
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
                                        <div class="tya-min0">
                                            <div class="tya-main-text tya-ellipsis">{{ $invoice->customer?->name ?? '-' }}</div>
                                            <div class="tya-sub-text tya-ellipsis">{{ $invoice->customer?->outlet?->name ?? 'Stok Pusat' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="tya-sub-text"><span class="tya-tgl-penuh">{{ $invoice->paid_at?->format('Y-m-d H:i:s') ?? '-' }}</span><span class="tya-tgl-pendek">{{ $invoice->paid_at?->format('d/m H:i') ?? '-' }}</span></td>
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
</x-filament-widgets::widget>
