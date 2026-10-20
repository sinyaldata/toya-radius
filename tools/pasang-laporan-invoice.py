#!/usr/bin/env python3
"""
pasang-laporan-invoice.py -- desain ulang halaman Laporan > Invoice (HP & PC):
  - baris filter ringkas: [ Tahun ][ tombol corong ] (sama seperti tombol
    filter di halaman lain), tanpa tombol "Terapkan Filter" besar
  - 2 kartu ringkasan (Pelanggan Bayar, Total Pendapatan) -- di HP otomatis
    2 kolom seperti kartu halaman lain (blok KARTU-SLIDE-V2)
  - tabel rekap 12 bulan dirapikan (kolom nama tetap terlihat saat digeser)

Yang diubah HANYA tampilan:
  resources/views/filament/pages/invoice-report-page.blade.php  (diganti)
Kode PHP (InvoiceReportPage.php) tidak disentuh.

  python3 tools/pasang-laporan-invoice.py --dry-run
  python3 tools/pasang-laporan-invoice.py
  python3 tools/pasang-laporan-invoice.py --kembalikan
"""
import argparse, glob, hashlib, os, shutil, sys, time

SINI = os.path.dirname(os.path.abspath(__file__))
VIEW = 'resources/views/filament/pages/invoice-report-page.blade.php'
PAGE = 'app/Filament/Pages/InvoiceReportPage.php'
VIEW_ASLI_MD5 = ['b9b71a960ae2dea41246154c0c4542b2']
SFX = '.bak-lapinv-'
WAJIB = ['function getMatrix', 'function getMonthlyTotals', 'function getGrandTotal', 'function bulanLabels', 'function terapkanFilter']


def md5(p):
    return hashlib.md5(open(p, 'rb').read()).hexdigest()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    ap.add_argument('--paksa', action='store_true', help='timpa view walau sudah berubah dari aslinya')
    a = ap.parse_args()
    view = os.path.join(a.dir, VIEW)
    page = os.path.join(a.dir, PAGE)

    if a.kembalikan:
        cad = sorted(glob.glob(view + SFX + '*'))
        if cad:
            shutil.copy2(cad[-1], view)
            print(f'DIKEMBALIKAN: {VIEW}')
        else:
            print(f'(tidak ada cadangan) {VIEW}')
        return 0

    view_baru = os.path.join(SINI, 'laporan-invoice', VIEW)
    if not os.path.isfile(view) or not os.path.isfile(page):
        print('GAGAL: halaman Laporan Invoice tidak ditemukan di folder aplikasi.', file=sys.stderr)
        return 1

    if md5(view) == md5(view_baru):
        print('Sudah terpasang -- tidak ada yang diubah.')
        return 0

    if md5(view) not in VIEW_ASLI_MD5 and not a.paksa:
        print(f'GAGAL: {VIEW} sudah berubah dari versi aslinya. Kirim filenya, atau pakai --paksa.', file=sys.stderr)
        return 1

    src = open(page, encoding='utf-8').read()
    hilang = [w for w in WAJIB if w not in src]
    if hilang:
        print('GAGAL: fungsi yang dibutuhkan tidak ada di ' + PAGE + ': ' + ', '.join(hilang) + '. Tidak ada yang diubah.', file=sys.stderr)
        return 1

    print(f'{VIEW}: {"AKAN DIGANTI" if a.dry_run else "diganti"}')
    if a.dry_run:
        return 0

    stamp = time.strftime('%Y%m%d%H%M%S')
    shutil.copy2(view, view + SFX + stamp)
    shutil.copyfile(view_baru, view)
    print(f'Cadangan: {VIEW}{SFX}{stamp}')
    print('\nSelesai. Lalu: php8.4 artisan view:clear')
    return 0


if __name__ == '__main__':
    sys.exit(main())
