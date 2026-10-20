#!/usr/bin/env python3
"""
pasang-vpn-owner-mobile.py -- tampilan VPN Owner versi HP sesuai mockup:
header gelap (judul panel + chip online + hamburger), kolom cari, kartu
statistik 2x2, dan kartu "User Online Sekarang" per tenant + "Jumlah semua".
Tampilan desktop tidak berubah. Logic PHP/controller tidak disentuh.

  python3 tools/pasang-vpn-owner-mobile.py --dry-run
  python3 tools/pasang-vpn-owner-mobile.py
  python3 tools/pasang-vpn-owner-mobile.py --kembalikan
"""
import argparse, glob, hashlib, os, shutil, sys, time

SINI = os.path.dirname(os.path.abspath(__file__))
SUMBER = os.path.join(SINI, 'vpn-owner-mobile')
FILES = [
    'resources/views/vpn-owner/online.blade.php',
    'resources/views/vpn-owner/partials/styles.blade.php',
    'resources/views/vpn-owner/partials/topbar.blade.php',
    'resources/views/vpn-owner/partials/sidebar.blade.php',
    'resources/views/vpn-owner/partials/scripts.blade.php',
]
# md5 file server yang dikirim (16-10-2026). Kalau beda = file sudah diubah sejak itu.
ASLI = {'resources/views/vpn-owner/online.blade.php': '01720cb92db237910c586098ee5b6dbf', 'resources/views/vpn-owner/partials/styles.blade.php': 'bd499a8f54552cc1f1d6aa3a483e3af9', 'resources/views/vpn-owner/partials/topbar.blade.php': '5d087b53d42363d717f61c96d40fb33b', 'resources/views/vpn-owner/partials/sidebar.blade.php': '35db334c3f7767eeaab2b089421aa561', 'resources/views/vpn-owner/partials/scripts.blade.php': '9382063610fd5095b793ae51020fc5bf'}
SFX = '.bak-vpnmobile-'


def md5(p):
    return hashlib.md5(open(p, 'rb').read()).hexdigest()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    ap.add_argument('--paksa', action='store_true', help='timpa walau file server sudah berubah')
    a = ap.parse_args()

    if a.kembalikan:
        for rel in FILES:
            p = os.path.join(a.dir, rel)
            cad = sorted(glob.glob(p + SFX + '*'))
            if cad:
                shutil.copy2(cad[-1], p)
                print(f'DIKEMBALIKAN: {rel}')
            else:
                print(f'(tidak ada cadangan) {rel}')
        return 0

    beda = []
    for rel in FILES:
        p = os.path.join(a.dir, rel)
        if not os.path.exists(p):
            print(f'GAGAL: {rel} tidak ada di server.', file=sys.stderr)
            return 1
        sekarang = md5(p)
        if sekarang == md5(os.path.join(SUMBER, rel)):
            print(f'sudah terpasang : {rel}')
        elif sekarang != ASLI.get(rel):
            beda.append(rel)
            print(f'BERUBAH         : {rel}')
        else:
            print(f'{"AKAN DIUBAH" if a.dry_run else "diubah"}     : {rel}')

    if beda and not a.paksa:
        print('\nFile di atas sudah berubah sejak dikirim -- tidak ada yang diubah.\n'
              'Kirim ulang file itu, atau pakai --paksa untuk menimpa (cadangan tetap dibuat).', file=sys.stderr)
        return 1
    if a.dry_run:
        return 0

    stamp = time.strftime('%Y%m%d%H%M%S')
    for rel in FILES:
        p = os.path.join(a.dir, rel)
        src = os.path.join(SUMBER, rel)
        if md5(p) == md5(src):
            continue
        shutil.copy2(p, p + SFX + stamp)
        shutil.copyfile(src, p)
    print('\nSelesai. Lalu: php8.4 artisan view:clear')
    return 0


if __name__ == '__main__':
    sys.exit(main())
