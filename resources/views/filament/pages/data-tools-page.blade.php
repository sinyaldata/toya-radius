<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Backup Database</x-slot>
        <p class="text-sm text-gray-500">
            Klik <b>"Backup Database Sekarang"</b> di atas untuk mengunduh salinan penuh database
            (format .sql) — bisa dipakai untuk pemulihan data kapan pun diperlukan. Simpan file
            backup di tempat yang aman (Google Drive, hardisk eksternal, dll), jangan hanya di server ini.
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Export / Import Data</x-slot>
        <p class="text-sm text-gray-500">
            Gunakan tombol <b>Voucher</b>, <b>Outlet / Reseller</b>, dan <b>Pelanggan PPPoE</b> di atas
            untuk export data ke CSV atau import data massal dari CSV. Cocok untuk pindahan data dari
            sistem lama, atau membuat cadangan data per-kategori secara terpisah dari backup database penuh.
        </p>
    </x-filament::section>
</x-filament-panels::page>
