#!/usr/bin/env python3
"""
ringankan-tampilan.py -- bikin aplikasi lebih ringan di HP.

Masalah: semua tambalan tampilan (sekitar 330 KB CSS + skrip) ditempel
LANGSUNG di setiap halaman (lewat render hook), jadi diunduh & diproses ulang
setiap kali pindah halaman -- browser tidak bisa menyimpannya (cache).

Yang dilakukan skrip ini, untuk setiap file tampilan global yang isinya murni
<style>/<script>:
  1. Isi aslinya disimpan sebagai SUMBER di
       resources/views/filament/tya-sumber/<nama>.html
     (sumber inilah yang nanti diubah oleh installer tambalan berikutnya).
  2. Dibangun file statis:
       public/tya/<nama>.css  (komentar & spasi berlebih dibuang)
       public/tya/<nama>.js   (tiap blok skrip diberi pengaman try/catch)
  3. File blade-nya diganti jadi 2 baris saja: <link> CSS + <script src>
     dengan nomor versi (?v=...), jadi browser menyimpannya dan hanya
     mengunduh ulang kalau isinya berubah.

Perilaku & urutan tetap sama (posisi di halaman sama, skrip tetap jalan
berurutan di akhir halaman).

  python3 tools/ringankan-tampilan.py --dry-run
  python3 tools/ringankan-tampilan.py               # pasang / bangun ulang
  python3 tools/ringankan-tampilan.py --kembalikan  # kembali ke versi inline
"""
import argparse, hashlib, os, re, shutil, sys, time

NAMA = ['global-table-style', 'global-ui-polish', 'global-form-style']
VIEW_DIR = 'resources/views/filament'
SUMBER_DIR = 'resources/views/filament/tya-sumber'
PUBLIK_DIR = 'public/tya'
TANDA = 'RINGANKAN-TAMPILAN'

# CSS dimuat di <head> (bukan di akhir body) supaya tampilan lama tidak sempat
# terlihat: file tya-kepala.blade.php di-@include dari view HEAD_END yang sudah
# ada (sidebar-collapsed-seed). Kalau view itu tidak ada, CSS tetap di body.
KEPALA = 'resources/views/filament/tya-kepala.blade.php'
SEED = 'resources/views/filament/sidebar-collapsed-seed.blade.php'
TANDA_KEPALA = '{{-- TYA-KEPALA (tools/ringankan-tampilan.py) --}}'
INCLUDE_KEPALA = TANDA_KEPALA + "\n@include('filament.tya-kepala')\n"
KEPALA_INLINE = (
    '<script>(function(d){d.classList.add("tya-js");setTimeout(function(){d.classList.add("tya-siap")},1800);})(document.documentElement);</script>\n'
    '<style>@media (max-width:767px){'
    'html.tya-js:not(.tya-siap) .fi-layout,html.tya-js:not(.tya-siap) .fi-topbar-ctn,html.tya-js:not(.tya-siap) .fi-topbar,html.tya-js:not(.tya-siap) .tya-bottom-nav,html.tya-js:not(.tya-siap) .tya-footer{opacity:0!important}'
    'html.tya-js:not(.tya-siap) body{background:linear-gradient(90deg,#fb923c,#f97316 50%,#ea580c) top/100% 64px no-repeat,#f4f4f6!important}'
    'html.tya-js .fi-layout,html.tya-js .fi-topbar-ctn{transition:opacity .15s ease}}</style>\n'
)


def kepala_aktif(d):
    sp = os.path.join(d, SEED)
    return os.path.isfile(sp) and TANDA_KEPALA in open(sp, encoding='utf-8').read()


def pasang_kepala(d, diam=False):
    """Sisipkan @include tya-kepala ke view HEAD_END (sekali saja)."""
    sp = os.path.join(d, SEED)
    if not os.path.isfile(sp):
        if not diam:
            print('(info) view HEAD_END tidak ditemukan -- CSS tetap dimuat di akhir halaman.')
        return False
    isi = open(sp, encoding='utf-8').read()
    if TANDA_KEPALA in isi:
        return True
    shutil.copy2(sp, sp + '.bak-kepala')
    open(sp, 'w', encoding='utf-8').write(isi.rstrip('\n') + '\n' + INCLUDE_KEPALA)
    if not diam:
        print('CSS tampilan sekarang dimuat di <head> (lebih cepat, tanpa kedip tampilan lama).')
    return True


def tulis_kepala(d, links):
    open(os.path.join(d, KEPALA), 'w', encoding='utf-8').write(
        '{{-- ' + TANDA + ': DIBANGUN OTOMATIS oleh tools/ringankan-tampilan.py -- JANGAN diedit. --}}\n'
        + KEPALA_INLINE + '\n'.join(links) + '\n')


def blade_path(d, n):
    return os.path.join(d, VIEW_DIR, n + '.blade.php')


def sumber_path(d, n):
    return os.path.join(d, SUMBER_DIR, n + '.html')


def murni(isi):
    """True kalau isinya cuma komentar Blade + <style> + <script> tanpa sintaks Blade lain."""
    s = re.sub(r'\{\{--.*?--\}\}', '', isi, flags=re.S)
    if re.search(r'\{\{|\{!!', s):
        return False
    sisa = re.sub(r'<style[^>]*>.*?</style>|<script>.*?</script>', '', s, flags=re.S).strip()
    if sisa:
        return False
    # direktif Blade di LUAR css (mis. @php, @if) -- @media/@keyframes/@supports dll di dalam CSS boleh
    tanpa_css = re.sub(r'<style[^>]*>.*?</style>', '', s, flags=re.S)
    if re.search(r'(?m)^\s*@(php|if|foreach|include|livewire|endif|csrf|auth|guest)\b', tanpa_css):
        return False
    return True


