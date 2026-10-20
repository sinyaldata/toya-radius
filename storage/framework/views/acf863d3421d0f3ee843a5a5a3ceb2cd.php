
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
<?php /**PATH /var/www/toya-radius/resources/views/filament/topbar-gap-fix.blade.php ENDPATH**/ ?>