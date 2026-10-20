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

    $chartAccessibleLabel = trim(implode('. ', array_filter([
        $heading instanceof Htmlable ? strip_tags($heading->toHtml()) : $heading,
        $description instanceof Htmlable ? strip_tags($description->toHtml()) : $description,
    ], fn ($value): bool => filled($value))));
@endphp

{{-- Kartu "Grafik Pendapatan" -- salinan dari kartu Grafik Pengguna
     Online (widget serupa yang sudah ada), disederhanakan (tidak
     perlu tema gelap paksa, kartu ini ikut terang/gelap tema aplikasi
     seperti kartu biasa lainnya). Satu-satunya alasan pakai view
     custom di sini: mengganti dropdown filter "Harian/Bulanan" jadi
     tombol pil, persis permintaan user, gaya sama dengan kartu
     serupa itu.

     CATATAN (pelajaran dari kartu serupa itu): JANGAN PERNAH tulis
     nama tag Blade/HTML dengan kurung siku asli di dalam komentar
     mana pun di file .blade.php -- Blade membaca seluruh isi file
     mencari pola tag, termasuk di dalam komentar, dan bisa salah
     kompilasi. Selalu deskripsikan pakai kata-kata biasa saja. --}}
<x-filament-widgets::widget class="fi-wi-chart tya-sales-chart">
    <style>
        .tya-sales-filter-btns {
            display: inline-flex !important;
            padding: .2rem !important;
            background: rgba(128, 128, 128, .12) !important;
            border-radius: 999px !important;
            gap: .15rem !important;
        }
        button.tya-sales-filter-btn {
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
        button.tya-sales-filter-btn:hover:not(.tya-sales-filter-btn-active) {
            background: rgba(128, 128, 128, .12) !important;
        }
        button.tya-sales-filter-btn-active {
            background: #ea580c !important;
            color: #fff !important;
        }

        @media (max-width: 640px) {
            .tya-sales-chart .fi-section-header {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: .6rem !important;
            }
            .tya-sales-filter-btns {
                width: 100% !important;
                justify-content: center !important;
            }
            button.tya-sales-filter-btn {
                flex: 1 1 0 !important;
                text-align: center !important;
            }
        }
    </style>

    <x-filament::section
        :description="$description"
        :heading="$heading"
        :collapsible="$isCollapsible"
    >
        @if ($filters || method_exists($this, 'getFiltersSchema'))
            <x-slot name="afterHeader">
                @if ($filters)
                    <div class="tya-sales-filter-btns" wire:target="filter">
                        @foreach ($filters as $value => $label)
                            <button
                                type="button"
                                wire:click="$set('filter', '{{ $value }}')"
                                @class([
                                    'tya-sales-filter-btn',
                                    'tya-sales-filter-btn-active' => (string) $filter === (string) $value,
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
