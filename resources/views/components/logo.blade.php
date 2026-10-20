@php
    $iconSize = $iconSize ?? 38;
@endphp

<div class="flex items-center gap-2.5">
    <svg
        width="{{ $iconSize }}"
        height="{{ $iconSize }}"
        viewBox="0 0 40 40"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        class="shrink-0"
        aria-hidden="true"
    >
        <rect x="1" y="1" width="38" height="38" rx="11" fill="#2563EB" />
        <rect x="1" y="1" width="38" height="38" rx="11" fill="none" stroke="#1D4ED8" stroke-opacity=".4" />

        <path d="M13 24.5c1.9-1.9 4.4-3 7-3s5.1 1.1 7 3" stroke="white" stroke-opacity=".65" stroke-width="2.3" stroke-linecap="round" />
        <path d="M16.3 27.8c1-1 2.3-1.6 3.7-1.6s2.7.6 3.7 1.6" stroke="white" stroke-width="2.3" stroke-linecap="round" />

        <path
            d="M20 8.2c2.8 3.6 5.8 7.8 5.8 11.3 0 3.2-2.6 5.8-5.8 5.8s-5.8-2.6-5.8-5.8c0-3.5 3-7.7 5.8-11.3Z"
            fill="white"
        />
    </svg>

    <div class="leading-tight tr-logo-word">
        <div
            class="font-extrabold text-lg tracking-tight"
            style="color: #2563EB; font-family: var(--font-display, inherit);"
        >
            TOYA
        </div>
        <div class="text-[0.65rem] font-bold tracking-[0.28em] text-gray-500 dark:text-gray-400 -mt-0.5">
            RADIUS
        </div>
    </div>
</div>
