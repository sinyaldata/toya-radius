@php
    $timezone = config('app.timezone', 'Asia/Jakarta');
@endphp

<div
    x-data="{
        base: {{ now($timezone)->valueOf() }},
        startedAt: Date.now(),
        timezone: '{{ $timezone }}',
        now: null,
        tick() {
            this.now = new Date(this.base + (Date.now() - this.startedAt));
        },
        formatted() {
            if (!this.now) return '';
            const parts = new Intl.DateTimeFormat('en-GB', {
                timeZone: this.timezone, day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
            }).formatToParts(this.now).reduce((acc, p) => ({ ...acc, [p.type]: p.value }), {});
            return `${parts.day}-${parts.month}-${parts.year} ${parts.hour}:${parts.minute}:${parts.second}`;
        },
    }"
    x-init="tick(); setInterval(() => tick(), 1000)"
    class="tya-waktu-server"
>
    <x-filament::icon icon="lucide-clock" class="tya-waktu-server-icon" />
    <div class="tya-waktu-server-lines">
        <span class="tya-waktu-server-label">Waktu Server</span>
        <span x-text="formatted()" class="tya-waktu-server-value"></span>
    </div>
</div>

