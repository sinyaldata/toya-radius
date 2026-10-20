{{-- Widget "Status Server": CPU, RAM, Penyimpanan, Bandwidth.
     Datanya dari ServerStatusService (lihat catatan di sana soal kenapa
     CPU & bandwidth tampil 0 saat halaman dimuat pertama kali). --}}
<x-filament-widgets::widget class="tya-srv-widget">
    <x-filament::section>
        <style>
            .tya-srv-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .5rem;
                margin-bottom: .9rem;
            }
            .tya-srv-title {
                display: flex; align-items: center; gap: .45rem;
                font-size: .78rem; font-weight: 800; text-transform: uppercase;
                letter-spacing: .05em; color: #18181b;
            }
            .dark .tya-srv-title { color: #fafafa; }
            .tya-srv-title svg { width: 1rem; height: 1rem; color: #f97316; }

            /* Penanda "server aktif" memakai oranye, senada dengan
             * identitas warna aplikasi (permintaan user). */
            .tya-srv-uptime {
                display: inline-flex; align-items: center; gap: .35rem;
                font-size: .65rem; font-weight: 700; color: #c2410c;
                background: rgba(249,115,22,.14); padding: .18rem .5rem;
            }
            .tya-srv-dot { width: .4rem; height: .4rem; border-radius: 999px; background: #f97316; }

            .tya-srv-rows { display: flex; flex-direction: column; gap: .7rem; }

            .tya-srv-row-top {
                display: flex; align-items: baseline; justify-content: space-between;
                gap: .5rem; margin-bottom: .28rem;
            }
            .tya-srv-label {
                font-size: .66rem; font-weight: 700; text-transform: uppercase;
                letter-spacing: .04em; color: #71717a;
            }
            .dark .tya-srv-label { color: #a1a1aa; }
            .tya-srv-value { font-size: .78rem; font-weight: 800; color: #27272a; }
            .dark .tya-srv-value { color: #e4e4e7; }
            .tya-srv-sub { font-size: .62rem; font-weight: 500; color: #a1a1aa; }

            .tya-srv-track { height: .4rem; background: #f1f1f4; overflow: hidden; }
            .dark .tya-srv-track { background: rgba(255,255,255,.07); }
            .tya-srv-fill { height: 100%; transition: width .4s ease; }

            /* Bandwidth: dua kotak berdampingan (unduh & unggah). */
            .tya-srv-net { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-top: .2rem; }
            .tya-srv-net-box {
                display: flex; align-items: center; gap: .45rem;
                padding: .45rem .55rem; background: #fafafa;
            }
            .dark .tya-srv-net-box { background: rgba(255,255,255,.03); }
            .tya-srv-net-icon {
                width: 1.55rem; height: 1.55rem; flex-shrink: 0;
                display: flex; align-items: center; justify-content: center;
            }
            .tya-srv-net-icon svg { width: .85rem; height: .85rem; }
             /* Unduh & unggah dibedakan lewat TERANG-GELAP oranye, bukan
             * warna yang berbeda keluarga — supaya seluruh kartu ini
             * tetap satu nada. */
            .tya-srv-net-down { background: rgba(249,115,22,.14); color: #ea580c; }
            .tya-srv-net-up { background: rgba(194,65,12,.14); color: #9a3412; }
            .tya-srv-net-val { font-size: .76rem; font-weight: 800; line-height: 1.1; color: #27272a; }
            .dark .tya-srv-net-val { color: #e4e4e7; }
            .tya-srv-net-lbl { font-size: .6rem; font-weight: 600; color: #a1a1aa; }

            .tya-srv-note { font-size: .6rem; color: #a1a1aa; margin-top: .5rem; line-height: 1.4; }
        </style>

        @php($srv = $this->getStatus())

        <div class="tya-srv-head">
            <div class="tya-srv-title">
                <x-filament::icon icon="lucide-server" />
                <span>Status Server</span>
            </div>

            @if ($srv['uptime'])
                <span class="tya-srv-uptime">
                    <span class="tya-srv-dot"></span>
                    Aktif {{ $srv['uptime'] }}
                </span>
            @endif
        </div>

        <div class="tya-srv-rows">
            {{-- CPU --}}
            @php($cpu = $srv['cpu'])
            <div>
                <div class="tya-srv-row-top">
                    <span class="tya-srv-label">CPU</span>
                    <span class="tya-srv-value">{{ $cpu === null ? '-' : number_format($cpu, 1, ',', '.').'%' }}</span>
                </div>
                <div class="tya-srv-track">
                    <div class="tya-srv-fill" style="width: {{ $cpu === null ? 0 : min(100, $cpu) }}%; background: {{ \App\Filament\Widgets\ServerStatusWidget::warnaBar($cpu) }};"></div>
                </div>
            </div>

            {{-- RAM --}}
            @php($ram = $srv['ram'])
            <div>
                <div class="tya-srv-row-top">
                    <span class="tya-srv-label">RAM</span>
                    <span class="tya-srv-value">
                        {{ $ram ? number_format($ram['percent'], 1, ',', '.').'%' : '-' }}
                        @if ($ram)
                            <span class="tya-srv-sub">
                                ({{ \App\Filament\Widgets\ServerStatusWidget::formatBytes($ram['used']) }} /
                                {{ \App\Filament\Widgets\ServerStatusWidget::formatBytes($ram['total']) }})
                            </span>
                        @endif
                    </span>
                </div>
                <div class="tya-srv-track">
                    <div class="tya-srv-fill" style="width: {{ $ram ? min(100, $ram['percent']) : 0 }}%; background: {{ \App\Filament\Widgets\ServerStatusWidget::warnaBar($ram['percent'] ?? null) }};"></div>
                </div>
            </div>

            {{-- Penyimpanan --}}
            @php($disk = $srv['disk'])
            <div>
                <div class="tya-srv-row-top">
                    <span class="tya-srv-label">Penyimpanan</span>
                    <span class="tya-srv-value">
                        {{ $disk ? number_format($disk['percent'], 1, ',', '.').'%' : '-' }}
                        @if ($disk)
                            <span class="tya-srv-sub">
                                ({{ \App\Filament\Widgets\ServerStatusWidget::formatBytes($disk['used']) }} /
                                {{ \App\Filament\Widgets\ServerStatusWidget::formatBytes($disk['total']) }})
                            </span>
                        @endif
                    </span>
                </div>
                <div class="tya-srv-track">
                    <div class="tya-srv-fill" style="width: {{ $disk ? min(100, $disk['percent']) : 0 }}%; background: {{ \App\Filament\Widgets\ServerStatusWidget::warnaBar($disk['percent'] ?? null) }};"></div>
                </div>
            </div>

            {{-- Bandwidth --}}
            @php($net = $srv['net'])
            <div>
                <div class="tya-srv-row-top">
                    <span class="tya-srv-label">Bandwidth</span>
                </div>
                <div class="tya-srv-net">
                    <div class="tya-srv-net-box">
                        <div class="tya-srv-net-icon tya-srv-net-down">
                            <x-filament::icon icon="lucide-arrow-down" />
                        </div>
                        <div>
                            <div class="tya-srv-net-val">
                                {{ $net ? \App\Filament\Widgets\ServerStatusWidget::formatBytes($net['rx']).'/s' : '-' }}
                            </div>
                            <div class="tya-srv-net-lbl">Unduh</div>
                        </div>
                    </div>

                    <div class="tya-srv-net-box">
                        <div class="tya-srv-net-icon tya-srv-net-up">
                            <x-filament::icon icon="lucide-arrow-up" />
                        </div>
                        <div>
                            <div class="tya-srv-net-val">
                                {{ $net ? \App\Filament\Widgets\ServerStatusWidget::formatBytes($net['tx']).'/s' : '-' }}
                            </div>
                            <div class="tya-srv-net-lbl">Unggah</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($srv['cpu'] === null && $srv['ram'] === null)
            <div class="tya-srv-note">
                Data server tidak terbaca — kemungkinan server bukan Linux, atau akses ke
                <code>/proc</code> dibatasi (open_basedir). Penyimpanan tetap terbaca.
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
