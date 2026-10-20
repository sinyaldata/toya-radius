{{-- ============================================================
     KARTU STATISTIK SERBAGUNA (dipakai bersama beberapa halaman).

     Sengaja dibuat SATU view yang dipakai ulang, supaya tampilan
     kartu di halaman User PPPoE, Invoice, dan Kasir persis sama dan
     kalau nanti mau diubah cukup edit di sini saja.

     Cara pakai dari kelas Widget:
       protected string $view = 'filament.widgets.tya-stat-cards';
       public function getCards(): array { return [ ... ]; }

     Bentuk satu kartu:
       [
         'label' => 'Sesi Online',
         'value' => '12',            // sudah diformat sendiri (string)
         'icon'  => 'lucide-wifi',
         'note'  => 'opsional',      // keterangan kecil di bawah angka
         'color' => '#f97316',       // opsional, default oranye
         'url'   => '/admin/...',    // opsional -- kalau diisi, kartu jadi bisa diklik
       ]

     Sudutnya KOTAK (border-radius 0) mengikuti gaya baru aplikasi.
     ============================================================ --}}
<x-filament-widgets::widget>
    <style>
        .tya-sc-grid {
            display: grid;
            gap: 1rem;
            /* Jumlah kolom menyesuaikan sendiri: minimal 210px per
             * kartu, sisanya dibagi rata. Jadi 2 kartu tampil lebar,
             * 4 kartu tetap muat sejajar di layar besar, dan otomatis
             * turun ke bawah di layar sempit tanpa media query. */
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        }

        .tya-sc-card {
            position: relative;
            overflow: hidden;
            background: #ffffff;
            border: 1px solid #e4e4e7;
            border-radius: 0;
            padding: 1.1rem 1.25rem;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .tya-sc-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -6px rgba(249, 115, 22, .25);
        }

        /* Kartu yang bisa diklik (ada 'url') -- dibungkus <a>, jadi
         * warna teks & underline bawaan link perlu dinetralkan supaya
         * tampilannya tetap sama persis dengan kartu biasa. */
        a.tya-sc-card {
            display: block;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
        }

        .tya-sc-accent { position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .tya-sc-row { display: flex; align-items: center; justify-content: space-between; gap: .85rem; }
        .tya-sc-label { font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #71717a; }
        .tya-sc-value { font-size: 1.4rem; font-weight: 800; line-height: 1.15; margin-top: .25rem; color: #1c1917; }
        .tya-sc-note { font-size: .68rem; font-weight: 600; margin-top: .3rem; color: #c2410c; }
        .tya-sc-icon {
            width: 2.6rem; height: 2.6rem; border-radius: 0;
            display: flex; align-items: center; justify-content: center;
            color: #fff; flex-shrink: 0;
        }

        .dark .tya-sc-card { background: #27272a; border-color: rgba(255,255,255,.08); }
        .dark .tya-sc-label { color: #a1a1aa; }
        .dark .tya-sc-value { color: #fafafa; }
        .dark .tya-sc-note { color: #fb923c; }

        @media (max-width: 640px) {
            .tya-sc-grid { grid-template-columns: repeat(2, 1fr); gap: .6rem; }
            .tya-sc-card { padding: .8rem .85rem; }
            .tya-sc-value { font-size: 1.1rem; }
            .tya-sc-label { font-size: .62rem; letter-spacing: .03em; }
            .tya-sc-icon { width: 1.9rem; height: 1.9rem; }
            .tya-sc-icon svg { width: 1rem !important; height: 1rem !important; }
        }
    </style>

    <div class="tya-sc-grid">
        @foreach ($this->getCards() as $card)
            @php
                $warna = $card['color'] ?? '#f97316';
                // Kartu jadi <a> (bisa diklik) kalau 'url' diisi, kalau
                // tidak tetap <div> biasa seperti sebelumnya. Tag & atribut
                // href disiapkan di sini (bukan langsung ditulis di tag)
                // supaya href tetap lolos escaping HTML normal lewat e().
                $tagKartu = ! empty($card['url']) ? 'a' : 'div';
                $hrefKartu = ! empty($card['url']) ? ' href="'.e($card['url']).'"' : '';
            @endphp
            <{!! $tagKartu !!} class="tya-sc-card"{!! $hrefKartu !!}>
                <div class="tya-sc-accent" style="background: {{ $warna }};"></div>

                <div class="tya-sc-row">
                    <div style="min-width:0;">
                        <div class="tya-sc-label">{{ $card['label'] }}</div>
                        <div class="tya-sc-value">{{ $card['value'] }}</div>

                        @if (! empty($card['note']))
                            <div class="tya-sc-note">{{ $card['note'] }}</div>
                        @endif
                    </div>

                    <div class="tya-sc-icon" style="background: {{ $warna }};">
                        <x-filament::icon :icon="$card['icon']" style="width:1.3rem; height:1.3rem;" />
                    </div>
                </div>
            </{!! $tagKartu !!}>
        @endforeach
    </div>
</x-filament-widgets::widget>
