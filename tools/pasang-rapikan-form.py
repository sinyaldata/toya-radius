#!/usr/bin/env python3
"""
pasang-rapikan-form.py -- form & modal rapi dan responsif.

Menambahkan blok CSS di AKHIR resources/views/filament/global-table-style.blade.php
(CSS global aplikasi). Berkas tidak ditimpa, hanya ditambah di ujung; aman diulang.

  1) responsif-form-blok.html  (tanda RESPONSIF-FORM-LAYAR-KECIL)
     keterangan field tidak dipotong di layar < 1280px, pratinjau skrip turun baris, dll.
  2) modal-form-blok.html      (tanda PENUTUP-MODAL-FORM)
     - tombol Batal/Simpan diam di bawah dan TIDAK lagi menimpa field paling bawah;
       hanya isi form yang digulir
     - modal berisi wizard (Profile Hotspot, dst) lebih lebar, judul langkah tidak terpotong
     - tombol Batal abu netral, hanya Simpan yang oranye
     - modal konfirmasi (Hapus) tetap kecil; di HP modal selebar layar, tombol bagi rata
  3) tombol-filter-blok.html   (tanda TOMBOL-FILTER-RAPI)
     tombol "Tampilkan Filter" satu baris setinggi kotak Search, badge bulat di pojok
  4) kartu-tabel-blok.html     (tanda KARTU-TABEL-MOBILE)
     di HP (< 768px) semua tabel tampil sebagai kartu: nomor, judul, data berlabel, tombol aksi
  5) form-ikon-blok.html       (tanda FORM-IKON-KOTAK)
     tiap field form diberi kotak ikon oranye di kiri, label di atas, input besar & tebal
  6) kartu-tabel-v2-blok.html  (tanda KARTU-TABEL-MOBILE-V2)
     kotak kartu sama besar, saklar Aktif di kanan atas, tombol Tambah jadi "+" bundar kanan bawah
  7) kartu-tabel-v3-blok.html  (tanda KARTU-TABEL-MOBILE-V3)
     tombol "+" dibuat tombol sendiri (pasti tampil), kepala tabel kosong disembunyikan, "Apply"
  8) dasbor-mobile-blok.html   (tanda DASBOR-MOBILE)
     HP: nama+foto & tombol keluar di atas, sidebar diganti menu ikon 5 kolom, sapaan/jadwal
     sholat & jam server disembunyikan, 4 kartu ringkasan masing-masing satu baris
  9) dasbor-mobile-v2-blok.html (tanda DASBOR-MOBILE-V2)
     kartu ringkasan jadi slide, ikon mata nominal pendapatan (bawaan tersembunyi), menu utama
     + sub menu di jendela bawah, ikon invoice belum dibayar di bar atas
 10) dasbor-mobile-v3-blok.html (tanda DASBOR-MOBILE-V3)
     desain kartu ringkasan: gradasi oranye, ikon krem, persentase kanan atas, grafik batang
 11) dasbor-mobile-v4-blok.html (tanda DASBOR-MOBILE-V4)
     kartu ringkasan lebih pendek, sapaan bar atas cukup nama depan
 12) dasbor-mobile-v5-blok.html (tanda DASBOR-MOBILE-V5)
     foto & nama di bar atas HP bisa diketuk untuk membuka halaman Profil
 13) dasbor-mobile-v6-blok.html (tanda DASBOR-MOBILE-V6)
     menu ikon cukup 10 (9 menu + "Lainnya"), jarak kartu-menu dirapatkan
 14) dasbor-mobile-v7-blok.html (tanda DASBOR-MOBILE-V7)
     Top 20 Invoice (kartu geser, lihat pasang-invoice-slide.py) dipindah ke bawah menu ikon
  +) daftar-ringkas-blok.html (tanda DAFTAR-RINGKAS)
     HP: tabel jadi list satu baris (ketuk = rincian & aksi, tekan lama = pilih banyak)

Pemakaian (dari /var/www/toya-radius):
  python3 tools/pasang-rapikan-form.py --dry-run
  python3 tools/pasang-rapikan-form.py
  python3 tools/pasang-rapikan-form.py --kembalikan
"""
import argparse, glob, os, shutil, sys, time

