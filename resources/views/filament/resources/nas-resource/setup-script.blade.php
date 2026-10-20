<div class="space-y-4">
    <div class="text-sm text-gray-600 dark:text-gray-300">
        Buka <strong>Winbox &rarr; New Terminal</strong> di router MikroTik NAS ini, lalu
        <strong>copy-paste</strong> seluruh script di bawah, lalu tekan Enter.
        Tidak perlu paham RouterOS — tinggal salin-tempel. Username & password API
        sudah digenerate otomatis oleh aplikasi, tidak perlu diisi manual.
    </div>

    <textarea readonly rows="18" onclick="this.select()"
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
        Setelah script dijalankan di router, NAS ini otomatis bisa mengirim data
        login/logout Hotspot &amp; PPPoE ke aplikasi lewat RADIUS.
    </div>
</div>
