#!/usr/bin/env python3
"""
tambah-opsi-komisi.py -- tambahkan pilihan "Tipe Komisi" ke formulir Outlet & Reseller.

Pilihan:
  - Persentase                       : komisi = persen x harga jual   (cara lama)
  - Selisih Harga Jual - Harga Beli  : komisi = harga jual voucher - harga beli (modal)
                                       voucher itu, dihitung per voucher yang terjual.

Rumus hitungnya SUDAH ada di model (Outlet::hitungKomisi) dan sudah dipakai oleh laporan
penjualan, PDF, laba/rugi, dan bot Telegram; yang belum ada hanya pilihannya di formulir.
Skrip ini menyisipkan pilihan itu DI TEMPAT (bukan menimpa berkas), jadi perubahan lain
yang sudah ada di berkas-berkas itu tidak hilang.

Yang diubah:
  app/Filament/Resources/OutletResource.php   -- pilihan di langkah "Komisi & Catatan",
                                                 kolom persen disembunyikan bila Selisih Harga,
                                                 kolom tabel "Komisi" menampilkan "Selisih harga".
  app/Filament/Resources/ResellerResource.php -- pilihan (bawaan: Selisih Harga) + kolom tabel.
  app/Models/Outlet.php                       -- 'commission_type' dimasukkan ke $fillable bila perlu
                                                 (tanpa ini pilihan di formulir DIAM-DIAM tidak tersimpan).

Keselamatan: titik sisip diperiksa dulu (tidak cocok -> berkas itu tidak disentuh), tiap berkas
dicadangkan, hasilnya diperiksa `php -l` dan dikembalikan otomatis bila sintaksnya rusak.
Aman dijalankan berulang (tidak menggandakan).

Pemakaian:
  python3 tambah-opsi-komisi.py --dry-run        # lihat rencana, tidak mengubah apa pun
  python3 tambah-opsi-komisi.py                  # pasang
  python3 tambah-opsi-komisi.py --kembalikan     # batalkan (pakai cadangan terbaru)
  Opsi: --dir /var/www/toya-radius
"""
import argparse
import glob
import os
import re
import shutil
import subprocess
import sys
import time

TANDA = 'OPSI-KOMISI-SELISIH-HARGA'

# ---- potongan kode yang disisipkan (diuji apa adanya) -------------------------------------
EKSPR_NILAI = "fn ($state) => $state instanceof \\BackedEnum ? $state->value : ($state ?: '%s')"
EKSPR_TAMPIL_PERSEN = "fn ($get) => ($get('commission_type') ?? 'percent') !== 'markup'"
EKSPR_KOLOM_OUTLET = ("fn ($record) => ($record->commission_type instanceof \\BackedEnum ? $record->commission_type->value : $record->commission_type) === 'markup'"
                      " ? 'Selisih harga' : static::formatPersen($record->commission_percent).'%'")
EKSPR_KOLOM_RESELLER = ("fn ($record) => ($record->commission_type instanceof \\BackedEnum ? $record->commission_type->value : $record->commission_type) === 'markup'"
                        " ? 'Selisih harga' : rtrim(rtrim(number_format((float) $record->commission_percent, 2, ',', ''), '0'), ',').'%'")


def blok_pilihan(indent, bawaan):
    i = indent
    return (
        f"{i}// {TANDA} (2026-10-04): cara menghitung komisi mitra ini.\n"
        f"{i}Forms\\Components\\Select::make('commission_type')\n"
        f"{i}    ->label('Tipe Komisi')\n"
        f"{i}    ->options([\n"
        f"{i}        'percent' => 'Persentase dari harga jual (%)',\n"
        f"{i}        'markup' => 'Selisih Harga Jual - Harga Beli voucher',\n"
        f"{i}    ])\n"
        f"{i}    ->default('{bawaan}')\n"
        f"{i}    ->formatStateUsing({EKSPR_NILAI % bawaan})\n"
        f"{i}    ->native(false)\n"
        f"{i}    ->live()\n"
        f"{i}    ->required()\n"
        f"{i}    ->helperText('Persentase: komisi = persen x harga jual. Selisih harga: komisi = harga jual voucher dikurangi harga beli (modal) voucher itu, dihitung per voucher yang terjual. Komisi dihitung saat laporan dibuka, jadi mengubah tipe ini ikut mengubah angka periode lama.')\n"
        f"{i}    ->columnSpanFull(),\n"
    )