REL = 'resources/views/filament/global-table-style.blade.php'
BLOK = [
    ('responsif-form-blok.html', 'RESPONSIF-FORM-LAYAR-KECIL'),
    ('modal-form-blok.html', 'PENUTUP-MODAL-FORM'),
    ('tombol-filter-blok.html', 'TOMBOL-FILTER-RAPI'),
    ('kartu-tabel-blok.html', 'KARTU-TABEL-MOBILE'),
    ('form-ikon-blok.html', 'FORM-IKON-KOTAK'),
    ('kartu-tabel-v2-blok.html', 'KARTU-TABEL-MOBILE-V2'),
    ('kartu-tabel-v3-blok.html', 'KARTU-TABEL-MOBILE-V3'),
    ('dasbor-mobile-blok.html', 'DASBOR-MOBILE'),
    ('dasbor-mobile-v2-blok.html', 'DASBOR-MOBILE-V2'),
    ('dasbor-mobile-v3-blok.html', 'DASBOR-MOBILE-V3'),
    ('dasbor-mobile-v4-blok.html', 'DASBOR-MOBILE-V4'),
    ('dasbor-mobile-v5-blok.html', 'DASBOR-MOBILE-V5'),
    ('dasbor-mobile-v6-blok.html', 'DASBOR-MOBILE-V6'),
    ('dasbor-mobile-v7-blok.html', 'DASBOR-MOBILE-V7'),
    ('tombol-ekspor-ikon-blok.html', 'TOMBOL-EKSPOR-IKON'),
    ('kembali-filter-ikon-blok.html', 'KEMBALI-FILTER-IKON'),
    ('kembali-filter-ikon-v2-blok.html', 'KEMBALI-FILTER-IKON-V2'),
    ('kembali-filter-ikon-v5-blok.html', 'KEMBALI-FILTER-IKON-V5'),
    ('hp-tengah-blok.html', 'HP-TENGAH'),
    ('form-pro-blok.html', 'FORM-PRO'),
    ('wizard-pro-v4-blok.html', 'WIZARD-PRO-V4'),
    ('oranye-semua-v2-blok.html', 'ORANYE-SEMUA-V2'),
    ('menu-penuh-v2-blok.html', 'MENU-PENUH-V2'),
    ('kartu-slide-v2-blok.html', 'KARTU-SLIDE-V2'),
    ('toolbar-rapi-blok.html', 'TOOLBAR-RAPI'),
    ('fab-modal-blok.html', 'FAB-MODAL'),
    ('dropdown-tampil-blok.html', 'DROPDOWN-TAMPIL'),
    ('oranye-nyambung-blok.html', 'ORANYE-NYAMBUNG'),
    ('footer-hp-blok.html', 'FOOTER-HP'),
    ('siap-tampil-v2-blok.html', 'SIAP-TAMPIL-V2'),
    ('daftar-ringkas-v5-blok.html', 'DAFTAR-RINGKAS-V5'),
    ('bersih-kembali-v3-blok.html', 'BERSIH-KEMBALI-V3'),
    ('form-kartu-hp-blok.html', 'FORM-KARTU-HP'),
]

# Blok lama yang DICABUT dari file kalau ditemukan (diganti blok baru di atas).
HAPUS = [
    ('kembali-filter-ikon-v3-blok.html', 'KEMBALI-FILTER-IKON-V3'),
    ('kembali-filter-ikon-v4-blok.html', 'KEMBALI-FILTER-IKON-V4'),
    ('pengaturan-rapi-blok.html', 'PENGATURAN-RAPI'),
    ('wizard-pro-blok.html', 'WIZARD-PRO'),
    ('wizard-pro-v2-blok.html', 'WIZARD-PRO-V2'),
    ('wizard-pro-v3-blok.html', 'WIZARD-PRO-V3'),
    ('dasbor-oranye-blok.html', 'DASBOR-ORANYE'),
    ('oranye-semua-blok.html', 'ORANYE-SEMUA'),
    ('menu-penuh-blok.html', 'MENU-PENUH'),
    ('kartu-slide-blok.html', 'KARTU-SLIDE'),
    ('bersih-kembali-blok.html', 'BERSIH-KEMBALI'),
    ('siap-tampil-blok.html', 'SIAP-TAMPIL'),
    ('daftar-ringkas-blok.html', 'DAFTAR-RINGKAS'),
    ('daftar-ringkas-v2-blok.html', 'DAFTAR-RINGKAS-V2'),
    ('daftar-ringkas-v3-blok.html', 'DAFTAR-RINGKAS-V3'),
    ('daftar-ringkas-v4-blok.html', 'DAFTAR-RINGKAS-V4'),
    ('bersih-kembali-v2-blok.html', 'BERSIH-KEMBALI-V2'),
]


