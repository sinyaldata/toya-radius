@php
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\Contracts\Support\Htmlable;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $filters = $this->getFilters();
    $isCollapsible = $this->isCollapsible();
    $type = $this->getType();
    $maxHeight = $this->getMaxHeight();
    $hasMaxHeight = filled($maxHeight) && $maxHeight !== '100%';
    $isEmpty = $this->isEmpty();

    // The chart paints onto a bare `<canvas>`, which exposes no accessible name, so build a text
    // alternative from the widget's heading and description (WCAG 1.1.1) for `role="img"` + `aria-label`.
    $chartAccessibleLabel = trim(implode('. ', array_filter([
        $heading instanceof Htmlable ? strip_tags($heading->toHtml()) : $heading,
        $description instanceof Htmlable ? strip_tags($description->toHtml()) : $description,
    ], fn ($value): bool => filled($value))));
@endphp

<x-filament-widgets::widget class="fi-wi-chart tya-online-dark">
    <style>
        /* Kartu "Grafik Pengguna Online" -- tema gelap mengikuti gambar
           referensi user, TAPI CUMA saat mode gelap aplikasi aktif.
           Semua aturan gelap di bawah diberi awalan ".dark" supaya
           TIDAK berlaku sama sekali saat mode terang.

           FIX 2026-09-18 (PENTING): blok <style> ini SEMPAT RUSAK
           strukturnya dari editan sebelumnya -- ada aturan yang
           kebuka kurung kurawalnya tapi tidak pernah ditutup dengan
           benar sebelum aturan berikutnya mulai, bikin SELURUH isi
           <style> ini berantakan/tidak konsisten dibaca browser
           (itulah kenapa tombol filternya kemarin kelihatan polos
           tanpa gaya sama sekali -- CSS-nya gagal dibaca, bukan
           soal nama class salah). Ditulis ulang bersih dari awal di
           bawah ini, SEKALIAN ditambah tata letak khusus HP (permintaan
           user: judul & tombol filter numpuk jadi 3 baris di layar
           sempit, bikin grafiknya kelihatan kecil dengan banyak ruang
           kosong di atasnya -- sekarang di HP judul & tombol filter
           disusun turun ke bawah, bukan dipaksa sebaris). */
        .dark .tya-online-dark .fi-section {
            background: #18181b !important;
            border-color: rgba(255, 255, 255, 0.06) !important;
        }
        .dark .tya-online-dark .fi-section-header-heading {
            color: #fafafa !important;
        }

        /* Kelompok tombol filter -- gaya "segmented control", satu
           tombol aktif berwarna oranye, sisanya abu netral. Warnanya
           dibuat netral/tidak diberi awalan .dark supaya kebaca di
           kedua mode terang/gelap tanpa perlu didefinisikan dua kali. */
        .tya-online-filter-btns {
            display: inline-flex !important;
            padding: .2rem !important;
            background: rgba(128, 128, 128, .14) !important;
            border-radius: 999px !important;
            gap: .15rem !important;
        }
        button.tya-online-filter-btn {
            border: none !important;
            background: transparent !important;
            font-size: .68rem !important;
            font-weight: 600 !important;
            padding: .35rem .7rem !important;
            border-radius: 999px !important;
            color: rgba(113, 113, 122, .9) !important;
            cursor: pointer !important;
            white-space: nowrap !important;
            transition: all .15s ease !important;
            line-height: 1.2 !important;
        }
        button.tya-online-filter-btn:hover:not(.tya-online-filter-btn-active) {
            background: rgba(128, 128, 128, .14) !important;
        }
        button.tya-online-filter-btn-active {
            background: #ea580c !important;
            color: #fff !important;
        }

        /* Jarak bawaan Filament antara judul & isi kartu (gap-y section)
           dihapus total -- berlaku di kedua mode, ini soal jarak/
           layout bukan warna tema. */
        .tya-online-dark .fi-section {
            gap: 0 !important;
            row-gap: 0 !important;
        }
        .tya-online-dark .fi-section-content-ctn {
            gap: 0 !important;
            row-gap: 0 !important;
        }
        .tya-online-dark .fi-wi-chart-canvas-ctn {
            margin-top: 0 !important;
        }
        /* Rapetin lagi jarak dalam kartu (permintaan user: "grafik
           masih kelihatan belum penuh") -- padding bawaan Filament di
           header & badan kartu dikurangi supaya grafiknya mengisi
           kartu lebih maksimal, tidak menyisakan banyak ruang kosong
           di sekitarnya. */
        .tya-online-dark .fi-section-header {
            padding-bottom: .5rem !important;
        }
        .tya-online-dark .fi-section-content-ctn > .fi-section-content {
            padding-top: .5rem !important;
            padding-bottom: .5rem !important;
        }

        /* KHUSUS HP (permintaan user): judul & tombol filter ditumpuk
           ke bawah (bukan dipaksa sebaris) -- di layar sempit, dua-
           duanya sekaligus tidak muat sebaris tanpa membuat judulnya
           patah jadi 3 baris seperti sebelumnya. Tombol filternya
           juga dilebarkan penuh & rata tengah supaya enak dipencet
           dengan jempol. */
        @media (max-width: 640px) {
            .tya-online-dark .fi-section-header {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: .6rem !important;
            }
            .tya-online-filter-btns {
                width: 100% !important;
                justify-content: center !important;
            }
            button.tya-online-filter-btn {
                flex: 1 1 0 !important;
                text-align: center !important;
            }
        }
    </style>

    <x-filament::section
        :description="$description"
        :heading="$heading"
        :collapsible="$isCollapsible"
        icon="lucide-radio"
        icon-color="primary"
    >
        @if ($filters || method_exists($this, 'getFiltersSchema'))
            <x-slot name="afterHeader">
                @if ($filters)
                    {{-- Diganti jadi kelompok TOMBOL (permintaan user:
                         "pake option button aja biar rapi") -- bukan
                         dropdown select standar lagi. --}}
                    <div class="tya-online-filter-btns" wire:target="filter">
                        @foreach ($filters as $value => $label)
                            <button
                                type="button"
                                wire:click="$set('filter', '{{ $value }}')"
                                @class([
                                    'tya-online-filter-btn',
                                    'tya-online-filter-btn-active' => (string) $filter === (string) $value,
                                ])
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                @endif

                @if (method_exists($this, 'getFiltersSchema'))
                    <x-filament::dropdown
                        placement="bottom-end"
                        shift
                        width="xs"
                        class="fi-wi-chart-filter"
                    >
                        <x-slot name="trigger">
                            {{ $this->getFiltersTriggerAction() }}
                        </x-slot>

                        <div class="fi-wi-chart-filter-content">
                            {{ $this->getFiltersSchema() }}

                            @if (method_exists($this, 'hasDeferredFilters') && $this->hasDeferredFilters())
                                <div
                                    class="fi-wi-chart-filter-content-actions-ctn"
                                >
                                    {{ $this->getFiltersApplyAction() }}

                                    {{ $this->getFiltersResetAction() }}
                                </div>
                            @endif
                        </div>
                    </x-filament::dropdown>
                @endif
            </x-slot>
        @endif

        <div
            @if ($pollingInterval = $this->getPollingInterval())
                wire:poll.{{ $pollingInterval }}="updateChartData"
            @endif
            @if ($isEmpty)
                style="display: none"
            @endif
        >
            <div
                x-load
                x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                wire:ignore
                data-chart-type="{{ $type }}"
                x-data="chart({
                            cachedData: @js($this->getCachedData()),
                            options: @js($this->getOptions()),
                            type: @js($type),
                        })"
                {{
                    (new FilamentComponentAttributeBag)
                        ->color(ChartWidgetComponent::class, $color)
                        ->class([
                            'fi-wi-chart-frame',
                            'fi-wi-chart-canvas-ctn',
                            'fi-wi-chart-frame-no-aspect-ratio' => $hasMaxHeight,
                        ])
                }}
            >
                <canvas
                    x-ref="canvas"
                    @if (filled($chartAccessibleLabel))
                        role="img"
                        aria-label="{{ $chartAccessibleLabel }}"
                    @endif
                    @style([
                        'width: 100%',
                        'height: 100%; max-height: 100%' => ! $hasMaxHeight,
                        ('max-height: ' . e($maxHeight)) => $hasMaxHeight,
                    ])
                ></canvas>

                <span
                    x-ref="backgroundColorElement"
                    class="fi-wi-chart-bg-color"
                ></span>

                <span
                    x-ref="borderColorElement"
                    class="fi-wi-chart-border-color"
                ></span>

                <span
                    x-ref="gridColorElement"
                    class="fi-wi-chart-grid-color"
                ></span>

                <span
                    x-ref="textColorElement"
                    class="fi-wi-chart-text-color"
                ></span>
            </div>
        </div>

        @if ($isEmpty)
            @if ($emptyState = $this->getEmptyState())
                {{ $emptyState }}
            @else
                <div
                    @class([
                        'fi-wi-chart-frame',
                        'fi-wi-chart-frame-no-aspect-ratio' => $hasMaxHeight,
                    ])
                    @style([
                        ('min-height: ' . e($maxHeight)) => $hasMaxHeight,
                    ])
                >
                    <x-filament::empty-state
                        :contained="false"
                        :description="$this->getEmptyStateDescription()"
                        :heading="$this->getEmptyStateHeading()"
                        :icon="$this->getEmptyStateIcon()"
                        icon-color="gray"
                    >
                        @if ($emptyStateActions = $this->getEmptyStateActions())
                            <x-slot name="footer">
                                <x-filament::actions
                                    :actions="$emptyStateActions"
                                    alignment="center"
                                />
                            </x-slot>
                        @endif
                    </x-filament::empty-state>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
