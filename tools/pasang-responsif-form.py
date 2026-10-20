#!/usr/bin/env python3
"""
pasang-responsif-form.py -- form tidak terpotong di layar PC kecil (lebar < 1280px).

Menambahkan SATU blok <style> di AKHIR resources/views/filament/global-table-style.blade.php
(berkas CSS global yang sudah dipakai seluruh aplikasi). Berkas tidak ditimpa -- hanya ditambah di
ujung -- jadi perubahan lain di dalamnya aman. Layar >= 1280px tidak berubah.

Isi blok (tools/responsif-form-blok.html, harus satu folder dengan skrip ini):
  - keterangan di bawah input tidak lagi dipotong 2 baris (di bawah 1280px)
  - kepala wizard (NAS, Profile, dst) dirapatkan, keterangan langkah disembunyikan, bisa digeser
  - pratinjau skrip/kode turun baris, tidak melebar keluar kartu
  - kolom/field boleh mengecil (min-width:0), teks panjang boleh turun baris
  - modal tidak pernah lebih lebar dari layar

Pemakaian:
  python3 tools/pasang-responsif-form.py --dry-run
  python3 tools/pasang-responsif-form.py
  python3 tools/pasang-responsif-form.py --kembalikan
  Opsi: --dir /var/www/toya-radius
"""
import argparse, glob, os, shutil, sys, time

TANDA = 'RESPONSIF-FORM-LAYAR-KECIL'
REL = 'resources/views/filament/global-table-style.blade.php'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()
    path = os.path.join(a.dir, REL)

    if a.kembalikan:
        cad = sorted(glob.glob(path + '.bak-responsif-*'))
        if not cad:
            print('Tidak ada cadangan untuk dikembalikan.')
            return 0
        shutil.copy2(cad[-1], path)
        print(f'DIKEMBALIKAN: {REL}  <-  {os.path.basename(cad[-1])}')
        print('Lalu: php8.4 artisan view:clear')
        return 0

    blok_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'responsif-form-blok.html')
    if not os.path.isfile(path):
        print(f'GAGAL: {REL} tidak ditemukan di {a.dir}', file=sys.stderr)
        return 1
    if not os.path.isfile(blok_path):
        print('GAGAL: responsif-form-blok.html harus satu folder dengan skrip ini.', file=sys.stderr)
        return 1

    asli = open(path, encoding='utf-8').read()
    blok = open(blok_path, encoding='utf-8').read()
    if TANDA in asli:
        print('Sudah terpasang -- tidak ada yang diubah.')
        return 0
    if '{{' in blok or '@php' in blok or '@if' in blok:
        print('GAGAL: blok mengandung sintaks Blade, dibatalkan.', file=sys.stderr)
        return 1
    if '</style>' not in asli:
        print(f'GAGAL: {REL} tidak tampak seperti berkas CSS global yang saya kenal (tidak ada </style>).', file=sys.stderr)
        return 1

    baru = asli.rstrip('\n') + '\n' + blok.rstrip('\n') + '\n'
    print(f'{REL}: {"AKAN DITAMBAH" if a.dry_run else "ditambah"} {len(blok.splitlines())} baris di akhir berkas.')
    if a.dry_run:
        print('Simulasi selesai, TIDAK ada yang diubah.')
        return 0

    cad = f'{path}.bak-responsif-{time.strftime("%Y%m%d%H%M%S")}'
    shutil.copy2(path, cad)
    open(path, 'w', encoding='utf-8').write(baru)
    print(f'Cadangan: {os.path.basename(cad)}')
    print('Langkah berikutnya:\n  php8.4 artisan view:clear && php8.4 artisan optimize:clear\n  (tidak perlu restart php-fpm; di browser tekan Ctrl+F5)')
    print('Batalkan dengan: python3 tools/pasang-responsif-form.py --kembalikan')
    return 0


if __name__ == '__main__':
    sys.exit(main())
