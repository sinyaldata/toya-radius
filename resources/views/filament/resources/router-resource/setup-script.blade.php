<div class="space-y-4">
    <div class="text-sm text-gray-600 dark:text-gray-300">
        Buka <strong>Winbox &rarr; New Terminal</strong> di router MikroTik ini, lalu
        <strong>copy-paste</strong> seluruh script di bawah, lalu tekan Enter.
        Tidak perlu paham RouterOS — tinggal salin-tempel.
    </div>

    <textarea readonly rows="16" onclick="this.select()"
        style="width:100%; font-family: ui-monospace, monospace; font-size: 12px; padding: 12px; border-radius: 8px; border: 1px solid #d1d5db; background: #0f172a; color: #e2e8f0;"
    >{{ $script }}</textarea>

    <div class="flex items-center gap-2">
        <button type="button"
            onclick="navigator.clipboard.writeText(document.querySelector('textarea').value); this.innerText='Tersalin ✓';"
            style="padding: 8px 14px; border-radius: 8px; background: #f97316; color: white; font-weight: 600; font-size: 13px; border: none; cursor: pointer;"
        >Salin Script</button>
        <span class="text-xs text-gray-500">Klik kotak teks lalu Ctrl+A, Ctrl+C juga bisa.</span>
    </div>

    <div class="text-xs text-gray-500 border-t pt-3">
        Setelah script dijalankan di router, kembali ke halaman ini dan klik tombol
        <strong>"Sinkronisasi Profile"</strong> supaya semua paket/profile yang sudah
        dibuat di aplikasi otomatis dikirim ke router.
    </div>
</div>
