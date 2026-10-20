<x-filament-panels::page>
    <form wire:submit="save">
        <x-filament::section>
            {{ $this->form }}
        </x-filament::section>

        <div style="margin-block-start: 1rem;">
            <x-filament::button type="submit">
                Simpan Hak Akses
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
