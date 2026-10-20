{{-- Kartu "Butuh Bantuan?" + tanda tangan merek di dasar sidebar.

     Nomor/tautan support diambil dari Pengaturan > Perusahaan (kontak
     WhatsApp). Kalau kontak belum diisi, tombolnya mengarah ke halaman
     Pengaturan supaya orangnya tahu apa yang harus dilengkapi — bukan
     tautan mati. --}}
@php
    $kontak = \App\Models\Setting::get('isp_contact');
    $waNumber = $kontak ? preg_replace('/[^0-9]/', '', $kontak) : null;

    // 08xxx -> 628xxx supaya link wa.me valid
    if ($waNumber && str_starts_with($waNumber, '0')) {
        $waNumber = '62'.substr($waNumber, 1);
    }

    $url = $waNumber
        ? 'https://wa.me/'.$waNumber
        : \App\Filament\Pages\SettingsPage::getUrl();

    // Nama perusahaan dipecah di SPASI TERAKHIR: bagian depan gelap,
    // kata terakhir oranye — meniru gaya "ToyaRadius" pada rancangan.
    // Dibuat dari nama yang tersimpan, bukan teks tetap, supaya tenant
    // lain ikut memakai namanya sendiri. Nama satu kata tampil polos
    // (tidak ada yang bisa dipecah), dan itu memang disengaja.
    $namaIsp = trim((string) \App\Models\Setting::get('isp_name', 'Toya Radius')) ?: 'Toya Radius';
    $posisiSpasi = mb_strrpos($namaIsp, ' ');
    $merekDepan = $posisiSpasi !== false ? mb_substr($namaIsp, 0, $posisiSpasi) : $namaIsp;
    $merekBelakang = $posisiSpasi !== false ? mb_substr($namaIsp, $posisiSpasi + 1) : '';
@endphp

<div class="tya-help-dock" x-show="$store.sidebar.isOpen" x-cloak>
    <div class="tya-help-card">
        {{-- Lingkaran hias di pojok kanan atas. aria-hidden karena murni
             dekorasi — tidak ada gunanya dibacakan pembaca layar. --}}
        <span class="tya-help-blob" aria-hidden="true"></span>

        <div class="tya-help-head">
            <span class="tya-help-badge">
                <x-filament::icon icon="lucide-life-buoy" class="tya-help-icon" />
            </span>
            <div class="tya-help-teks">
                <div class="tya-help-title">Butuh Bantuan?</div>
                <div class="tya-help-sub">Hubungi tim support kami, siap membantu Anda.</div>
            </div>
        </div>

        <a href="{{ $url }}" target="_blank" rel="noopener" class="tya-help-btn">
            <span>Buka Bantuan</span>
            <x-filament::icon icon="lucide-arrow-right" class="tya-help-btn-icon" />
        </a>
    </div>

    <div class="tya-help-brand">
        <div class="tya-help-brand-name">
            {{ $merekDepan }}@if ($merekBelakang)<span>{{ $merekBelakang }}</span>@endif
        </div>
        <div class="tya-help-brand-tag">Stabil &bull; Aman &bull; Bersama</div>
    </div>
</div>
