{{-- ============================================================
     FIX: celah/strip HITAM tipis kelihatan tepat di bawah topbar
     (dilaporkan user 2026-09-19, lihat screenshot).

     PENYEBAB: ".fi-topbar" itu "position:fixed" (lihat theme.css,
     bagian "Footer & Topbar tetap di tempat saat discroll") --
     karena "fixed", topbar dikeluarkan dari alur halaman biasa, jadi
     wadah kontennya (".fi-main-ctn") diberi jarak atas
     ("padding-top") SEBESAR PERKIRAAN tinggi topbar (4.5rem, angka
     tetap/hardcode) supaya konten tidak ketutupan topbar.

     Masalahnya: tinggi topbar yang SEBENARNYA tidak selalu 4.5rem --
     dia punya "min-height" (bisa membesar sendiri kalau isinya,
     misalnya kotak jam "Waktu Server", butuh lebih dari itu, atau
     malah sedikit LEBIH PENDEK dari 4.5rem tergantung konten &
     ukuran layar). Begitu angka tetap itu MELESET dari tinggi asli,
     sisa selisihnya kelihatan sebagai strip warna latar body (hitam
     di dark mode) di antara topbar dan konten.

     PERBAIKAN: tinggi topbar diukur BENERAN lewat JS (ResizeObserver
     -- otomatis update lagi kalau topbar berubah tinggi, misalnya
     pas sidebar dibuka/ditutup atau layar di-resize), hasilnya
     disimpan ke custom property "--tya-topbar-h" di <html>, dan
     "padding-top" konten dibuat MENGIKUTI variabel itu (bukan angka
     tetap lagi). "4.5rem" di bawah cuma FALLBACK sebelum JS sempat
     jalan, bukan angka utama lagi.

     Ditulis di sini (Blade langsung, BUKAN theme.css/Vite) supaya
     TIDAK perlu "npm run build" -- cukup upload file ini + jalankan
     "php artisan view:clear". Sama seperti pola "global-table-style"
     di atasnya.
     ============================================================ --}}
<style>
    .fi-main-ctn {
        padding-top: var(--tya-topbar-h, 4.5rem) !important;
    }

    /* FIX #2 (2026-09-19, revisi): hilangkan ruang kosong gelap di
       atas footer pada halaman yang kontennya pendek (mis.
       Dashboard) -- footer sekarang langsung nempel di bawah
       konten, bukan dipaksa ke ujung layar. Duplikat dari perbaikan
       yang sama di theme.css (baris ".fi-main { flex: 0 1 auto }")
       -- ditaruh DUA-DUANYA supaya langsung aktif TANPA nunggu
       "npm run build" (blok <style> di sini kalah/menang berdasar
       urutan render, ditulis SETELAH theme.css jadi otomatis
       menang), dan tetap konsisten kalau nanti build dijalankan. */
    .fi-main {
        flex: 0 1 auto !important;
    }
</style>
<script>
    (function () {
        function syncTopbarHeight() {
            var topbar = document.querySelector('.fi-topbar');
            if (!topbar) {
                return;
            }
            var height = topbar.getBoundingClientRect().height;
            if (height > 0) {
                document.documentElement.style.setProperty('--tya-topbar-h', height + 'px');
            }
        }

        // Ukur begitu script ini jalan (topbar sudah ada di HTML awal).
        syncTopbarHeight();

        // Ukur ulang kalau tinggi topbar berubah (mis. jam "Waktu
        // Server" bikin baris baru di layar sempit, sidebar
        // dibuka/ditutup, dsb) -- lebih andal daripada cuma dengar
        // event "resize" window saja.
        var topbarEl = document.querySelector('.fi-topbar');
        if (topbarEl && window.ResizeObserver) {
            new ResizeObserver(syncTopbarHeight).observe(topbarEl);
        }

        // Jaga-jaga tambahan: navigasi Livewire (pindah halaman tanpa
        // reload penuh) kadang merender ulang topbar-nya.
        document.addEventListener('livewire:navigated', syncTopbarHeight);
        window.addEventListener('resize', syncTopbarHeight);
    })();
</script>
