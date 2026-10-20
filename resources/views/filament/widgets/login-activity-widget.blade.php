<x-filament-widgets::widget>
    <style>
        /* Struktur & kelas SAMA PERSIS dengan kartu "Top 20 Invoice" /
           "Top 20 Voucher Sales" (lihat top-lists-widget.blade.php) --
           sengaja disamakan, bukan cuma mirip, supaya ketiga kartu di
           baris dashboard ini benar-benar identik tinggi & gaya headernya.
           Widget ini terpisah dari TopListsWidget (Livewire component
           sendiri), jadi tidak bisa berbagi <style> -- makanya blok CSS
           di bawah ini SENGAJA diduplikasi persis dari file itu. */
        .tya-card { background: #fff; border-radius: 0.85rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03), 0 4px 14px rgba(0,0,0,0.02); overflow: hidden; }
        .dark .tya-card { background: #27272a; }
        .tya-card-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.85rem 1rem; border-bottom: 1px solid rgba(0,0,0,0.06); flex-wrap: wrap; }
        .dark .tya-card-header { border-bottom-color: rgba(255,255,255,0.08); }
        .tya-card-title { display: flex; align-items: center; gap: 0.4rem; font-size: 0.9rem; font-weight: 700; color: #18181b; }
        .dark .tya-card-title { color: #fff; }
        .tya-card-title svg { width: 16px; height: 16px; color: #f97316; }

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
            .tya-empty { flex: 1 1 auto; display: flex; align-items: center; justify-content: center; }
        }

        .tya-card-body { padding: 1rem; }
        .tya-timeline { position: relative; padding-inline-end: 0.5rem; }
        .tya-timeline-line { position: absolute; top: 0; bottom: 0; left: 13px; width: 1px; background: rgba(0,0,0,0.08); }
        .dark .tya-timeline-line { background: rgba(255,255,255,0.1); }
        .tya-timeline-item { position: relative; display: flex; gap: 0.75rem; margin-bottom: 1rem; }
        .tya-timeline-item:last-child { margin-bottom: 0; }
        .tya-icon { position: relative; z-index: 1; flex: 0 0 26px; width: 26px; height: 26px; border-radius: 9999px; display: flex; align-items: center; justify-content: center; }
        .tya-icon.success { background: rgba(34,197,94,0.12); color: #16a34a; }
        .tya-icon.fail { background: rgba(239,68,68,0.12); color: #dc2626; }
        .tya-icon svg { width: 14px; height: 14px; display: block; }
        .tya-login-body { min-width: 0; flex: 1 1 auto; }
        .tya-login-title { font-size: 0.8125rem; font-weight: 600; color: #ea580c; line-height: 1.3; margin: 0; }
        .tya-login-email { font-size: 0.6875rem; color: #a1a1aa; line-height: 1.3; margin: 0; }
        .tya-meta { margin-top: 0.25rem; font-size: 0.6875rem; color: #a1a1aa; display: flex; flex-wrap: wrap; gap: 0.25rem; align-items: center; }
        .tya-meta .name { color: #71717a; font-weight: 500; }
        .tya-meta .ip { font-family: 'Courier New', monospace; }
        .tya-logout { margin-top: 0.15rem; font-size: 0.6875rem; color: #a1a1aa; }
        .tya-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem 0; text-align: center; color: #a1a1aa; }
        .tya-empty svg { width: 32px; height: 32px; margin-bottom: 0.5rem; opacity: 0.4; }

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

    <div class="tya-card tya-kartu-daftar">
        <div class="tya-card-header">
            <div class="tya-card-title">
                {{-- Ikon "login" (panah masuk) -- sengaja dipakai supaya
                     konsisten dengan ikon di dua kartu sebelah (dokumen
                     untuk Invoice, chat untuk Voucher Sales). --}}
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 3.75h3a2.25 2.25 0 012.25 2.25v12a2.25 2.25 0 01-2.25 2.25h-3M12 15.75L16.5 12m0 0L12 8.25M16.5 12H3.75" />
                </svg>
                Aktivitas Login Pengguna
            </div>
        </div>

        <div class="tya-card-body">
            @php($rows = $this->getRows())

            @if ($rows->isEmpty())
                <div class="tya-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                    </svg>
                    <p style="font-size: 0.8125rem;">Belum ada aktivitas login tercatat.</p>
                </div>
            @else
                <div class="tya-timeline">
                    <div class="tya-timeline-line"></div>

                    @foreach ($rows as $log)
                        <div class="tya-timeline-item">
                            <div class="tya-icon {{ $log->successful ? 'success' : 'fail' }}">
                                @if ($log->successful)
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                @else
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                @endif
                            </div>

                            <div class="tya-login-body">
                                <p class="tya-login-title">{{ $log->successful ? 'User login: ' : 'Gagal login: ' }}{{ $log->user?->name ?? $log->email }}</p>
                                <p class="tya-login-email">{{ $log->email }}</p>

                                <div class="tya-meta">
                                    <span class="name">{{ $log->user?->name ?? $log->email }}</span>
                                    <span>&middot;</span>
                                    <span class="ip">{{ $log->ip_address ?? '-' }}</span>
                                    <span>&middot;</span>
                                    <span>{{ $log->logged_in_at?->format('Y-m-d H:i') }}</span>
                                </div>

                                @if ($log->logged_out_at)
                                    <p class="tya-logout">Logout {{ $log->logged_out_at->diffForHumans() }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
