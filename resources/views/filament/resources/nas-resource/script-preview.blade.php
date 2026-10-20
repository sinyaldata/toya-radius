{{--
    Baris textarea disesuaikan dengan JUMLAH BARIS SCRIPT-nya SENDIRI
    (permintaan user: hapus ruang kosong yang tidak perlu) -- dulu selalu
    rows="10" tetap, padahal script L2TP/PPTP cuma ~9 baris dan WireGuard
    cuma ~5, jadi selalu menyisakan baris kosong di bawahnya.

    PENTING: dihitung bukan cuma dari jumlah "\n" -- baris yang PANJANG
    (mis. baris /interface ...connect-to=...user=...password=...) ikut
    melipat (soft-wrap) jadi lebih dari satu baris tampilan di dalam
    textarea yang lebarnya terbatas. Kalau cuma dihitung dari "\n", baris
    seperti itu bisa terpotong kelihatannya (walau isinya tetap utuh saat
    di-copy -- "Salin Script" selalu menyalin SELURUH isi, bukan cuma yang
    kelihatan). ~85 karakter/baris adalah perkiraan lebar textarea di
    dalam popup (font monospace 11.5px); +1 baris ekstra sebagai jaga-jaga.
    Dibatasi 5-16 baris supaya tidak pernah kelewat pendek atau tinggi.
--}}
@php
    $perkiraanKarakterPerBaris = 85;
    $barisTampilan = 0;

    foreach (explode("\n", trim($script)) as $baris) {
        $barisTampilan += max(1, (int) ceil(mb_strlen($baris) / $perkiraanKarakterPerBaris));
    }

    $baris = max(5, min(16, $barisTampilan + 1));
@endphp
<div>
    <textarea readonly rows="{{ $baris }}" onclick="this.select()"
        style="width:100%; font-family: ui-monospace, monospace; font-size: 11.5px; padding: 8px 10px; border-radius: 8px; border: 1px solid #d1d5db; background: #0f172a; color: #e2e8f0; box-sizing: border-box; line-height: 1.45;"
    >{{ $script }}</textarea>

    <button type="button"
        x-data
        @click="navigator.clipboard.writeText($el.previousElementSibling.value); $el.innerText='Tersalin ✓'; setTimeout(() => $el.innerText='Salin Script', 1500);"
        style="margin-top: 6px; padding: 5px 12px; border-radius: 8px; background: #f97316; color: white; font-weight: 600; font-size: 12px; border: none; cursor: pointer;"
    >Salin Script</button>
</div>