def blok_persen_reseller(indent):
    i = indent
    return (
        f"{i}Forms\\Components\\TextInput::make('commission_percent')\n"
        f"{i}    ->label('Komisi (%)')\n"
        f"{i}    ->numeric()\n"
        f"{i}    ->default(0)\n"
        f"{i}    ->suffix('%')\n"
        f"{i}    ->minValue(0)\n"
        f"{i}    ->maxValue(100)\n"
        f"{i}    ->visible({EKSPR_TAMPIL_PERSEN}),\n"
    )


class Batal(Exception):
    pass


# ---- penambal per berkas --------------------------------------------------------------------
def tambal_outlet(src):
    if TANDA in src:
        return src, ['sudah terpasang']
    catatan = []
    pola = re.compile(r"^([ \t]*)Forms\\Components\\TextInput::make\('commission_percent'\)[ \t]*\n", re.M)
    cocok = pola.findall(src)
    if len(cocok) != 1:
        raise Batal(f"titik sisip kolom 'commission_percent' ditemukan {len(cocok)}x (harus tepat 1)")
    m = pola.search(src)
    indent = m.group(1)
    sisa = src[m.end():]
    label = re.search(r"^([ \t]*)->label\('Komisi \(%\)'\)[ \t]*\n", sisa, re.M)
    if not label or label.start() > 400:
        raise Batal("baris ->label('Komisi (%)') tidak ditemukan tepat setelah kolom commission_percent")
    kolom_baru = sisa[:label.end()] + f"{label.group(1)}->visible({EKSPR_TAMPIL_PERSEN})\n" + sisa[label.end():]
    src = src[:m.start()] + blok_pilihan(indent, 'percent') + src[m.start():m.end()] + kolom_baru
    catatan.append('pilihan Tipe Komisi disisipkan; kolom persen disembunyikan saat Selisih Harga')

    pola_tabel = re.compile(
        r"Tables\\Columns\\TextColumn::make\('commission_percent'\)\s*->label\('Komisi'\)\s*"
        r"->formatStateUsing\(fn \(\$state\) => static::formatPersen\(\$state\)\)\s*->suffix\('%'\),", re.S)
    if pola_tabel.search(src):
        src = pola_tabel.sub(lambda _: f"Tables\\Columns\\TextColumn::make('commission_percent')\n                    ->label('Komisi')\n                    ->state({EKSPR_KOLOM_OUTLET}),", src, count=1)
        catatan.append("kolom tabel 'Komisi' menampilkan \"Selisih harga\" untuk tipe itu")
    else:
        catatan.append("PERINGATAN: kolom tabel 'Komisi' tidak dikenali -- tidak diubah (formulir tetap berfungsi)")
    return src, catatan


def tambal_reseller(src):
    if TANDA in src:
        return src, ['sudah terpasang']
    catatan = []
    pola = re.compile(r"^([ \t]*)Forms\\Components\\TextInput::make\('saldo'\)[ \t]*\n", re.M)
    cocok = pola.findall(src)
    if len(cocok) != 1:
        raise Batal(f"titik sisip kolom 'saldo' ditemukan {len(cocok)}x (harus tepat 1)")
    m = pola.search(src)
    indent = m.group(1)
    src = src[:m.start()] + blok_pilihan(indent, 'markup') + blok_persen_reseller(indent) + src[m.start():]
    catatan.append('pilihan Tipe Komisi (bawaan: Selisih Harga) + kolom persen disisipkan di langkah Saldo')

    if "Step::make('Saldo & Catatan')" in src:
        src = src.replace("Step::make('Saldo & Catatan')", "Step::make('Saldo, Komisi & Catatan')", 1)
        catatan.append("judul langkah menjadi 'Saldo, Komisi & Catatan'")

    pola_saldo = re.compile(r"^([ \t]*)Tables\\Columns\\TextColumn::make\('saldo'\)[^\n]*\n", re.M)
    ms = pola_saldo.search(src)
    if ms:
        kolom = (f"{ms.group(1)}Tables\\Columns\\TextColumn::make('commission_percent')\n"
                 f"{ms.group(1)}    ->label('Komisi')\n"
                 f"{ms.group(1)}    ->state({EKSPR_KOLOM_RESELLER}),\n")
        src = src[:ms.end()] + kolom + src[ms.end():]
        catatan.append("kolom tabel 'Komisi' ditambahkan setelah kolom Saldo")
    else:
        catatan.append("PERINGATAN: kolom tabel 'Saldo' tidak dikenali -- kolom Komisi di tabel tidak ditambahkan (formulir tetap berfungsi)")
    return src, catatan


