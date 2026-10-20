#!/usr/bin/env python3
"""
pasang-halaman-hp.py -- halaman tabel (terutama User Hotspot / Voucher) ringan di HP.

  1) app/Support/HalamanHp.php: di HP pilihan "baris per halaman" maksimal 100.
     Pilihan 500/1000 yang tersimpan di sesi HP otomatis kembali ke 20.
     Di komputer pilihan tetap seperti semula.
  2) semua ->paginationPageOptions([...]) yang punya pilihan >= 200 dibungkus
     \\App\\Support\\HalamanHp::opsi([...]).
  4) halaman User Hotspot SELALU dibuka 20 baris per halaman (di HP maupun
     komputer). Pilihan 100/1000 tetap bisa dipakai, tapi tidak diingat --
     dibuka lagi kembali 20.
  3) User Hotspot: relasi profile.router & generatedBy ikut dimuat sekaligus
     (sebelumnya 2-3 query tambahan PER BARIS).

Cadangan .bak-halhp-*, cek php -l, berkas yang gagal dikembalikan.
  python3 tools/pasang-halaman-hp.py [--dry-run] [--kembalikan]
"""
import argparse, glob, os, re, shutil, subprocess, sys, time

TANDA = 'HalamanHp::opsi('
POLA = re.compile(r'->paginationPageOptions\(\s*(\[[^\]\n]*\])\s*\)')


def cek_php(path):
    for p in ('php8.4', 'php'):
        if shutil.which(p):
            r = subprocess.run([p, '-l', path], capture_output=True, text=True)
            return r.returncode == 0, (r.stdout + r.stderr).strip()
    return True, ''


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()
    sini = os.path.dirname(os.path.abspath(__file__))
    app = os.path.join(a.dir, 'app')
    if not os.path.isdir(app):
        print('GAGAL: folder app tidak ditemukan', file=sys.stderr)
        return 1

    if a.kembalikan:
        for f in glob.glob(os.path.join(app, '**', '*.php'), recursive=True):
            cad = sorted(glob.glob(f + '.bak-halhp-*'))
            if cad:
                shutil.copy2(cad[-1], f)
                print('DIKEMBALIKAN:', os.path.relpath(f, a.dir))
        print('Lalu: php8.4 artisan view:clear && php8.4 artisan filament:optimize && sudo systemctl reload php8.4-fpm')
        return 0

    cap = time.strftime('%Y%m%d%H%M%S')
    # 1) kelas pembantu
    tuj = os.path.join(app, 'Support', 'HalamanHp.php')
    src = os.path.join(sini, 'halaman-hp', 'app', 'Support', 'HalamanHp.php')
    if not os.path.isfile(src):
        print('GAGAL: halaman-hp/app/Support/HalamanHp.php harus satu folder dengan skrip ini', file=sys.stderr)
        return 1
    if not a.dry_run:
        os.makedirs(os.path.dirname(tuj), exist_ok=True)
        shutil.copy2(src, tuj)
    print('app/Support/HalamanHp.php:', 'AKAN DIPASANG' if a.dry_run else 'dipasang')

    gagal = 0
    for f in sorted(glob.glob(os.path.join(app, 'Filament', '**', '*.php'), recursive=True)):
        isi = open(f, encoding='utf-8').read()
        baru = isi

        def ganti(m):
            angka = [int(x) for x in re.findall(r'\d+', m.group(1))]
            if not angka or max(angka) < 200:
                return m.group(0)
            return '->paginationPageOptions(\\App\\Support\\HalamanHp::opsi(' + m.group(1) + '))'

        if TANDA not in baru:
            baru = POLA.sub(ganti, baru)
        if f.endswith(os.path.join('HotspotUserResource', 'Pages', 'ListHotspotUsers.php')) and 'SELALU-20' not in baru:
            jangkar = 'protected static string $resource = HotspotUserResource::class;\n'
            if jangkar in baru:
                baru = baru.replace(jangkar, jangkar + '''
    // SELALU-20: setiap kali halaman Voucher dibuka mulai dari 20 baris per
    // halaman; pilihan 100/1000 sebelumnya tidak diingat (supaya tidak berat).
    public function getDefaultTableRecordsPerPageSelectOption(): int|string
    {
        session()->forget($this->getTablePerPageSessionKey());

        return 20;
    }
''', 1)
            else:
                print(os.path.relpath(f, a.dir) + ': DILEWATI (bentuk berkas tidak dikenali) -- kirim berkas ini ke saya.')
        if f.endswith('HotspotUserResource.php'):
            baru = baru.replace("->with(['outlet']);", "->with(['outlet', 'profile.router', 'generatedBy']);", 1)
        if baru == isi:
            continue
        rel = os.path.relpath(f, a.dir)
        if a.dry_run:
            print(rel + ': AKAN DIUBAH')
            continue
        cad = f + '.bak-halhp-' + cap
        shutil.copy2(f, cad)
        open(f, 'w', encoding='utf-8').write(baru)
        ok, pesan = cek_php(f)
        if not ok:
            shutil.copy2(cad, f)
            print(rel + ': GAGAL cek php, dikembalikan\n  ' + pesan, file=sys.stderr)
            gagal += 1
            continue
        print(rel + ': diubah')

    if not a.dry_run:
        print('Langkah berikutnya:\n  php8.4 artisan view:clear && php8.4 artisan filament:optimize\n  sudo systemctl reload php8.4-fpm')
    return 1 if gagal else 0


if __name__ == '__main__':
    sys.exit(main())
