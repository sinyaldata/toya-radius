{{--
    Sub-menu di dalam GRUP (mis. "Pelanggan") sudah punya tombol buka/tutup
    sendiri -- lihat sidebar-accordion.blade.php, dipasang Filament lewat
    $store.sidebar.collapsedGroups.

    TAPI itu untuk GRUP (level PALING atas, $navigationGroup). "Profile" di
    dalam grup Pelanggan itu BUKAN grup -- itu item BIASA yang punya anak
    lewat $navigationParentItem (lihat PppoeProfileResource dkk, yang
    menunjuk navigationParentItem = 'Profile'). Filament TIDAK punya
    tombol buka/tutup bawaan untuk pola ini sama sekali: anak-anaknya
    (PPPoE / Static IP / Member) HANYA dirender selama halaman "Profile"
    atau salah satu anaknya sedang aktif (dicek di server lewat Blade,
    bukan Alpine) -- begitu Anda SUDAH di halaman itu, link "Profile"
    menunjuk ke halaman yang SAMA yang sedang dibuka, jadi diklik ulang
    pun tidak melakukan apa-apa (tidak ada navigasi = tidak ada perubahan
    = sub-menu kelihatan "tidak mau tutup").

    Script ini menambah toggle MANUAL khusus untuk kasus itu:
      - Klik PERTAMA (dari halaman lain) -> berperilaku normal, pindah ke
        halaman "Profile", sub-menu otomatis kebuka (karena aktif).
      - Klik BERIKUTNYA (selagi masih di halaman yang sama) -> TIDAK
        navigasi apa-apa (memang tidak ada tempat baru yang dituju), cuma
        toggle class CSS manual untuk sembunyikan/tampilkan sub-menu-nya.
      - Pindah ke menu lain lalu balik lagi -> status manual dibuang,
        supaya kebuka lagi dari awal (fresh) seperti semula, konsisten
        sama seperti pertama kali.

    Generik: berlaku untuk SEMUA item yang punya anak (bukan cuma
    "Profile"), jadi otomatis ikut kepakai kalau nanti ada
    navigationParentItem baru lagi.
--}}
<script>
    (function () {
        const COLLAPSED_CLASS = 'toya-parentitem-collapsed';

        function findParentItem(target) {
            const btn = target.closest('.fi-sidebar-item-btn');
            if (!btn) {
                return null;
            }

            const li = btn.closest('.fi-sidebar-item');
            if (!li) {
                return null;
            }

            // Harus punya <ul class="fi-sidebar-sub-group-items"> LANGSUNG
            // di bawahnya supaya ini benar item "induk", bukan item biasa
            // tanpa anak (yang harus tetap navigasi normal).
            const subUl = li.querySelector(':scope > .fi-sidebar-sub-group-items');
            if (!subUl) {
                return null;
            }

            return { btn, li };
        }

        document.addEventListener('click', function (event) {
            const found = findParentItem(event.target);
            if (!found) {
                return;
            }

            const { btn, li } = found;
            const href = btn.getAttribute('href');

            if (href) {
                try {
                    const targetPath = new URL(href, window.location.href).pathname;

                    if (targetPath !== window.location.pathname) {
                        // Menuju halaman LAIN -> biarkan navigasi jalan
                        // seperti biasa, buang status collapsed manual lama
                        // supaya nanti baliknya kebuka fresh lagi.
                        li.classList.remove(COLLAPSED_CLASS);

                        return;
                    }
                } catch (e) {
                    // href bukan URL yang valid (mis. "#") -> anggap toggle.
                }
            }

            // Sudah di halaman yang sama persis -> ini klik toggle manual.
            event.preventDefault();
            li.classList.toggle(COLLAPSED_CLASS);
        }, true);
    })();
</script>

<style>
    .toya-parentitem-collapsed > .fi-sidebar-sub-group-items {
        display: none !important;
    }
</style>