def kecilkan_css(css):
    css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
    css = re.sub(r'[ \t]*\n[ \t\n]*', '\n', css)
    return css.strip() + '\n'


def bangun(isi):
    s = re.sub(r'\{\{--.*?--\}\}', '', isi, flags=re.S)
    css = []
    js = []
    for m in re.finditer(r'<style[^>]*>(.*?)</style>|<script>(.*?)</script>', s, flags=re.S):
        if m.group(1) is not None:
            css.append(m.group(1))
        else:
            kode = m.group(2).strip('\n')
            js.append('try {\n' + kode + '\n} catch (e) { if (window.console) console.error("[tya]", e); }\n')
    return kecilkan_css('\n'.join(css)), '\n'.join(js)


def tulis_blade(d, n, css, js, links_kepala=None):
    os.makedirs(os.path.join(d, PUBLIK_DIR), exist_ok=True)
    baris = ['{{-- ' + TANDA + ': DIBANGUN OTOMATIS oleh tools/ringankan-tampilan.py -- JANGAN diedit di sini.',
             '     Sumber aslinya: ' + SUMBER_DIR + '/' + n + '.html (ubah di sana lalu jalankan ulang skripnya). --}}']
    if css.strip():
        v = hashlib.md5(css.encode()).hexdigest()[:10]
        open(os.path.join(d, PUBLIK_DIR, n + '.css'), 'w', encoding='utf-8').write(css)
        link = '<link rel="stylesheet" href="{{ asset(\'tya/' + n + '.css\') }}?v=' + v + '">'
        if links_kepala is not None:
            links_kepala.append(link)  # dimuat di <head> lewat tya-kepala.blade.php
        else:
            baris.append(link)
    if js.strip():
        v = hashlib.md5(js.encode()).hexdigest()[:10]
        open(os.path.join(d, PUBLIK_DIR, n + '.js'), 'w', encoding='utf-8').write(js)
        baris.append('<script src="{{ asset(\'tya/' + n + '.js\') }}?v=' + v + '"></script>')
    open(blade_path(d, n), 'w', encoding='utf-8').write('\n'.join(baris) + '\n')


def bangun_semua(d, dry=False, diam=False):
    """Bangun ulang dari sumber. Dipakai juga oleh pasang-rapikan-form.py."""
    hasil = 0
    kepala = (not dry) and pasang_kepala(d, diam=diam)
    links_kepala = [] if kepala else None
    for n in NAMA:
        b = blade_path(d, n)
        sp = sumber_path(d, n)
        if not os.path.isfile(b):
            continue
        isi_blade = open(b, encoding='utf-8').read()
        if TANDA not in isi_blade:
            # belum pernah diringankan
            if not murni(isi_blade):
                if not diam:
                    print(f'{n}: dilewati (berisi sintaks Blade/HTML lain, tidak aman dipindah).')
                continue
            if dry:
                print(f'{n}: AKAN DIRINGANKAN ({len(isi_blade) // 1024} KB inline -> file statis)')
                hasil += 1
                continue
            os.makedirs(os.path.dirname(sp), exist_ok=True)
            shutil.copy2(b, b + '.bak-ringan-' + time.strftime('%Y%m%d%H%M%S'))
            shutil.copy2(b, sp)
        elif not os.path.isfile(sp):
            print(f'GAGAL: {n} sudah diringankan tapi sumbernya ({sp}) hilang.', file=sys.stderr)
            return -1
        elif dry:
            print(f'{n}: sudah ringan, AKAN DIBANGUN ULANG dari sumber.')
            hasil += 1
            continue
        css, js = bangun(open(sp, encoding='utf-8').read())
        tulis_blade(d, n, css, js, links_kepala)
        if not diam:
            print(f'{n}: ringan -- CSS {len(css) // 1024} KB, JS {len(js) // 1024} KB (disimpan browser)')
        hasil += 1
    if kepala:
        tulis_kepala(d, links_kepala)
    return hasil


def kembalikan(d):
    for n in NAMA:
        sp = sumber_path(d, n)
        b = blade_path(d, n)
        if os.path.isfile(sp) and os.path.isfile(b) and TANDA in open(b, encoding='utf-8').read():
            shutil.copy2(sp, b)
            print(f'{n}: dikembalikan ke versi inline (dari sumber terbaru).')
    sp = os.path.join(d, SEED)
    if os.path.isfile(sp):
        isi = open(sp, encoding='utf-8').read()
        if TANDA_KEPALA in isi:
            open(sp, 'w', encoding='utf-8').write(isi.replace('\n' + INCLUDE_KEPALA, '\n').replace(INCLUDE_KEPALA, ''))
            print('CSS kembali dimuat di akhir halaman.')
    kp = os.path.join(d, KEPALA)
    if os.path.isfile(kp):
        open(kp, 'w', encoding='utf-8').write('{{-- tya-kepala dinonaktifkan --}}\n')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()
    if a.kembalikan:
        kembalikan(a.dir)
        print('Lalu: php8.4 artisan view:clear')
        return 0
    n = bangun_semua(a.dir, dry=a.dry_run)
    if n < 0:
        return 1
    if a.dry_run:
        print('Simulasi selesai, TIDAK ada yang diubah.')
        return 0
    if n:
        print('\nSelesai. Lalu: php8.4 artisan view:clear && php8.4 artisan filament:optimize')
        print('Pastikan folder public/tya bisa dibaca web server (biasanya otomatis).')
    else:
        print('Tidak ada file yang perlu diringankan.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
