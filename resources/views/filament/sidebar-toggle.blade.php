{{--
    Toggle sidebar sendiri (bukan fitur "sidebarCollapsibleOnDesktop" bawaan
    Filament — pernah dicoba, sempat bikin masalah render). Ini murni: satu
    class di <html>, disimpan di localStorage, dibaca CSS untuk sembunyikan
    sidebar + geser konten balik ke kiri.
--}}
<script>
    document.addEventListener('alpine:init', () => {
        window.Alpine.store('toyaSidebar', {
            collapsed: localStorage.getItem('toya-sidebar-collapsed') === '1',

            init() {
                this.apply();
            },

            toggle() {
                this.collapsed = !this.collapsed;
                localStorage.setItem('toya-sidebar-collapsed', this.collapsed ? '1' : '0');
                this.apply();
            },

            apply() {
                document.documentElement.classList.toggle('tya-sidebar-collapsed', this.collapsed);
            },
        });

        window.Alpine.store('toyaSidebar').apply();
    });
</script>
