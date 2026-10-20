#!/usr/bin/env python3
"""
muat-widget-langsung.py -- dasbor tampil sekaligus (tanpa jeda kosong).

Bawaan Filament: setiap widget dasbor "lazy" -- halaman tampil kosong dulu, lalu tiap widget
mengambil datanya sendiri-sendiri setelahnya. Di HP inilah yang membuat dasbor lama kosong
saat dibuka. Skrip ini menambahkan

    protected static bool $isLazy = false;

ke setiap kelas widget di app/Filament/Widgets yang belum mengaturnya, sehingga isi widget
langsung ikut terkirim bersama halaman.

Aman: hanya menyisipkan satu baris (tidak menimpa berkas), widget yang sudah mengatur $isLazy
dilewati, tiap berkas dicadangkan dan dicek `php -l`; bila ada yang rusak semuanya dikembalikan.

Pemakaian (dari /var/www/toya-radius):
  python3 tools/muat-widget-langsung.py --dry-run
  python3 tools/muat-widget-langsung.py
  python3 tools/muat-widget-langsung.py --kembalikan
"""
import argparse, glob, os, re, shutil, subprocess, sys, time

TANDA = 'MUAT-WIDGET-LANGSUNG'
BARIS = f'    protected static bool $isLazy = false; // {TANDA}: tampil bersama halaman\n'


def lint(path):
    for php in ('php8.4', 'php'):
        if shutil.which(php):
            r = subprocess.run([php, '-l', path], capture_output=True, text=True)
            return r.returncode == 0, (r.stdout + r.stderr).strip()
    return None, 'php tidak ditemukan'


def tambal(src):
    if '$isLazy' in src:
        return None, 'sudah mengatur $isLazy'
    m = re.search(r'^(?!\s*abstract\b)\s*(final\s+)?class\s+\w+\s+extends\s+[\\\w]*Widget\b[^{]*\{[ \t]*\n', src, re.M)
    if not m:
        return None, 'bukan kelas widget'
    return src[:m.end()] + BARIS + src[m.end():], 'disisipkan'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()
    folder = os.path.join(a.dir, 'app/Filament/Widgets')
    berkas = sorted(glob.glob(os.path.join(folder, '**', '*.php'), recursive=True))

    if a.kembalikan:
        n = 0
        for p in berkas:
            cad = sorted(glob.glob(p + '.bak-lazy-*'))
            if cad:
                shutil.copy2(cad[-1], p)
                n += 1
                print(f'DIKEMBALIKAN: {os.path.relpath(p, a.dir)}')
        print(f'{n} berkas dikembalikan.' if n else 'Tidak ada cadangan.')
        return 0

    if not berkas:
        print(f'GAGAL: tidak ada berkas widget di {folder}', file=sys.stderr)
        return 1

    rencana = []
    for p in berkas:
        src = open(p, encoding='utf-8').read()
        baru, ket = tambal(src)
        rel = os.path.relpath(p, a.dir)
        print(f'{rel}: {"AKAN DIUBAH" if (baru and a.dry_run) else ("diubah" if baru else "dilewati")} ({ket})')
        if baru:
            rencana.append((p, baru))

    if a.dry_run or not rencana:
        print('\nSimulasi selesai, TIDAK ada yang diubah.' if a.dry_run else '\nTidak ada yang perlu diubah.')
        return 0

    stempel = time.strftime('%Y%m%d%H%M%S')
    for p, baru in rencana:
        shutil.copy2(p, f'{p}.bak-lazy-{stempel}')
        open(p, 'w', encoding='utf-8').write(baru)
        ok, pesan = lint(p)
        if ok is False:
            for p2, _ in rencana:
                c = f'{p2}.bak-lazy-{stempel}'
                if os.path.exists(c):
                    shutil.copy2(c, p2)
            print(f'GAGAL: sintaks {p} rusak ({pesan}). Semua dikembalikan.', file=sys.stderr)
            return 1

    print(f'\nSelesai: {len(rencana)} widget dimuat langsung.')
    print('Langkah berikutnya: php8.4 artisan optimize:clear && sudo systemctl restart php8.4-fpm')
    return 0


if __name__ == '__main__':
    sys.exit(main())
