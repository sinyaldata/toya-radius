
<script>
    (function () {
        // ===== Toggle tema terang/gelap, tersimpan di localStorage =====
        var html = document.documentElement;
        var themeBtn = document.getElementById('tya-theme-toggle');
        var themeIcon = document.getElementById('tya-theme-icon');

        function terapkanIkonTema() {
            var gelap = html.getAttribute('data-theme') === 'dark';
            if (themeIcon) themeIcon.textContent = gelap ? '🌙' : '☀️';
            document.querySelectorAll('[data-tya-theme-icon]').forEach(function (el) {
                el.textContent = gelap ? '🌙' : '☀️';
            });
        }
        terapkanIkonTema();

        function gantiTema() {
            var sekarangGelap = html.getAttribute('data-theme') === 'dark';
            var baru = sekarangGelap ? 'light' : 'dark';
            html.setAttribute('data-theme', baru);
            try { localStorage.setItem('tya-theme', baru); } catch (e) {}
            terapkanIkonTema();
        }

        if (themeBtn) themeBtn.addEventListener('click', gantiTema);
        // Tombol tema kedua (di laci menu versi HP).
        document.querySelectorAll('[data-tya-theme-toggle]').forEach(function (b) {
            b.addEventListener('click', gantiTema);
        });

        // ===== Dropdown menu akun: tutup kalau klik di luar kotaknya =====
        document.addEventListener('click', function (e) {
            var menu = document.getElementById('tya-user-menu');
            if (menu && menu.classList.contains('open') && !menu.contains(e.target)) {
                menu.classList.remove('open');
            }
        });

        // ===== Sidebar mobile: tutup drawer kalau klik area gelap di luar =====
        document.addEventListener('click', function (e) {
            var sidebar = document.getElementById('tya-sidebar');
            var hamburger = document.querySelector('.tya-hamburger');
            if (
                document.body.classList.contains('tya-sidebar-open') &&
                sidebar && !sidebar.contains(e.target) &&
                hamburger && !hamburger.contains(e.target)
            ) {
                document.body.classList.remove('tya-sidebar-open');
            }
        });

        // ===== Filter pencarian sederhana: sembunyikan baris tabel yang
        // tidak cocok dengan teks yang diketik. Jalan di tabel apa pun
        // yang ada di halaman yang sedang dibuka (tenant, akun VPN,
        // dst) -- murni tampilan, tidak mengubah data apapun. =====
        var searchInput = document.getElementById('tya-search-input');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var kata = searchInput.value.trim().toLowerCase();
                document.querySelectorAll('table.tya-table tbody tr, [data-tya-cari]').forEach(function (baris) {
                    if (!kata) {
                        baris.style.display = '';
                        return;
                    }
                    var cocok = baris.textContent.toLowerCase().indexOf(kata) !== -1;
                    baris.style.display = cocok ? '' : 'none';
                });
            });
        }
    })();
</script>
<?php /**PATH /var/www/toya-radius/resources/views/vpn-owner/partials/scripts.blade.php ENDPATH**/ ?>