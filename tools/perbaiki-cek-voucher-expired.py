#!/usr/bin/env python3
"""
perbaiki-cek-voucher-expired.py -- /cek di bot Telegram: voucher yang sudah kadaluarsa
tidak lagi dilaporkan "Belum aktif".

Penyebab: handleCekVoucher() menganggap SEMUA status selain "Aktif" sebagai "Belum aktif",
termasuk voucher yang sudah Expired atau Dinonaktifkan. Skrip ini menyisipkan pemeriksaan
Expired / Dinonaktifkan sebelum jawaban "Belum aktif". Voucher yang tanggal expired-nya sudah
lewat (walau statusnya belum sempat diubah penjadwal) juga dilaporkan kadaluarsa.

  python3 tools/perbaiki-cek-voucher-expired.py --dry-run
  python3 tools/perbaiki-cek-voucher-expired.py
  python3 tools/perbaiki-cek-voucher-expired.py --kembalikan
"""
import argparse, glob, os, shutil, subprocess, sys, time

REL = 'app/Http/Controllers/TelegramWebhookController.php'
TANDA = 'CEK-VOUCHER-EXPIRED'
JANGKAR = ("        if ($voucher->status !== \\App\\Enums\\VoucherStatus::Aktif) {\n"
           "            $telegram->sendMessage($chatId, \"🔴 Voucher <code>{$voucher->username}</code>{$labelOutlet}\\n\\nKeterangan: Belum aktif\");")
SISIPAN = r'''        // CEK-VOUCHER-EXPIRED (2026-10-16): voucher kadaluarsa / dinonaktifkan
        // dulu dilaporkan "Belum aktif" karena semua status selain Aktif jatuh
        // ke jawaban itu. Tanggal expired yang sudah lewat juga dihitung
        // kadaluarsa walau statusnya belum sempat diubah oleh penjadwal.
        $tyaSudahLewat = $voucher->expired_at && $voucher->expired_at->isPast();

        if ($voucher->status === \App\Enums\VoucherStatus::Expired || ($tyaSudahLewat && $voucher->activated_at)) {
            $tyaAktif = $voucher->activated_at ? $voucher->activated_at->format('d-m-Y H:i') : 'tidak tercatat';
            $tyaExpired = $voucher->expired_at ? $voucher->expired_at->format('d-m-Y H:i') : 'tidak tercatat';
            $telegram->sendMessage($chatId, "⚫ Voucher <code>{$voucher->username}</code>{$labelOutlet}\n\nKeterangan: Sudah kadaluarsa (expired)\nAktif: {$tyaAktif}\nExpired: {$tyaExpired}");

            return;
        }

        if ($voucher->status === \App\Enums\VoucherStatus::Disabled) {
            $telegram->sendMessage($chatId, "⛔ Voucher <code>{$voucher->username}</code>{$labelOutlet}\n\nKeterangan: Dinonaktifkan");

            return;
        }

'''


def lint(p):
    for php in ('php8.4', 'php'):
        if shutil.which(php):
            r = subprocess.run([php, '-l', p], capture_output=True, text=True)
            return r.returncode == 0, (r.stdout + r.stderr).strip()
    return None, 'php tidak ditemukan'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    a = ap.parse_args()
    path = os.path.join(a.dir, REL)
    if a.kembalikan:
        cad = sorted(glob.glob(path + '.bak-cekexp-*'))
        if not cad:
            print('Tidak ada cadangan.')
            return 0
        shutil.copy2(cad[-1], path)
        print(f'DIKEMBALIKAN: {REL}')
        return 0
    src = open(path, encoding='utf-8').read()
    if TANDA in src:
        print('Sudah terpasang -- tidak ada yang diubah.')
        return 0
    n = src.count(JANGKAR)
    if n != 1:
        print(f'GAGAL: titik sisip ditemukan {n}x (harus 1). Tidak ada yang diubah.', file=sys.stderr)
        return 1
    baru = src.replace(JANGKAR, SISIPAN + JANGKAR, 1)
    print(f'{REL}: {"AKAN DIUBAH" if a.dry_run else "diubah"}')
    if a.dry_run:
        return 0
    cad = f'{path}.bak-cekexp-{time.strftime("%Y%m%d%H%M%S")}'
    shutil.copy2(path, cad)
    open(path, 'w', encoding='utf-8').write(baru)
    ok, pesan = lint(path)
    if ok is False:
        shutil.copy2(cad, path)
        print(f'GAGAL: sintaks rusak ({pesan}). Dikembalikan.', file=sys.stderr)
        return 1
    print('Selesai. Lalu: php8.4 artisan optimize:clear && sudo systemctl restart php8.4-fpm')
    return 0


if __name__ == '__main__':
    sys.exit(main())
