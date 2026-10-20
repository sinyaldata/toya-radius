#!/usr/bin/env python3
"""
pasang-hapus-penjualan.py -- fitur HAPUS PENJUALAN (permanen), khusus SUPER ADMIN:
  - tombol "Hapus Penjualan" (ikon tempat sampah merah) di menu
    Hotspot > Voucher Terjual  dan  Transaksi   (hanya tampil untuk super admin)
  - perintah bot Telegram:  /hapusjual KODEVOUCHER  (hanya Chat ID super admin)
  - perintah server untuk mendaftarkan Chat ID super admin bot:
      php8.4 artisan toya:superadmin-bot <chat_id> [--tenant=subdomain]

File baru:
  app/Services/HapusPenjualan.php, app/Services/HapusPenjualanBot.php,
  app/Filament/Actions/HapusPenjualanAction.php, app/Console/Commands/SuperAdminBot.php
File yang disisipi (dicadangkan dulu, *.bak-hapusjual-...):
  app/Filament/Resources/SoldVoucherResource.php, app/Filament/Resources/TransactionResource.php,
  app/Http/Controllers/TelegramWebhookController.php

  python3 tools/pasang-hapus-penjualan.py --dry-run
  python3 tools/pasang-hapus-penjualan.py
  python3 tools/pasang-hapus-penjualan.py --kembalikan
"""
import argparse, glob, os, re, shutil, subprocess, sys, time

SINI = os.path.dirname(os.path.abspath(__file__))
SUMBER = os.path.join(SINI, 'hapus-penjualan')
BARU = [
    'app/Services/HapusPenjualan.php',
    'app/Services/HapusPenjualanBot.php',
    'app/Filament/Actions/HapusPenjualanAction.php',
    'app/Console/Commands/SuperAdminBot.php',
]
TANDA = 'HAPUS-PENJUALAN'
SFX = '.bak-hapusjual-'


def lint(p):
    for php in ('php8.4', 'php'):
        if shutil.which(php):
            r = subprocess.run([php, '-l', p], capture_output=True, text=True)
            return r.returncode == 0, (r.stdout + r.stderr).strip()
    return True, ''


def sisip_resource(isi, panggil):
    """Sisipkan action di awal ->recordActions([ ... ])."""
    n = len(re.findall(r'->recordActions\(\[', isi))
    if n != 1:
        return None, f'pola ->recordActions([ ditemukan {n}x (harus 1)'
    m = re.search(r'(->recordActions\(\[)\n(\s*)', isi)
    if not m:
        return None, 'format ->recordActions([ tidak dikenali'
    indent = m.group(2)
    tambah = f'{m.group(1)}\n{indent}{panggil}, // {TANDA}\n{indent}'
    return isi[:m.start()] + tambah + isi[m.end():], None


def sisip_bot(isi):
    # 1) perintah teks, tepat sebelum blok "/link"
    pola = re.compile(r"\n([ \t]*)if \(str_starts_with\(\$text, '/link'\)\) \{")
    ms = list(pola.finditer(isi))
    if len(ms) != 1:
        return None, f"blok perintah '/link' ditemukan {len(ms)}x (harus 1)"
    ind = ms[0].group(1)
    blok = (f"\n{ind}// {TANDA}: /hapusjual KODE -- khusus super admin (lihat App\\Services\\HapusPenjualanBot)"
            f"\n{ind}if ($text === '/hapusjual' || str_starts_with($text, '/hapusjual ')) {{"
            f"\n{ind}    \\App\\Services\\HapusPenjualanBot::perintah($chatId, trim(substr($text, strlen('/hapusjual'))), $telegram);"
            f"\n\n{ind}    return;"
            f"\n{ind}}}\n")
    isi = isi[:ms[0].start()] + blok + isi[ms[0].start():]
    # 2) tombol (callback), tepat setelah answerCallbackQuery di awal handleCallback
    f = re.search(r'function handleCallback\([^)]*\)[^{]*\{', isi)
    if not f:
        return None, 'fungsi handleCallback tidak ditemukan'
    a = re.compile(r"\n([ \t]*)\$telegram->answerCallbackQuery\(\$callback\['id'\]\);\n").search(isi, f.end())
    if not a or a.start() - f.end() > 1500:
        return None, 'baris answerCallbackQuery di awal handleCallback tidak ditemukan'
    ind = a.group(1)
    blok2 = (f"\n{ind}// {TANDA}: tombol konfirmasi /hapusjual"
             f"\n{ind}if (str_starts_with($data, 'hpsjual:') || $data === 'hpsjual_batal') {{"
             f"\n{ind}    \\App\\Services\\HapusPenjualanBot::callback($chatId, $messageId, $data, $telegram);"
             f"\n\n{ind}    return;"
             f"\n{ind}}}\n")
    isi = isi[:a.end()] + blok2 + isi[a.end():]
    return isi, None


