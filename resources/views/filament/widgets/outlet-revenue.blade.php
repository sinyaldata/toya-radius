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

<x-filament-widgets::widget class="fi-wi-chart tya-donut-widget">
    {{-- Heading bawaan section SENGAJA tidak dipakai ($heading tidak
         dioper) -- judulnya digambar sendiri di bawah supaya bisa dua
         warna (hitam + oranye) dengan ikon di depannya, persis contoh
         desain yang dikirim user. --}}
    <x-filament::section
        :description="$description"
        :collapsible="$isCollapsible"
    >
        @php($tyaJudul = $this->getHeading())

        <div class="tya-donut-head">
            <div class="tya-donut-head-icon">
                <x-filament::icon icon="lucide-map-pin" />
            </div>
            <div class="tya-donut-head-title">
                {{-- Kata pertama ("Area/Wilayah") hitam, sisanya oranye. --}}
                @php($tyaPecah = explode(' ', (string) $tyaJudul, 3))
                <span class="tya-donut-head-dark">{{ $tyaPecah[0] ?? '' }} {{ $tyaPecah[1] ?? '' }}</span>
                <span class="tya-donut-head-accent">{{ $tyaPecah[2] ?? '' }}</span>
            </div>
        </div>
        @if ($filters || method_exists($this, 'getFiltersSchema'))
            <x-slot name="afterHeader">
                @if ($filters)
                    <x-filament::input.wrapper
                        inline-prefix
                        wire:target="filter"
                        class="fi-wi-chart-filter"
                    >
                        <x-filament::input.select
                            :aria-label="__('filament-widgets::chart.filter.label')"
                            inline-prefix
                            wire:model.live="filter"
                        >
                            @foreach ($filters as $value => $label)
                                <option value="{{ $value }}">
                                    {{ $label }}
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
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

        {{-- Pembungkus donat: agar teks "Total" bisa diletakkan tepat di
             tengah lingkaran (posisi absolut terhadap pembungkus ini). --}}
        <div class="tya-donut-wrap">
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

            {{-- Teks "Total Rp…" di tengah lingkaran DIHAPUS (permintaan
                 user). Method getAreaTotal() di widget SENGAJA tidak
                 ikut dihapus — masih dipakai/berguna kalau suatu saat
                 totalnya mau ditampilkan lagi di tempat lain. --}}
        </div>

        {{-- Ringkasan per Area/Wilayah (10 teratas), dua kolom. Nominal
             rupiah tidak ditampilkan di sini -- mengikuti contoh desain,
             dan supaya nama area tidak terpotong. Nominal lengkapnya ada
             di Laporan Penjualan. --}}
        <div class="tya-donut-legend">
            @forelse ($this->getAreaRows() as $row)
                <div class="tya-donut-legend-row">
                    <span class="tya-donut-dot" style="background: {{ $row['color'] }}"></span>
                    <span class="tya-donut-name">{{ $row['name'] }}</span>
                    <span
                        class="tya-donut-pct"
                        style="background: {{ $row['badge']['bg'] }}; color: {{ $row['badge']['text'] }};"
                    >{{ number_format($row['pct'], 1) }}%</span>
                </div>
            @empty
                <div class="tya-donut-empty">Belum ada pendapatan area bulan ini.</div>
            @endforelse
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
