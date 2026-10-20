{{--
    Auto-reload halaman kalau sesi/CSRF token sudah expired (HTTP 419),
    dipicu oleh Livewire.hook('request', ...) — mencegat SEBELUM Livewire
    menampilkan dialog konfirmasi bawaannya ("This page has expired...").
    Operator tidak perlu klik OK manual, halaman langsung refresh sendiri.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (status === 419) {
                    // Cegah Livewire menampilkan confirm() bawaan, lalu reload otomatis.
                    preventDefault();
                    window.location.reload();
                }
            });
        });
    });
</script>
