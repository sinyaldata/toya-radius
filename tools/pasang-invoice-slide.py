#!/usr/bin/env python3
"""
pasang-invoice-slide.py -- Top 20 Invoice versi HP: kartu geser seperti referensi.

Menyisipkan blok tampilan HP ke resources/views/filament/widgets/invoice-list.blade.php
(sebelum kartu tabel yang sudah ada) dan menandai kartu tabel itu supaya hanya tampil di
layar >= 768px. Tidak menimpa berkas; dicadangkan dulu; aman diulang.

  python3 tools/pasang-invoice-slide.py --dry-run
  python3 tools/pasang-invoice-slide.py
  python3 tools/pasang-invoice-slide.py --kembalikan
"""
import argparse, glob, os, shutil, sys, time

REL = 'resources/views/filament/widgets/invoice-list.blade.php'
TANDA = 'INVOICE-SLIDE-MOBILE'
JANGKAR = '<div class="tya-card tya-kartu-daftar">'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()
    path = os.path.join(a.dir, REL)

    if a.kembalikan:
        cad = sorted(glob.glob(path + '.bak-invslide-*'))
        if not cad:
            print('Tidak ada cadangan.')
            return 0
        shutil.copy2(cad[-1], path)
        print(f'DIKEMBALIKAN: {REL}')
        return 0

    blok = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'invoice-slide-blok.blade.html'), encoding='utf-8').read()
    if not os.path.isfile(path):
        print(f'GAGAL: {REL} tidak ditemukan', file=sys.stderr)
        return 1
    src = open(path, encoding='utf-8').read()
    if TANDA in src:
        print('Sudah terpasang -- tidak ada yang diubah.')
        return 0
    n = src.count(JANGKAR)
    if n != 1:
        print(f'GAGAL: titik sisip ditemukan {n}x (harus 1). Berkas di server berbeda -- kirimkan ke saya.', file=sys.stderr)
        return 1
    if '$this->getInvoiceRows()' not in src or 'getInvoiceTotalPages' not in src:
        print('GAGAL: widget tidak memakai getInvoiceRows()/getInvoiceTotalPages() seperti yang saya kenal.', file=sys.stderr)
        return 1

    baru = src.replace(JANGKAR, blok + '    <div class="tya-card tya-kartu-daftar tya-inv-lebar">', 1)
    print(f'{REL}: {"AKAN DIUBAH" if a.dry_run else "diubah"}')
    if a.dry_run:
        print('Simulasi selesai, TIDAK ada yang diubah.')
        return 0
    shutil.copy2(path, f'{path}.bak-invslide-{time.strftime("%Y%m%d%H%M%S")}')
    open(path, 'w', encoding='utf-8').write(baru)
    print('Selesai. Lalu: php8.4 artisan view:clear')
    return 0


if __name__ == '__main__':
    sys.exit(main())