SISIP = [
    ('app/Filament/Resources/SoldVoucherResource.php', lambda s: sisip_resource(s, '\\App\\Filament\\Actions\\HapusPenjualanAction::untukVoucher()')),
    ('app/Filament/Resources/TransactionResource.php', lambda s: sisip_resource(s, '\\App\\Filament\\Actions\\HapusPenjualanAction::untukTransaksi()')),
    ('app/Http/Controllers/TelegramWebhookController.php', sisip_bot),
]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()

    if a.kembalikan:
        for rel, _ in SISIP:
            p = os.path.join(a.dir, rel)
            cad = sorted(glob.glob(p + SFX + '*'))
            if cad:
                shutil.copy2(cad[0], p)
                print(f'DIKEMBALIKAN: {rel}')
        for rel in BARU:
            p = os.path.join(a.dir, rel)
            if os.path.isfile(p):
                os.remove(p)
                print(f'DIHAPUS: {rel}')
        print('Lalu: php8.4 artisan optimize:clear && php8.4 artisan filament:optimize')
        return 0

    stamp = time.strftime('%Y%m%d%H%M%S')
    gagal = 0

    for rel in BARU:
        src = os.path.join(SUMBER, rel)
        dst = os.path.join(a.dir, rel)
        sama = os.path.isfile(dst) and open(dst, 'rb').read() == open(src, 'rb').read()
        print(f'{rel}: {"sudah" if sama else ("AKAN DITULIS" if a.dry_run else "ditulis")}')
        if sama or a.dry_run:
            continue
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        shutil.copyfile(src, dst)
        ok, pesan = lint(dst)
        if not ok:
            os.remove(dst)
            print(f'GAGAL sintaks {rel}: {pesan}', file=sys.stderr)
            return 1

    for rel, fungsi in SISIP:
        p = os.path.join(a.dir, rel)
        if not os.path.isfile(p):
            print(f'{rel}: TIDAK ADA -- dilewati')
            continue
        isi = open(p, encoding='utf-8').read()
        if TANDA in isi:
            print(f'{rel}: sudah')
            continue
        baru, salah = fungsi(isi)
        if salah:
            print(f'{rel}: DILEWATI -- {salah}. Kirim file ini ke saya untuk disesuaikan.', file=sys.stderr)
            gagal += 1
            continue
        print(f'{rel}: {"AKAN DISISIPI" if a.dry_run else "disisipi"}')
        if a.dry_run:
            continue
        shutil.copy2(p, p + SFX + stamp)
        open(p, 'w', encoding='utf-8').write(baru)
        ok, pesan = lint(p)
        if not ok:
            shutil.copy2(p + SFX + stamp, p)
            print(f'GAGAL sintaks {rel} ({pesan}) -- dikembalikan.', file=sys.stderr)
            gagal += 1

    if not a.dry_run:
        print('\nSelesai.' + (f' ({gagal} file dilewati, lihat pesan di atas)' if gagal else ''))
        print('Lalu: php8.4 artisan optimize:clear && php8.4 artisan filament:optimize')
        print('Daftarkan Chat ID super admin bot: php8.4 artisan toya:superadmin-bot <chat_id> [--tenant=subdomain]')
    return 1 if gagal else 0


if __name__ == '__main__':
    sys.exit(main())