def bangun_ulang(d, sini):
    """Bangun ulang public/tya/*.css|js dari sumber (pakai ringankan-tampilan.py)."""
    import importlib.util
    f = os.path.join(sini, 'ringankan-tampilan.py')
    if not os.path.isfile(f):
        print('GAGAL: ringankan-tampilan.py harus satu folder dengan skrip ini.', file=sys.stderr)
        return False
    spec = importlib.util.spec_from_file_location('ringankan_tampilan', f)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod.bangun_semua(d) >= 0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()
    path = os.path.join(a.dir, REL)
    sini = os.path.dirname(os.path.abspath(__file__))

    # Kalau tampilan sudah "diringankan" (ringankan-tampilan.py), berkas blade
    # tinggal 2 baris <link>/<script>; tambalan ditulis ke SUMBER-nya lalu
    # file statis dibangun ulang otomatis.
    ringan = False
    if os.path.isfile(path) and 'RINGANKAN-TAMPILAN' in open(path, encoding='utf-8').read():
        ringan = True
        path = os.path.join(a.dir, 'resources/views/filament/tya-sumber/global-table-style.html')
        if not os.path.isfile(path):
            print('GAGAL: sumber tampilan (tya-sumber/global-table-style.html) tidak ditemukan.', file=sys.stderr)
            return 1

    if a.kembalikan:
        cad = sorted(glob.glob(path + '.bak-rapikan-*'))
        if not cad:
            print('Tidak ada cadangan untuk dikembalikan.')
            return 0
        shutil.copy2(cad[-1], path)
        print(f'DIKEMBALIKAN: {os.path.relpath(path, a.dir)}  <-  {os.path.basename(cad[-1])}')
        if ringan:
            bangun_ulang(a.dir, sini)
        print('Lalu: php8.4 artisan view:clear')
        return 0

    if not os.path.isfile(path):
        print(f'GAGAL: {REL} tidak ditemukan di {a.dir}', file=sys.stderr)
        return 1
    asli = open(path, encoding='utf-8').read()
    if '</style>' not in asli:
        print(f'GAGAL: {REL} tidak tampak seperti berkas CSS global yang saya kenal.', file=sys.stderr)
        return 1

    baru = asli
    for nama, tanda in HAPUS:
        # dicabut berdasarkan ISI PERSIS blok lama (bukan nama penanda, karena
        # penanda lama bisa ikut tercantum di dalam blok penggantinya)
        p = os.path.join(sini, nama)
        if not os.path.isfile(p):
            continue
        lama = open(p, encoding='utf-8').read().rstrip('\n') + '\n'
        if lama not in baru:
            continue
        baru = baru.replace(lama, '', 1)
        print(f'{nama}: {"AKAN DICABUT" if a.dry_run else "dicabut"} (diganti versi baru)')

    for nama, tanda in BLOK:
        p = os.path.join(sini, nama)
        if not os.path.isfile(p):
            print(f'GAGAL: {nama} harus satu folder dengan skrip ini.', file=sys.stderr)
            return 1
        if tanda in baru:
            print(f'{nama}: sudah terpasang, dilewati.')
            continue
        isi = open(p, encoding='utf-8').read()
        if '{{' in isi or '{!!' in isi or '@php' in isi:
            print(f'GAGAL: {nama} mengandung sintaks Blade, dibatalkan.', file=sys.stderr)
            return 1
        baru = baru.rstrip('\n') + '\n' + isi.rstrip('\n') + '\n'
        print(f'{nama}: {"AKAN DITAMBAH" if a.dry_run else "ditambah"} ({len(isi.splitlines())} baris)')

    if baru == asli:
        print('Tidak ada yang perlu diubah (semua sudah terpasang).')
        return 0
    if a.dry_run:
        print('Simulasi selesai, TIDAK ada yang diubah.')
        return 0

    cad = f'{path}.bak-rapikan-{time.strftime("%Y%m%d%H%M%S")}'
    shutil.copy2(path, cad)
    open(path, 'w', encoding='utf-8').write(baru)
    print(f'Cadangan: {os.path.basename(cad)}')
    if ringan:
        if not bangun_ulang(a.dir, sini):
            shutil.copy2(cad, path)
            print('GAGAL membangun ulang file statis -- sumber dikembalikan.', file=sys.stderr)
            return 1
    print('Langkah berikutnya:\n  php8.4 artisan view:clear && php8.4 artisan filament:optimize\n  lalu di browser tekan Ctrl+F5')
    return 0


if __name__ == '__main__':
    sys.exit(main())
