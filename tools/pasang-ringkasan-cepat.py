#!/usr/bin/env python3
"""
pasang-ringkasan-cepat.py -- angka kartu statistik dihitung lebih hemat.

Widget kartu statistik (User Hotspot, User PPPoE, Invoice, Kasir, dan widget
lain yang memakai view 'filament.widgets.tya-stat-cards') sebelumnya menghitung
ulang SEMUA angkanya ke database setiap kali halaman dibuka. Skrip ini:

  1) membungkus getCards() dengan cache 2 menit, terpisah per tenant (nama
     database) dan per user (supaya outlet tetap melihat angkanya sendiri);
     isi perhitungan aslinya dipindah utuh ke hitungKartu() -- tidak diubah.
  3) kartu dimuat LANGSUNG bersama halaman ($isLazy = false), bukan lewat
     permintaan kedua setelah halaman tampil -- tidak ada lagi kotak abu
     berkedip (memudar-menebal) sebelum angka muncul. Aman karena angkanya
     sudah disimpan sementara (poin 1).
  2) khusus User Hotspot: whereDate('kolom', today()) diganti whereBetween
     (awal & akhir hari ini) supaya database bisa memakai indeks dan tidak
     membaca seluruh tabel voucher.

Aman diulang (berkas yang sudah dipasang dilewati). Cadangan .bak-ringkasan-*.
Setiap berkas dicek `php -l`; kalau gagal, berkas itu dikembalikan.

Pemakaian (dari /var/www/toya-radius):
  python3 tools/pasang-ringkasan-cepat.py --dry-run
  python3 tools/pasang-ringkasan-cepat.py
  python3 tools/pasang-ringkasan-cepat.py --kembalikan
"""
import argparse, glob, os, re, shutil, subprocess, sys, time

TANDA = 'RINGKASAN-CEPAT'
DETIK = 120
VIEW_DIKENAL = ("'filament.widgets.tya-stat-cards'",
                "'filament.resources.hotspot-user-resource.widgets.hotspot-user-overview'")

POLA_GETCARDS = re.compile(r'^([ \t]*)public function getCards\(\): array[ \t]*\n\1\{[ \t]*\n', re.M)


def php():
    for p in ('php8.4', 'php'):
        if shutil.which(p):
            return p
    return None


def cek_php(path):
    p = php()
    if not p:
        return True, '(php tidak ditemukan, cek dilewati)'
    r = subprocess.run([p, '-l', path], capture_output=True, text=True)
    return r.returncode == 0, (r.stdout + r.stderr).strip()


def bungkus(isi):
    m = POLA_GETCARDS.search(isi)
    if not m:
        return None
    ind = m.group(1)
    baru = (
        f"{ind}// {TANDA}: angka kartu disimpan {DETIK // 60} menit per tenant & per user, supaya\n"
        f"{ind}// halaman tidak menghitung ulang ke database setiap kali dibuka.\n"
        f"{ind}public function getCards(): array\n"
        f"{ind}{{\n"
        f"{ind}    $kunci = 'tya-ringkasan:'.static::class.':'\n"
        f"{ind}        .\\Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName().':'\n"
        f"{ind}        .(auth()->id() ?? 0);\n"
        f"\n"
        f"{ind}    return \\Illuminate\\Support\\Facades\\Cache::remember($kunci, {DETIK}, fn () => $this->hitungKartu());\n"
        f"{ind}}}\n"
        f"\n"
        f"{ind}protected function hitungKartu(): array\n"
        f"{ind}{{\n"
    )
    return isi[:m.start()] + baru + isi[m.end():]


def perbaiki_whereDate(isi):
    # whereDate('sold_at', today()) -> whereBetween('sold_at', [today(), today()->endOfDay()])
    return re.sub(
        r"whereDate\(\s*'([a-z_]+)'\s*,\s*today\(\)\s*\)",
        r"whereBetween('\1', [today(), today()->endOfDay()])",
        isi,
    )


TANDA_LANGSUNG = 'RINGKASAN-LANGSUNG'
POLA_KELAS = re.compile(r'^(class \w+ extends \w*Widget\b[^\n{]*\n?\{[ \t]*\n)', re.M)


def langsung(isi):
    if TANDA_LANGSUNG in isi or re.search(r'\$isLazy\s*=', isi):
        return isi
    m = POLA_KELAS.search(isi)
    if not m:
        return isi
    sisip = (f"    // {TANDA_LANGSUNG}: dimuat bersama halaman (tanpa kotak loading berkedip)\n"
             "    protected static bool $isLazy = false;\n\n")
    return isi[:m.end()] + sisip + isi[m.end():]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()

    akar = os.path.join(a.dir, 'app', 'Filament')
    if not os.path.isdir(akar):
        print(f'GAGAL: {akar} tidak ditemukan.', file=sys.stderr)
        return 1

    if a.kembalikan:
        n = 0
        for f in glob.glob(os.path.join(akar, '**', '*.php'), recursive=True):
            cad = sorted(glob.glob(f + '.bak-ringkasan-*'))
            if cad:
                shutil.copy2(cad[-1], f)
                print(f'DIKEMBALIKAN: {os.path.relpath(f, a.dir)}')
                n += 1
        print('Tidak ada cadangan.' if not n else 'Lalu: php8.4 artisan view:clear && php8.4 artisan filament:optimize && sudo systemctl reload php8.4-fpm')
        return 0

    cap = time.strftime('%Y%m%d%H%M%S')
    ubah = gagal = 0
    for f in sorted(glob.glob(os.path.join(akar, '**', '*.php'), recursive=True)):
        isi = open(f, encoding='utf-8').read()
        if 'function getCards(): array' not in isi:
            continue
        if not any(v in isi for v in VIEW_DIKENAL):
            continue
        rel = os.path.relpath(f, a.dir)
        if TANDA in isi:
            baru = isi
        else:
            baru = bungkus(isi)
            if baru is None:
                print(f'{rel}: DILEWATI (bentuk getCards() tidak dikenali) -- kirim berkas ini ke saya.')
                continue
            if f.endswith('HotspotUserOverview.php'):
                baru = perbaiki_whereDate(baru)
        baru = langsung(baru)
        if baru == isi:
            print(f'{rel}: sudah terpasang, dilewati.')
            continue
        if a.dry_run:
            print(f'{rel}: AKAN DIUBAH')
            continue
        cad = f'{f}.bak-ringkasan-{cap}'
        shutil.copy2(f, cad)
        open(f, 'w', encoding='utf-8').write(baru)
        ok, pesan = cek_php(f)
        if not ok:
            shutil.copy2(cad, f)
            print(f'{rel}: GAGAL cek php, dikembalikan.\n  {pesan}', file=sys.stderr)
            gagal += 1
            continue
        print(f'{rel}: diubah (cadangan {os.path.basename(cad)})')
        ubah += 1

    if a.dry_run:
        print('Simulasi selesai, TIDAK ada yang diubah.')
    elif ubah:
        print('Langkah berikutnya:\n  php8.4 artisan view:clear && php8.4 artisan filament:optimize\n'
              '  sudo systemctl reload php8.4-fpm')
    return 1 if gagal else 0


if __name__ == '__main__':
    sys.exit(main())
