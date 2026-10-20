#!/usr/bin/env python3
"""
pasang-label-indonesia.py -- label tombol bawaan Filament jadi Indonesia:
  "New X" / "Create X"      -> "Tambah X"   (tombol & judul popup/halaman)
  "Create" / "Save changes" / "Submit" -> "Simpan"
  "Create & create another" -> "Simpan & tambah lagi"
  "Cancel" -> "Batal", wizard "Next" -> "Selanjutnya", "Back" -> "Kembali"
  breadcrumb "Create" -> "Tambah"

Caranya resmi Laravel: file penimpa terjemahan di lang/vendor/<paket>/<bahasa>/
(hanya kunci yang ditulis yang ditimpa, sisanya tetap bawaan Filament).
Ditulis untuk bahasa "en" dan "id" sekaligus. Kode PHP aplikasi tidak disentuh.

  python3 tools/pasang-label-indonesia.py --dry-run
  python3 tools/pasang-label-indonesia.py
  python3 tools/pasang-label-indonesia.py --kembalikan
"""
import argparse, os, shutil, subprocess, sys, time

TANDA = '// LABEL-INDONESIA (tools/pasang-label-indonesia.py)'

FILES = {
    'filament-actions/{b}/create.php': """<?php

{tanda}
return [
    'single' => [
        'label' => 'Tambah :label',
        'modal' => [
            'heading' => 'Tambah :label',
            'actions' => [
                'create' => ['label' => 'Simpan'],
                'create_another' => ['label' => 'Simpan & tambah lagi'],
            ],
        ],
        'notifications' => [
            'created' => ['title' => 'Berhasil disimpan'],
        ],
    ],
];
""",
    'filament-actions/{b}/edit.php': """<?php

{tanda}
return [
    'single' => [
        'label' => 'Edit',
        'modal' => [
            'heading' => 'Edit :label',
            'actions' => [
                'save' => ['label' => 'Simpan'],
            ],
        ],
        'notifications' => [
            'saved' => ['title' => 'Perubahan disimpan'],
        ],
    ],
];
""",
    'filament-actions/{b}/modal.php': """<?php

{tanda}
return [
    'actions' => [
        'cancel' => ['label' => 'Batal'],
        'submit' => ['label' => 'Simpan'],
    ],
];
""",
    'filament-panels/{b}/resources/pages/create-record.php': """<?php

{tanda}
return [
    'title' => 'Tambah :label',
    'breadcrumb' => 'Tambah',
    'form' => [
        'actions' => [
            'cancel' => ['label' => 'Batal'],
            'create' => ['label' => 'Simpan'],
            'create_another' => ['label' => 'Simpan & tambah lagi'],
        ],
    ],
    'notifications' => [
        'created' => ['title' => 'Berhasil disimpan'],
    ],
];
""",
    'filament-panels/{b}/resources/pages/edit-record.php': """<?php

{tanda}
return [
    'form' => [
        'actions' => [
            'cancel' => ['label' => 'Batal'],
            'save' => ['label' => 'Simpan'],
        ],
    ],
    'notifications' => [
        'saved' => ['title' => 'Perubahan disimpan'],
    ],
];
""",
    'filament-schemas/{b}/components.php': """<?php

{tanda}
return [
    'wizard' => [
        'actions' => [
            'previous_step' => ['label' => 'Kembali'],
            'next_step' => ['label' => 'Selanjutnya'],
        ],
    ],
];
""",
}
BAHASA = ['en', 'id']


def lint(p):
    for php in ('php8.4', 'php'):
        if shutil.which(php):
            r = subprocess.run([php, '-l', p], capture_output=True, text=True)
            return r.returncode == 0
    return True


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()
    dasar = os.path.join(a.dir, 'lang', 'vendor')

    if a.kembalikan:
        for rel in FILES:
            for b in BAHASA:
                p = os.path.join(dasar, rel.format(b=b))
                if os.path.isfile(p) and TANDA in open(p, encoding='utf-8').read():
                    cad = p + '.bak-label'
                    if os.path.isfile(cad):
                        shutil.copy2(cad, p)
                        print(f'DIKEMBALIKAN: {os.path.relpath(p, a.dir)}')
                    else:
                        os.remove(p)
                        print(f'DIHAPUS: {os.path.relpath(p, a.dir)}')
        print('Lalu: php8.4 artisan view:clear && php8.4 artisan filament:optimize')
        return 0

    for rel, isi in FILES.items():
        for b in BAHASA:
            p = os.path.join(dasar, rel.format(b=b))
            r = os.path.relpath(p, a.dir)
            baru = isi.replace('{tanda}', TANDA)
            if os.path.isfile(p):
                lama = open(p, encoding='utf-8').read()
                if lama == baru:
                    print(f'sudah: {r}')
                    continue
                if TANDA not in lama:
                    print(f'DILEWATI (sudah ada file buatan sendiri, tidak ditimpa): {r}')
                    continue
            print(f'{"AKAN DITULIS" if a.dry_run else "ditulis"}: {r}')
            if a.dry_run:
                continue
            os.makedirs(os.path.dirname(p), exist_ok=True)
            if os.path.isfile(p) and not os.path.isfile(p + '.bak-label'):
                shutil.copy2(p, p + '.bak-label')
            open(p, 'w', encoding='utf-8').write(baru)
            if not lint(p):
                os.remove(p)
                print(f'GAGAL sintaks, dibatalkan: {r}', file=sys.stderr)
                return 1
    if not a.dry_run:
        print('\nSelesai. Lalu: php8.4 artisan view:clear && php8.4 artisan filament:optimize')
    return 0


if __name__ == '__main__':
    sys.exit(main())
