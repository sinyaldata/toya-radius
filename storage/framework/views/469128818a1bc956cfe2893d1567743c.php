
<script>
    (function () {
        try {
            if (localStorage.getItem('collapsedGroups') !== null) {
                // Sudah ada status tersimpan (baik dari pemakaian normal
                // maupun dari sesi sebelum perbaikan ini dipasang) --
                // JANGAN ditimpa, biarkan preferensi buka/tutup yang
                // sudah ada tetap dipakai.
                return;
            }

            // Daftar ini WAJIB sama persis dengan label NavigationGroup di
            // App\Providers\Filament\AdminPanelProvider::panel()
            // (->navigationGroups([...])). Kalau nanti ada grup baru
            // ditambah/dihapus/diganti nama di sana, ikut disesuaikan
            // juga di sini.
            var semuaLabelGrup = [
                'Master Data',
                'Mitra',
                'Profile Paket',
                'Hotspot',
                'Pelanggan',
                'FTTH',
                'Invoice',
                'Keuangan',
                'Transaksi',
                'Area / Wilayah',
                'Laporan',
                'Pengaturan',
                'Log',
            ];

            // Semua grup dianggap TERTUTUP di kunjungan pertama -- cocok
            // dengan ->collapsed() yang didaftarkan tiap NavigationGroup.
            localStorage.setItem('collapsedGroups', JSON.stringify(semuaLabelGrup));
        } catch (e) {
            // localStorage tidak bisa diakses (mode privat yang sangat
            // ketat, dsb) -- biarkan saja, jangan sampai error di sini
            // menghalangi sisa halaman untuk tetap tampil.
        }
    })();
</script>

<?php echo $__env->make('filament.tya-kepala', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php /**PATH /var/www/toya-radius/resources/views/filament/sidebar-collapsed-seed.blade.php ENDPATH**/ ?>