def tambal_model(src):
    if re.search(r"\$guarded\s*=\s*\[\s*\]", src) and not re.search(r"\$fillable", src):
        return src, ['model memakai $guarded = [] -- tidak perlu diubah']
    m = re.search(r"(protected \$fillable\s*=\s*\[)(.*?)(\n?[ \t]*\];)", src, re.S)
    if not m:
        raise Batal('daftar $fillable tidak ditemukan di model Outlet')
    isi = m.group(2)
    if "'commission_type'" in isi:
        return src, ["'commission_type' sudah ada di $fillable"]
    p = re.search(r"^([ \t]*)'commission_percent',[ \t]*\n", isi, re.M)
    if p:
        isi_baru = isi[:p.end()] + f"{p.group(1)}'commission_type',\n" + isi[p.end():]
    else:
        indent = re.search(r"\n([ \t]+)'", isi)
        ind = indent.group(1) if indent else '        '
        isi_baru = isi.rstrip() + f"\n{ind}'commission_type',"
    return src[:m.start(2)] + isi_baru + src[m.end(2):], ["'commission_type' ditambahkan ke $fillable"]


BERKAS = [
    ('app/Models/Outlet.php', tambal_model),
    ('app/Filament/Resources/OutletResource.php', tambal_outlet),
    ('app/Filament/Resources/ResellerResource.php', tambal_reseller),
]


def lint(path):
    for php in ('php8.4', 'php'):
        if shutil.which(php):
            r = subprocess.run([php, '-l', path], capture_output=True, text=True)
            return r.returncode == 0, (r.stdout + r.stderr).strip()
    return None, 'php tidak ditemukan -- pemeriksaan sintaks dilewati'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()

    if a.kembalikan:
        n = 0
        for rel, _ in BERKAS:
            path = os.path.join(a.dir, rel)
            cad = sorted(glob.glob(path + '.bak-komisi-*'))
            if cad:
                shutil.copy2(cad[-1], path); n += 1
                print(f'DIKEMBALIKAN: {rel}  <-  {os.path.basename(cad[-1])}')
        print(f'{n} berkas dikembalikan.' if n else 'Tidak ada cadangan untuk dikembalikan.')
        return 0

    rencana = []
    for rel, fungsi in BERKAS:
        path = os.path.join(a.dir, rel)
        if not os.path.isfile(path):
            print(f'GAGAL: {rel} tidak ditemukan di {a.dir}', file=sys.stderr)
            return 1
        asli = open(path, encoding='utf-8').read()
        try:
            baru, catatan = fungsi(asli)
        except Batal as e:
            print(f'GAGAL: {rel}: {e}. TIDAK ADA berkas yang diubah.', file=sys.stderr)
            return 1
        rencana.append((rel, path, asli, baru, catatan))

    for rel, _, asli, baru, catatan in rencana:
        status = 'tidak berubah' if asli == baru else 'AKAN DIUBAH' if a.dry_run else 'diubah'
        print(f'{rel}: {status}')
        for c in catatan:
            print(f'    - {c}')

    if a.dry_run:
        print('\nSimulasi selesai, TIDAK ada yang diubah.')
        return 0

    stempel = time.strftime('%Y%m%d%H%M%S')
    ditulis = []
    for rel, path, asli, baru, _ in rencana:
        if asli == baru:
            continue
        cad = f'{path}.bak-komisi-{stempel}'
        shutil.copy2(path, cad)
        open(path, 'w', encoding='utf-8').write(baru)
        ok, pesan = lint(path)
        if ok is False:
            for r2, p2, _, _, _ in rencana:      # kembalikan SEMUA yang sudah ditulis
                c2 = f'{p2}.bak-komisi-{stempel}'
                if os.path.exists(c2):
                    shutil.copy2(c2, p2)
            print(f'GAGAL: sintaks {rel} rusak setelah diubah ({pesan}). Semua berkas dikembalikan.', file=sys.stderr)
            return 1
        ditulis.append(rel)
        if ok is None:
            print(f'    (peringatan: {pesan})')

    print(f'\nSelesai: {len(ditulis)} berkas diubah.' if ditulis else '\nTidak ada yang perlu diubah (sudah terpasang).')
    if ditulis:
        print('Langkah berikutnya:\n  php8.4 artisan optimize:clear && sudo systemctl restart php8.4-fpm')
        print('Batalkan kapan saja dengan: python3 tambah-opsi-komisi.py --kembalikan')
    return 0


if __name__ == '__main__':
    sys.exit(main())
