{{-- Widget "Traffik Jaringan NAS" -- menggantikan "Status Server" lama
     (permintaan user). Datanya LIVE dari tiap router lewat
     MikrotikService::getTrafficSummary() -- lihat catatan performa di
     NasTrafficWidget.php. Tiap baris di daftar bisa DIKLIK buat buka
     modal "Traffic Network" -- gaya kartu gelap dengan 2 angka besar
     (Download/Upload) + grafik sparkline realtime, PERSIS mengikuti
     contoh gambar yang diberikan user (permintaan user: simpel, bukan
     tabel rincian tiap interface lagi). --}}
<x-filament-widgets::widget class="tya-srv-widget">
    <x-filament::section>
        <style>
            .tya-nastrf-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .5rem;
                margin-bottom: .9rem;
            }
            .tya-nastrf-title {
                display: flex; align-items: center; gap: .45rem;
                font-size: .78rem; font-weight: 800; text-transform: uppercase;
                letter-spacing: .05em; color: #18181b;
            }
            .dark .tya-nastrf-title { color: #fafafa; }
            .tya-nastrf-title svg { width: 1rem; height: 1rem; color: #f97316; }

            .tya-nastrf-count {
                font-size: .65rem; font-weight: 700; color: #a1a1aa;
            }

            .tya-nastrf-rows { display: flex; flex-direction: column; gap: .55rem; }

            .tya-nastrf-row {
                display: flex; align-items: center; gap: .6rem;
                padding: .5rem .6rem; background: #fafafa;
                cursor: pointer; border: none; width: 100%; text-align: left;
                font-family: inherit; transition: background .15s ease;
            }
            .tya-nastrf-row:hover { background: rgba(234,88,12,.08); }
            .dark .tya-nastrf-row { background: rgba(255,255,255,.03); }
            .dark .tya-nastrf-row:hover { background: rgba(234,88,12,.14); }

            .tya-nastrf-dot { width: .5rem; height: .5rem; border-radius: 999px; flex-shrink: 0; }
            .tya-nastrf-dot.online { background: #22c55e; }
            .tya-nastrf-dot.offline { background: #d4d4d8; }
            .dark .tya-nastrf-dot.offline { background: #52525b; }

            .tya-nastrf-nama {
                font-size: .74rem; font-weight: 700; color: #27272a;
                flex: 1 1 auto; min-width: 0;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            }
            .dark .tya-nastrf-nama { color: #e4e4e7; }

            .tya-nastrf-net { display: flex; align-items: center; gap: .7rem; flex-shrink: 0; }
            .tya-nastrf-net-item { display: flex; align-items: center; gap: .25rem; }
            .tya-nastrf-net-item svg { width: .68rem; height: .68rem; }
            .tya-nastrf-net-down svg { color: #ea580c; }
            .tya-nastrf-net-up svg { color: #9a3412; }
            .tya-nastrf-net-val {
                font-size: .68rem; font-weight: 800; color: #27272a;
                font-variant-numeric: tabular-nums;
            }
            .dark .tya-nastrf-net-val { color: #e4e4e7; }

            .tya-nastrf-offline-lbl {
                font-size: .65rem; font-weight: 600; color: #a1a1aa; flex-shrink: 0;
            }
            .tya-nastrf-chevron { width: .8rem; height: .8rem; color: #d4d4d8; flex-shrink: 0; }

            .tya-nastrf-empty {
                padding: 1.5rem 0; text-align: center;
                font-size: .72rem; color: #a1a1aa;
            }
            .tya-nastrf-note {
                font-size: .6rem; color: #a1a1aa; margin-top: .6rem; line-height: 1.4;
            }

            /* ---- Modal "Traffic Network" (gaya gelap, ikut gambar
               referensi user -- SELALU gelap, tidak ikut terang/gelap
               tema aplikasi, karena itu memang gaya kartu ini di
               gambar contohnya). ---- */
            .tya-nastrf-overlay {
                position: fixed; inset: 0; z-index: 60;
                background: rgba(0,0,0,.55);
                display: flex; align-items: center; justify-content: center;
                padding: 1rem;
            }
            .tya-nastrf-modal {
                background: #18181b; border-radius: 1.1rem; width: 100%; max-width: 26rem;
                box-shadow: 0 25px 50px -12px rgba(0,0,0,.5);
                padding: 1.25rem 1.35rem 1.4rem;
            }
            .tya-nastrf-modal-head {
                display: flex; align-items: center; justify-content: space-between;
                margin-bottom: 1.1rem;
            }
            .tya-nastrf-modal-title { font-size: .92rem; font-weight: 700; color: #f4f4f5; }
            .tya-nastrf-modal-badge {
                display: flex; align-items: center; gap: .3rem;
                font-size: .65rem; font-weight: 600; color: #d4d4d8;
                background: rgba(255,255,255,.07); padding: .35rem .65rem;
                border-radius: 999px;
            }
            .tya-nastrf-modal-badge .tya-nastrf-livedot {
                width: .4rem; height: .4rem; border-radius: 999px; background: #22c55e;
                animation: tya-nastrf-pulse 1.6s ease-in-out infinite;
            }
            @keyframes tya-nastrf-pulse { 0%,100% { opacity: 1; } 50% { opacity: .35; } }
            .tya-nastrf-modal-close {
                width: 1.7rem; height: 1.7rem; border-radius: 999px; border: none;
                background: rgba(255,255,255,.08); display: flex; align-items: center; justify-content: center;
                cursor: pointer; flex-shrink: 0; margin-inline-start: .5rem;
            }
            .tya-nastrf-modal-close svg { width: .8rem; height: .8rem; color: #a1a1aa; }

            .tya-nastrf-modal-error {
                font-size: .75rem; color: #fca5a5; background: rgba(220,38,38,.15);
                padding: .65rem .8rem; border-radius: .6rem;
            }

            .tya-nastrf-panels { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
            .tya-nastrf-panel-icon {
                width: 2rem; height: 2rem; border-radius: 999px;
                display: flex; align-items: center; justify-content: center;
                margin-bottom: .6rem;
            }
            .tya-nastrf-panel-icon svg { width: 1rem; height: 1rem; }
            .tya-nastrf-panel-icon.dl { background: #ea580c; }
            .tya-nastrf-panel-icon.dl svg { color: #fff; }
            .tya-nastrf-panel-icon.ul { background: #3f3f46; }
            .tya-nastrf-panel-icon.ul svg { color: #e4e4e7; }

            .tya-nastrf-panel-val {
                font-size: 1.35rem; font-weight: 800; color: #fafafa;
                font-variant-numeric: tabular-nums; line-height: 1.15;
            }
            .tya-nastrf-panel-lbl { font-size: .72rem; color: #a1a1aa; margin-bottom: .7rem; }
            .tya-nastrf-panel-chart { height: 3.2rem; }
        </style>

        @php($trf = $this->getTraffics())

        <div class="tya-nastrf-head">
            <div class="tya-nastrf-title">
                <x-filament::icon icon="lucide-activity" />
                <span>Traffik Jaringan NAS</span>
            </div>
            @if ($trf['total'] > 0)
                <span class="tya-nastrf-count">{{ $trf['ditampilkan'] }}/{{ $trf['total'] }} NAS</span>
            @endif
        </div>

        @if (empty($trf['baris']))
            <div class="tya-nastrf-empty">Belum ada NAS yang terhubung ke router manapun.</div>
        @else
            <div class="tya-nastrf-rows">
                @foreach ($trf['baris'] as $b)
                    <button type="button" class="tya-nastrf-row" wire:click="bukaDetailTrafik({{ $b['id'] }})">
                        <span class="tya-nastrf-dot {{ $b['online'] ? 'online' : 'offline' }}"></span>
                        <span class="tya-nastrf-nama">{{ $b['nama'] }}</span>

                        @if ($b['online'])
                            <div class="tya-nastrf-net">
                                <div class="tya-nastrf-net-item tya-nastrf-net-down">
                                    <x-filament::icon icon="lucide-arrow-down" />
                                    <span class="tya-nastrf-net-val">{{ \App\Filament\Widgets\NasTrafficWidget::formatBps($b['rx_bps']) }}</span>
                                </div>
                                <div class="tya-nastrf-net-item tya-nastrf-net-up">
                                    <x-filament::icon icon="lucide-arrow-up" />
                                    <span class="tya-nastrf-net-val">{{ \App\Filament\Widgets\NasTrafficWidget::formatBps($b['tx_bps']) }}</span>
                                </div>
                            </div>
                        @else
                            <span class="tya-nastrf-offline-lbl">Tidak terhubung</span>
                        @endif

                        <x-filament::icon icon="lucide-chevron-right" class="tya-nastrf-chevron" />
                    </button>
                @endforeach
            </div>

            @if ($trf['total'] > $trf['ditampilkan'])
                <div class="tya-nastrf-note">
                    + {{ $trf['total'] - $trf['ditampilkan'] }} NAS lain tidak ditampilkan di sini (dibatasi biar Dashboard tetap cepat dimuat).
                </div>
            @endif
        @endif

        {{-- Modal "Traffic Network" -- 2 angka besar + sparkline,
             persis gaya gambar referensi user. wire:poll DI DALAM blok
             @if ini SENGAJA ditaruh di sini (bukan di luar/di widget)
             supaya polling CUMA jalan selama modal ini kebuka -- begitu
             ditutup, elemen ini hilang dari halaman dan Livewire
             otomatis berhenti polling. --}}
        @if ($trafficDetailNasId)
            <div
                class="tya-nastrf-overlay"
                wire:poll.3s="perbaruiDetailTrafik"
                x-data="tyaNastrfChart()"
                x-init="init()"
                @tya-nastrf-update.window="update($event.detail.riwayat)"
            >
                <div class="tya-nastrf-modal" @click.stop>
                    <div class="tya-nastrf-modal-head">
                        <span class="tya-nastrf-modal-title">{{ $trafficDetailNama }}</span>
                        <div style="display:flex; align-items:center;">
                            <span class="tya-nastrf-modal-badge">
                                <span class="tya-nastrf-livedot"></span>
                                Realtime
                            </span>
                            <button type="button" class="tya-nastrf-modal-close" wire:click="tutupDetailTrafik">
                                <x-filament::icon icon="lucide-x" />
                            </button>
                        </div>
                    </div>

                    @if ($trafficDetailError)
                        <div class="tya-nastrf-modal-error">{{ $trafficDetailError }}</div>
                    @else
                        <div class="tya-nastrf-panels">
                            <div>
                                <div class="tya-nastrf-panel-icon dl">
                                    <x-filament::icon icon="lucide-arrow-down" />
                                </div>
                                <div class="tya-nastrf-panel-val" x-text="labelRx">-</div>
                                <div class="tya-nastrf-panel-lbl">Download</div>
                                <div class="tya-nastrf-panel-chart"><canvas x-ref="canvasRx"></canvas></div>
                            </div>
                            <div>
                                <div class="tya-nastrf-panel-icon ul">
                                    <x-filament::icon icon="lucide-arrow-up" />
                                </div>
                                <div class="tya-nastrf-panel-val" x-text="labelTx">-</div>
                                <div class="tya-nastrf-panel-lbl">Upload</div>
                                <div class="tya-nastrf-panel-chart"><canvas x-ref="canvasTx"></canvas></div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <script>
                if (typeof Chart === 'undefined') {
                    // Jaga-jaga kalau Chart.js belum ke-load sama sekali di
                    // halaman ini -- muat sendiri dari CDN supaya modal ini
                    // tidak bergantung ke widget lain.
                    const s = document.createElement('script');
                    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js';
                    document.head.appendChild(s);
                }

                function tyaNastrfFormat(bps) {
                    if (bps === null || bps === undefined) return '-';
                    if (bps >= 1_000_000_000) return (bps / 1_000_000_000).toFixed(2).replace('.', ',') + ' Gbps';
                    if (bps >= 1_000_000) return (bps / 1_000_000).toFixed(1).replace('.', ',') + ' Mbps';
                    if (bps >= 1_000) return (bps / 1_000).toFixed(0) + ' Kbps';
                    return bps + ' bps';
                }

                function tyaNastrfSparklineOpts() {
                    return {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 300 },
                        interaction: { intersect: false },
                        elements: { point: { radius: 0 } },
                        scales: {
                            x: { display: false },
                            y: { display: false, beginAtZero: true },
                        },
                        plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    };
                }

                function tyaNastrfChart() {
                    return {
                        chartRx: null,
                        chartTx: null,
                        labelRx: '-',
                        labelTx: '-',
                        init() {
                            const coba = (sisaPercobaan) => {
                                if (typeof Chart !== 'undefined') {
                                    this.buatGrafik();

                                    return;
                                }

                                if (sisaPercobaan <= 0) {
                                    console.error('Chart.js gagal dimuat -- grafik trafik NAS tidak bisa ditampilkan.');

                                    return;
                                }

                                setTimeout(() => coba(sisaPercobaan - 1), 50);
                            };

                            coba(40);
                        },
                        buatGrafik() {
                            this.chartRx = new Chart(this.$refs.canvasRx.getContext('2d'), {
                                type: 'line',
                                data: { labels: [], datasets: [{
                                    data: [], borderColor: '#ea580c', backgroundColor: 'rgba(234,88,12,.25)',
                                    fill: true, tension: .4, borderWidth: 2,
                                }] },
                                options: tyaNastrfSparklineOpts(),
                            });

                            this.chartTx = new Chart(this.$refs.canvasTx.getContext('2d'), {
                                type: 'line',
                                data: { labels: [], datasets: [{
                                    data: [], borderColor: '#a1a1aa', backgroundColor: 'rgba(161,161,170,.2)',
                                    fill: true, tension: .4, borderWidth: 2,
                                }] },
                                options: tyaNastrfSparklineOpts(),
                            });

                            // Isi grafik dengan riwayat yang sudah ada SEJAK
                            // modal dibuka (kalau ada, mis. langsung buka lagi
                            // NAS yang sama) -- lewat data awal dari server.
                            this.update(@js($riwayatTrafik));
                        },
                        update(riwayat) {
                            if (! riwayat || riwayat.length === 0) return;

                            const terakhir = riwayat[riwayat.length - 1];
                            this.labelRx = tyaNastrfFormat(terakhir.rx);
                            this.labelTx = tyaNastrfFormat(terakhir.tx);

                            if (this.chartRx) {
                                this.chartRx.data.labels = riwayat.map((r) => r.waktu);
                                this.chartRx.data.datasets[0].data = riwayat.map((r) => r.rx);
                                this.chartRx.update('none');
                            }

                            if (this.chartTx) {
                                this.chartTx.data.labels = riwayat.map((r) => r.waktu);
                                this.chartTx.data.datasets[0].data = riwayat.map((r) => r.tx);
                                this.chartTx.update('none');
                            }
                        },
                    };
                }
            </script>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
