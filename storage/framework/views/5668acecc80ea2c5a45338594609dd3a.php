
<script>
    (function () {
        // Timer penutup DIPISAH per-dropdown. Kalau memakai satu timer global,
        // begitu kursor pindah ke ikon lain, timer penutup dropdown lama ikut
        // dibatalkan sehingga menu sebelumnya tetap terbuka.
        const closeTimers = new WeakMap();
        let activeDropdown = null;

        function sidebarIsCollapsed() {
            const sidebar = document.querySelector('.fi-sidebar');

            // Sidebar TERBUKA penuh ditandai class "fi-sidebar-open".
            // Tidak ada class itu = mode ikon (rail sempit).
            return sidebar && !sidebar.classList.contains('fi-sidebar-open');
        }

        // Tombol trigger grup (ikon di rail) punya tooltip bawaan Filament
        // sendiri (Tippy.js, trigger mouseenter) yang isinya nama grup — SAMA
        // dengan judul panel dropdown kita, jadi dobel. Coba SEMBUNYIKAN
        // tooltip-nya SETELAH tampil (reaktif per-hover) ternyata tidak
        // cukup cepat: listener Tippy sendiri (nempel langsung di tombolnya,
        // fase "target") selalu duluan jalan sebelum listener kita di
        // "document" (fase "bubble") sempat bereaksi — tooltip sempat
        // kelihatan beberapa detik sebelum akhirnya disembunyikan.
        //
        // Solusinya: MATIKAN total tooltip itu dari awal (bukan
        // sembunyikan belakangan), langsung begitu instance Tippy-nya
        // terpasang. Diperiksa berkala beberapa detik pertama saja
        // (Alpine/Tippy kadang telat inisialisasi), setelah itu berhenti.
        function disableGroupTooltips() {
            document.querySelectorAll('.fi-sidebar-group-dropdown-trigger-btn').forEach((btn) => {
                if (btn._tippy && !btn._tippy.__toyaDisabled) {
                    btn._tippy.disable();
                    btn._tippy.__toyaDisabled = true;
                }
            });
        }

        disableGroupTooltips();
        const disableInterval = setInterval(disableGroupTooltips, 300);
        setTimeout(() => clearInterval(disableInterval), 5000);

        // PERBAIKAN (dipastikan lewat pemeriksaan langsung di halaman
        // produksi): pemeriksaan berkala di atas BERHENTI setelah 5 detik,
        // padahal tooltipnya bisa HIDUP LAGI setelah itu.
        //
        // Penyebabnya ada di markup bawaan Filament: tombol grup memakai
        // x-effect yang berbunyi kira-kira
        //     tooltip = $store.sidebar.isOpen ? false : { content: '<nama grup>', ... }
        // Artinya setiap kali sidebar dibuka/diciutkan, Alpine MENILAI
        // ULANG dan memasang tooltip baru. Instance barunya tidak punya
        // penanda __toyaDisabled, dan pemeriksaan berkalanya sudah mati,
        // jadi tooltip muncul lagi — tampil di sebelah KANAN ikon dengan
        // z-index 9999, persis menimpa panel flyout (z-index 60). Itulah
        // yang terlihat sebagai "menu bertumpuk/janggal saat kursor
        // didekatkan", sementara kalau DIKLIK tampak normal (pada klik,
        // tooltip keburu hilang).
        //
        // Penjaga di bawah memakai fase CAPTURE. Ini penting dan bukan
        // pilihan sembarangan: listener Tippy menempel di tombolnya
        // sendiri (fase target/bubble), sedangkan listener fase capture di
        // document dijalankan LEBIH DULU. Jadi tooltipnya sudah dimatikan
        // sebelum Tippy sempat menampilkannya — tidak ada lagi kedipan
        // "muncul sebentar lalu hilang" seperti percobaan sebelumnya yang
        // memakai fase bubble.
        function matikanTooltipTombol(event) {
            const target = event.target;

            if (! target || ! target.closest) {
                return;
            }

            const btn = target.closest('.fi-sidebar-group-dropdown-trigger-btn');

            if (! btn || ! btn._tippy) {
                return;
            }

            btn._tippy.hide();
            btn._tippy.disable();
            btn._tippy.__toyaDisabled = true;
        }

        document.addEventListener('pointerover', matikanTooltipTombol, true);
        document.addEventListener('mouseover', matikanTooltipTombol, true);

        // Setelah pindah halaman (Livewire SPA navigation) seluruh rail
        // dirender ulang beserta tooltip barunya, jadi disapu sekali lagi.
        document.addEventListener('livewire:navigated', disableGroupTooltips);

        function dropdownDataFor(el) {
            if (!window.Alpine || typeof window.Alpine.$data !== 'function') {
                return null;
            }

            try {
                return window.Alpine.$data(el);
            } catch (e) {
                return null;
            }
        }

        function closeDropdown(dropdown, event) {
            if (!dropdown) {
                return;
            }

            const data = dropdownDataFor(dropdown);
            if (data && typeof data.close === 'function') {
                data.close(event);
            }

            if (activeDropdown === dropdown) {
                activeDropdown = null;
            }
        }

        function scheduleClose(dropdown, event) {
            if (!dropdown) {
                return;
            }

            clearTimeout(closeTimers.get(dropdown));
            closeTimers.set(dropdown, setTimeout(function () {
                closeDropdown(dropdown, event);
            }, 200));
        }

        function closeAllExcept(current, event) {
            document.querySelectorAll('.fi-sidebar .fi-dropdown').forEach(function (other) {
                if (other === current) {
                    return;
                }

                clearTimeout(closeTimers.get(other));
                closeDropdown(other, event);
            });
        }

        document.addEventListener('mouseover', function (event) {
            if (!sidebarIsCollapsed()) {
                return;
            }

            const target = event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }

            // (a) Kursor berada di atas panel sub-menu yang sudah terbuka
            //     (panel ini ada di <body>, bukan di dalam sidebar).
            if (target.closest('.fi-dropdown-panel')) {
                if (activeDropdown) {
                    clearTimeout(closeTimers.get(activeDropdown));
                }

                return;
            }

            // (b) Kursor di atas ikon menu di rail.
            const dropdown = target.closest('.fi-sidebar .fi-dropdown');
            if (!dropdown) {
                return;
            }

            clearTimeout(closeTimers.get(dropdown));

            // Menu lain yang masih terbuka langsung ditutup, supaya tidak
            // menumpuk saat kursor berpindah dari satu ikon ke ikon lain.
            closeAllExcept(dropdown, event);

            const data = dropdownDataFor(dropdown);
            if (data && typeof data.open === 'function') {
                data.open(event);
                activeDropdown = dropdown;
            }
        });

        document.addEventListener('mouseout', function (event) {
            if (!sidebarIsCollapsed()) {
                return;
            }

            const target = event.target;
            const related = event.relatedTarget;

            if (!target || typeof target.closest !== 'function') {
                return;
            }

            const fromPanel = target.closest('.fi-dropdown-panel');
            const fromSidebar = target.closest('.fi-sidebar');

            if (!fromPanel && !fromSidebar) {
                return;
            }

            // Masih berada di dalam panel atau kembali ke sidebar -> biarkan.
            if (related && typeof related.closest === 'function') {
                if (related.closest('.fi-dropdown-panel') || related.closest('.fi-sidebar')) {
                    return;
                }
            }

            // Kursor benar-benar meninggalkan area menu -> jadwalkan tutup.
            scheduleClose(activeDropdown, event);
        });
    })();
</script>
<?php /**PATH /var/www/toya-radius/resources/views/filament/sidebar-flyout-hover.blade.php ENDPATH**/ ?>