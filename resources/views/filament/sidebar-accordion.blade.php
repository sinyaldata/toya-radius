{{--
    Sidebar Filament defaultnya: tiap grup menu (Hotspot, Invoice, Mitra, dst)
    buka/tutup independen satu sama lain. Owner minta perilaku "accordion":
    buka grup baru otomatis menutup grup lain yang sedang terbuka.

    CATATAN: "grup kebuka sendiri di kunjungan pertama" (bug yang beberapa
    kali coba ditambal di sini) SEKARANG diperbaiki di tempat yang lebih
    tepat -- lihat filament.sidebar-collapsed-seed (HEAD_END), yang mengisi
    localStorage SEBELUM Alpine sempat membaca nilai awalnya. File INI
    sekarang murni cuma untuk perilaku "accordion"-nya saja.
--}}
<script>
    document.addEventListener('alpine:init', () => {
        // Filament sendiri daftar store 'sidebar' di event 'alpine:init' juga —
        // listener ini dipasang belakangan (script ada di body akhir), jadi
        // pasti jalan SETELAH store aslinya sudah terbentuk saat event ini fire.
        document.addEventListener('alpine:initialized', () => {
            const store = window.Alpine.store('sidebar');

            if (!store || typeof store.toggleCollapsedGroup !== 'function') {
                return;
            }

            // Daftar ini WAJIB sama persis dengan label NavigationGroup di
            // AdminPanelProvider (dan dengan daftar yang sama di
            // filament.sidebar-collapsed-seed).
            const semuaLabelGrup = [
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

            store.toggleCollapsedGroup = function (label) {
                // PENTING: item TANPA grup (Dashboard, Transaksi, dll) numpang
                // di "grup semu" berlabel KOSONG ("") yang TIDAK PUNYA tombol
                // buka/tutup sendiri. Kalau ikut dimasukkan ke daftar "tutup
                // semua yang lain", dia collapsed selamanya tanpa cara membuka
                // lagi — makanya Dashboard/Transaksi sempat hilang. Sudah
                // otomatis terkecualikan karena semuaLabelGrup di atas cuma
                // berisi grup yang BENERAN terdaftar, grup semu itu tidak
                // pernah masuk daftar tersebut.
                const currentlyCollapsed = Array.isArray(this.collapsedGroups) ? this.collapsedGroups : [...semuaLabelGrup];
                const isOpeningThisGroup = currentlyCollapsed.includes(label);

                if (isOpeningThisGroup) {
                    // Grup ini lagi tertutup & mau dibuka -> tutup semua grup
                    // LAIN (yang punya nama), buka cuma yang ini.
                    this.collapsedGroups = semuaLabelGrup.filter(l => l !== label);
                } else {
                    // Grup ini lagi terbuka & mau ditutup -> tutup dia saja,
                    // biarkan status grup lain apa adanya.
                    this.collapsedGroups = currentlyCollapsed.concat(label);
                }
            };
        });
    });
</script>
