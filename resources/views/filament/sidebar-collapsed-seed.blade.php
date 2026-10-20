{{--
    Kenapa file ini perlu ada (root cause sebenarnya dari "grup sidebar
    kebuka sendiri padahal belum diklik"):

    Filament simpan status buka/tutup grup di localStorage BROWSER, lewat
    Alpine `$persist(null).as('collapsedGroups')`. Nilai AWALnya (SEBELUM
    Alpine sempat menyimpan apa pun) selalu `null` untuk browser yang belum
    pernah membuka sidebar ini. Filament sendiri lalu memanggil
    `this.collapsedGroups.includes(label)` untuk tiap grup saat merender --
    kalau nilainya masih `null` di momen itu, ini melempar error JS
    ("Cannot read properties of null"), dan grup yang gagal dievaluasi
    KARENA ERROR itu malah tetap tampil apa adanya di HTML (tidak
    tersembunyi), bukan ikut aturan ->collapsed() yang didaftarkan di
    AdminPanelProvider.

    Menambal store SETELAH Alpine jalan (lihat sidebar-accordion.blade.php)
    ternyata TIDAK CUKUP -- pada saat itu Alpine SUDAH KEBURU mengevaluasi
    (dan gagal) tampilan tiap grup sekali di awal; effect reaktif yang
    gagal karena exception tidak otomatis mencoba lagi hanya karena kita
    mengubah nilainya belakangan.

    Solusi yang benar-benar menutup akar masalah: isi localStorage-nya
    LEBIH DULU, SEBELUM Alpine sempat membaca nilai awal itu sama sekali.
    Makanya script ini didaftarkan di HEAD_END (lihat AdminPanelProvider),
    bukan BODY_END seperti sidebar-accordion -- <head> selalu dieksekusi
    browser LEBIH DULU daripada script apa pun di <body>, termasuk skrip
    Filament/Alpine sendiri yang baru dimuat di akhir <body>.
--}}
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
{{-- TYA-KEPALA (tools/ringankan-tampilan.py) --}}
@include('filament.tya-kepala')
