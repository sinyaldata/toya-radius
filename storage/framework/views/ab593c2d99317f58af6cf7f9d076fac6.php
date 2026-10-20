
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
<?php /**PATH /var/www/toya-radius/resources/views/filament/sidebar-parentitem-toggle.blade.php ENDPATH**/ ?>