
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
<?php /**PATH /var/www/toya-radius/resources/views/filament/livewire-419-handler.blade.php ENDPATH**/ ?>