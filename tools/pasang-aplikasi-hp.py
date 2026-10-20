#!/usr/bin/env python3
"""
pasang-aplikasi-hp.py -- bagian SERVER untuk aplikasi Android "Toya Radius":
  - API cek kode ISP  : GET /api-app/isp/{kode}
  - API cek versi APK : GET /api-app/versi
  - halaman unduh APK : GET /aplikasi   (file APK di public/unduh/)

File yang ditambah:
  app/Http/Controllers/AplikasiHpController.php
  routes/aplikasi-hp.php   (+ 1 baris "require" di akhir routes/web.php)
  resources/views/aplikasi-hp/unduh.blade.php
  public/unduh/            (folder APK, diisi oleh tools/bangun-apk.sh)

  python3 tools/pasang-aplikasi-hp.py --dry-run
  python3 tools/pasang-aplikasi-hp.py
  python3 tools/pasang-aplikasi-hp.py --kembalikan
"""
import argparse, os, shutil, subprocess, sys

SINI = os.path.dirname(os.path.abspath(__file__))
SUMBER = os.path.join(SINI, 'aplikasi-hp', 'server')
FILES = [
    'app/Http/Controllers/AplikasiHpController.php',
    'routes/aplikasi-hp.php',
    'resources/views/aplikasi-hp/unduh.blade.php',
]
TANDA = '// APLIKASI-HP (tools/pasang-aplikasi-hp.py)'
BARIS = "\n" + TANDA + "\nrequire __DIR__.'/aplikasi-hp.php';\n"


def lint(p):
    for php in ('php8.4', 'php'):
        if shutil.which(php):
            r = subprocess.run([php, '-l', p], capture_output=True, text=True)
            return r.returncode == 0, (r.stdout + r.stderr).strip()
    return True, ''


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()
    web = os.path.join(a.dir, 'routes', 'web.php')

    if a.kembalikan:
        s = open(web, encoding='utf-8').read()
        if TANDA in s:
            open(web, 'w', encoding='utf-8').write(s.replace(BARIS, '').replace(TANDA + "\nrequire __DIR__.'/aplikasi-hp.php';\n", ''))
            print('routes/web.php: baris APLIKASI-HP dihapus.')
        for rel in FILES:
            p = os.path.join(a.dir, rel)
            if os.path.isfile(p):
                os.remove(p)
                print(f'DIHAPUS: {rel}')
        print('Lalu: php8.4 artisan route:clear && php8.4 artisan view:clear')
        return 0

    if not os.path.isfile(os.path.join(a.dir, 'app', 'Models', 'Central', 'Tenant.php')):
        print('GAGAL: app/Models/Central/Tenant.php tidak ditemukan -- folder aplikasi benar?', file=sys.stderr)
        return 1

    for rel in FILES:
        src = os.path.join(SUMBER, rel)
        dst = os.path.join(a.dir, rel)
        sama = os.path.isfile(dst) and open(dst, 'rb').read() == open(src, 'rb').read()
        print(f'{rel}: {"sudah" if sama else ("AKAN DITULIS" if a.dry_run else "ditulis")}')
        if sama or a.dry_run:
            continue
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        if os.path.isfile(dst):
            shutil.copy2(dst, dst + '.bak-aplikasi-hp')
        shutil.copyfile(src, dst)
        if dst.endswith('.php') and not dst.endswith('.blade.php'):
            ok, pesan = lint(dst)
            if not ok:
                print(f'GAGAL sintaks {rel}: {pesan}', file=sys.stderr)
                return 1

    s = open(web, encoding='utf-8').read()
    if TANDA in s:
        print('routes/web.php: sudah memuat routes/aplikasi-hp.php')
    else:
        print(f'routes/web.php: {"AKAN DITAMBAH" if a.dry_run else "ditambah"} 1 baris require')
        if not a.dry_run:
            shutil.copy2(web, web + '.bak-aplikasi-hp')
            open(web, 'w', encoding='utf-8').write(s.rstrip('\n') + '\n' + BARIS)
            ok, pesan = lint(web)
            if not ok:
                shutil.copy2(web + '.bak-aplikasi-hp', web)
                print(f'GAGAL sintaks routes/web.php ({pesan}) -- dikembalikan.', file=sys.stderr)
                return 1

    if not a.dry_run:
        os.makedirs(os.path.join(a.dir, 'public', 'unduh'), exist_ok=True)
        print('\nSelesai. Lalu:')
        print('  php8.4 artisan route:clear && php8.4 artisan view:clear')
        print('  cek: https://<domain-utama>/api-app/isp/<kode-tenant>')
        print('  bangun APK: bash tools/bangun-apk.sh')
    return 0


if __name__ == '__main__':
    sys.exit(main())
