<x-filament-widgets::widget>
    <style>
        .tya-hs-card {
            background: #ffffff;
            border: 1px solid #e4e4e7;
        }
        .tya-hs-card .tya-hs-label { color: #71717a; }
        .tya-hs-card .tya-hs-value { color: #1c1917; }
        .tya-hs-card .tya-hs-note { color: #c2410c; }

        /* Kartu yang bisa diklik (ada 'url') -- dibungkus <a>, jadi
         * warna teks & underline bawaan link dinetralkan supaya
         * tampilannya tetap sama persis dengan kartu biasa. */
        a.tya-hs-card {
            text-decoration: none;
            color: inherit;
            cursor: pointer;
        }

        .dark .tya-hs-card {
            background: #27272a;
            border-color: rgba(255,255,255,.08);
        }
        .dark .tya-hs-card .tya-hs-label { color: #a1a1aa; }
        .dark .tya-hs-card .tya-hs-value { color: #fafafa; }
        .dark .tya-hs-card .tya-hs-note { color: #fb923c; }

        @media (max-width: 1279px) {
            .tya-hs-grid { grid-template-columns: repeat(2, 1fr) !important; }
        }

        @media (max-width: 640px) {
            .tya-hs-grid { grid-template-columns: repeat(2, 1fr) !important; }

            /* Ikon kartu (Total Voucher, Voucher Expired, dst) kelihatan
             * terlalu besar di HP karena ukurannya inline/tetap (2.75rem)
             * padahal kartunya sendiri menyempit jadi 2 kolom -- dikecilkan
             * khusus di mode HP supaya proporsional lagi. */
            .tya-hs-icon-ctn {
                width: 2rem !important;
                height: 2rem !important;
                border-radius: 0.5rem !important;
            }

            .tya-hs-icon-ctn svg {
                width: 1rem !important;
                height: 1rem !important;
            }

            .tya-hs-value {
                font-size: 1.3rem !important;
            }
        }
    </style>

    {{-- Kartu "Voucher Aktif", "Voucher Online" & "Voucher Offline" sudah
         dihapus (permintaan user, lihat HotspotUserOverview::getCards())
         -- tersisa 4 kartu: Total Voucher, Voucher Expired, Laku Hari
         Ini, Digenerate Hari Ini. Grid disesuaikan jadi 4 kolom sejajar
         di layar lebar, 2 kolom di layar sedang & HP (lihat @media di
         atas). --}}
    <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:1rem;" class="tya-hs-grid">
        @foreach ($this->getCards() as $card)
            @php
                // Kartu jadi <a> (bisa diklik) kalau 'url' diisi, kalau
                // tidak tetap <div> biasa seperti sebelumnya.
                $tagKartu = ! empty($card['url']) ? 'a' : 'div';
                $hrefKartu = ! empty($card['url']) ? ' href="'.e($card['url']).'"' : '';
            @endphp
            <{!! $tagKartu !!}
                class="tya-hs-card"{!! $hrefKartu !!}
                style="
                    display: block;
                    border-radius: 0.85rem;
                    padding: 1.1rem 1.25rem;
                    position: relative;
                    overflow: hidden;
                    transition: transform .15s ease, box-shadow .15s ease;
                "
                onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px -6px rgba(249,115,22,.25)';"
                onmouseout="this.style.transform=''; this.style.boxShadow='';"
            >
                {{-- Garis aksen oranye di sisi kiri --}}
                <div style="position:absolute; left:0; top:0; bottom:0; width:4px; background:#f97316;"></div>

                <div style="display:flex; align-items:center; justify-content:space-between; gap:.85rem; width:100%;">
                    <div>
                        <div class="tya-hs-label" style="font-size:.72rem; font-weight:600; letter-spacing:.02em; text-transform:uppercase;">
                            {{ $card['label'] }}
                        </div>
                        <div class="tya-hs-value" style="font-size:1.45rem; font-weight:800; line-height:1.15; margin-top:.2rem;">
                            {{ number_format($card['value']) }}
                        </div>

                        {{-- Keterangan kecil opsional di bawah angka,
                             mis. rincian "Aplikasi 12 • Telegram 30". --}}
                        @if (! empty($card['note']))
                            <div class="tya-hs-note" style="font-size:.68rem; font-weight:600; margin-top:.3rem;">
                                {{ $card['note'] }}
                            </div>
                        @endif
                    </div>

                    <div class="tya-hs-icon-ctn" style="
                        width:2.75rem; height:2.75rem; border-radius:0.65rem;
                        background:#f97316; color:#fff;
                        display:flex; align-items:center; justify-content:center;
                        box-shadow:0 4px 10px -2px rgba(249,115,22,.5);
                        flex-shrink:0;
                    ">
                        <x-filament::icon :icon="$card['icon']" style="width:1.35rem; height:1.35rem;" />
                    </div>
                </div>
            </{!! $tagKartu !!}>
        @endforeach
    </div>
</x-filament-widgets::widget>
