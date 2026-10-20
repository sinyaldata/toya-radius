<x-filament-panels::page>
    <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:0.75rem; padding:1.25rem; margin-bottom:1.5rem;">
        <div style="font-weight:700; color:#b91c1c; margin-bottom:0.5rem;">⚠️ Tindakan Ini Tidak Bisa Dibatalkan</div>
        <p style="font-size:0.875rem; color:#7f1d1d; margin:0 0 0.5rem;">
            Semua <strong>voucher, pelanggan, transaksi, invoice, dan log</strong> akan dihapus PERMANEN.
            Cocok dipakai kalau selama ini kamu masih dalam masa percobaan/testing dan mau mulai dari nol
            sebelum benar-benar dipakai untuk pelanggan asli.
        </p>
        <p style="font-size:0.875rem; color:#7f1d1d; margin:0;">
            <strong>Yang TIDAK dihapus</strong> (tetap aman): akun login kamu, paket layanan, IP Pool,
            NAS/Router, akun VPN, dan seluruh pengaturan aplikasi.
        </p>
    </div>

    <form wire:submit="resetData">
        {{ $this->form }}

        <x-filament::button type="submit" color="danger" class="mt-4">
            Hapus Semua Data Percobaan
        </x-filament::button>
    </form>
</x-filament-panels::page>
