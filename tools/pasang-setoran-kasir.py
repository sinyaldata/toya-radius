#!/usr/bin/env python3
"""
pasang-setoran-kasir.py -- pasang perintah bot /setor (terima setoran mitra untuk kasir).

Kelas utamanya (app/Services/SetoranKasirBot.php) sudah ikut di zip. Skrip ini hanya
menyambungkannya ke berkas yang SUDAH ADA di server dengan menyisipkan beberapa baris
DI TEMPAT (bukan menimpa berkas), jadi perubahan lain di berkas itu tidak hilang:

  app/Http/Controllers/TelegramWebhookController.php   (wajib)
      - pintu pesan  : /setor, /rekapsetoran, /kasir, jawaban di tengah alur
      - pintu tombol : callback "setor:*"
      - alur "setor" didaftarkan ke adaAlurAktif() & kunciAlurTanyaJawab()
        (supaya rapikan-chat otomatis & tombol menu ikut mengenalinya)
      - peranMenu() mengenali peran 'kasir'
      - /bantuan admin memuat /rekapsetoran dan /kasir
  app/Support/TelegramMenu.php                          (wajib)
      - tombol "Terima Setoran" & "Rekap Setoran" untuk kasir, "Rekap Setoran" untuk admin
  app/Observers/CashierDepositObserver.php              (opsional)
      - pemberitahuan ke admin menulis "Reseller:" bukan "Outlet:" untuk reseller, dan
        "Diterima kasir: via bot Telegram" untuk setoran yang dicatat lewat bot

Keselamatan: SEMUA titik sisip diperiksa dulu (harus tepat 1 kecocokan) -- kalau ada yang tidak
cocok, TIDAK ADA berkas yang diubah. Tiap berkas dicadangkan, hasilnya diperiksa `php -l` dan
semuanya dikembalikan otomatis bila ada sintaks yang rusak. Aman dijalankan berulang.

Pemakaian:
  python3 pasang-setoran-kasir.py --dry-run
  python3 pasang-setoran-kasir.py
  python3 pasang-setoran-kasir.py --kembalikan
  Opsi: --dir /var/www/toya-radius
"""
import argparse
import glob
import os
import shutil
import subprocess
import sys
import time

TANDA = 'SETORAN-KASIR-BOT'


class Batal(Exception):
    pass


def tepat_satu(src, anchor, nama):
    n = src.count(anchor)
    if n != 1:
        raise Batal(f"titik sisip '{nama}' ditemukan {n}x (harus tepat 1)")


def sisip_sebelum(src, anchor, teks, nama):
    tepat_satu(src, anchor, nama)
    i = src.index(anchor)
    return src[:i] + teks + src[i:]


def sisip_sesudah(src, anchor, teks, nama):
    tepat_satu(src, anchor, nama)
    i = src.index(anchor) + len(anchor)
    return src[:i] + teks + src[i:]


# ------------------------------------------------------------------ controller
def tambal_controller(src):
    if TANDA in src:
        return src, ['sudah terpasang']
    c = []

    src = sisip_sebelum(
        src,
        "        ] as $kunci) {\n            if (Cache::has($kunci)) {",
        f"            \\App\\Services\\SetoranKasirBot::kunciAlur($chatId), // {TANDA}\n",
        'adaAlurAktif()')
    c.append('alur setoran didaftarkan ke adaAlurAktif()')

    anchor = "            $this->cekVoucherFlowKey($chatId) => null,\n        ];"
    tepat_satu(src, anchor, 'kunciAlurTanyaJawab()')
    src = src.replace(
        anchor,
        "            $this->cekVoucherFlowKey($chatId) => null,\n"
        f"            \\App\\Services\\SetoranKasirBot::kunciAlur($chatId) => null, // {TANDA}\n        ];")
    c.append('alur setoran didaftarkan ke kunciAlurTanyaJawab()')

    src = sisip_sebelum(
        src,
        "        // BARU: kalau chat ini SEDANG di tengah alur /pppoe (isi Nama,",
        f"        // {TANDA} (2026-10-08): /setor & /rekapsetoran (kasir), /kasir (admin), dan\n"
        "        // jawaban kasir di tengah alur setoran. Ditaruh SEBELUM alur lain supaya\n"
        "        // angka jumlah setoran tidak tertangkap alur lain. Lihat App\\Services\\SetoranKasirBot.\n"
        "        if ((new \\App\\Services\\SetoranKasirBot($telegram))->handlePesan($chatId, $text, $message, $this->isAdminChat($chatId))) {\n"
        "            return;\n"
        "        }\n\n",
        'handleMessage()')
    c.append('pintu pesan disisipkan di handleMessage()')

    anchor = ("        $chatId = (string) $callback['message']['chat']['id'];\n"
              "        $messageId = (int) $callback['message']['message_id'];\n"
              "        $data = $callback['data'] ?? '';\n"
              "        $telegram->answerCallbackQuery($callback['id']);\n")
    src = sisip_sesudah(
        src, anchor,
        f"\n        // {TANDA}: tombol \"setor:*\" (alur /setor & rekap).\n"
        "        if (str_starts_with($data, 'setor:')\n"
        "            && (new \\App\\Services\\SetoranKasirBot($telegram))->handleCallback($chatId, $messageId, $data, $this->isAdminChat($chatId))) {\n"
        "            return;\n"
        "        }\n",
        'handleCallback()')
    c.append('pintu tombol disisipkan di handleCallback()')

    src = sisip_sebelum(
        src,
        "        $mitra = Outlet::where('telegram_chat_id', $chatId)->first();\n\n        if (! $mitra) {\n            return 'tamu';",
        f"        // {TANDA}: kasir (bukan admin) -> menu kasir.\n"
        "        if (\\App\\Services\\SetoranKasirBot::adalahKasir($chatId)) {\n"
        "            return 'kasir';\n"
        "        }\n\n",
        'peranMenu()')
    c.append("peranMenu() mengenali peran 'kasir'")

    src = sisip_sesudah(
        src,
        '            $bantuan .= "/outletnunggak — outlet yang belum setor bulan ini\\n";\n',
        '            $bantuan .= "/rekapsetoran — rekap setoran mitra yang diterima kasir (hari ini / kemarin / bulan ini / bulan lalu)\\n";\n'
        '            $bantuan .= "/kasir — kelola daftar Chat ID kasir &amp; pengaturan setoran Reseller\\n";\n',
        '/bantuan admin')
    c.append('/bantuan admin memuat /rekapsetoran & /kasir')
    return src, c


# ------------------------------------------------------------------ menu
def tambal_menu(src):
    if "const SETOR" in src:
        return src, ['sudah terpasang']
    c = []
    src = sisip_sesudah(
        src, "    public const LAPORAN = '📊 Laporan';\n",
        f"\n    // {TANDA}\n    public const SETOR = '💵 Terima Setoran';\n\n    public const REKAP = '📋 Rekap Setoran';\n",
        'konstanta LAPORAN')
    src = sisip_sesudah(
        src, "        self::LAPORAN => '/pendapatan',\n",
        "        self::SETOR => '/setor',\n        self::REKAP => '/rekapsetoran',\n",
        'PERINTAH')
    tepat_satu(src, "'admin' => [self::VOUCHER, self::SALDO, self::OFFLINE, self::TAGIHAN, self::LAPORAN],", 'tombol admin')
    src = src.replace(
        "'admin' => [self::VOUCHER, self::SALDO, self::OFFLINE, self::TAGIHAN, self::LAPORAN],",
        "'admin' => [self::VOUCHER, self::SALDO, self::OFFLINE, self::TAGIHAN, self::LAPORAN, self::REKAP],")
    src = sisip_sesudah(
        src, "        'biller' => [self::TAGIHAN, self::OFFLINE],\n",
        "        'kasir' => [self::SETOR, self::REKAP],\n",
        'tombol biller')
    c.append("tombol 'Terima Setoran' & 'Rekap Setoran' (kasir), 'Rekap Setoran' (admin)")
    return src, c


# ------------------------------------------------------------------ observer (opsional)
def tambal_observer(src):
    if TANDA in src:
        return src, ['sudah terpasang']
    c = []
    a1 = '        $teks .= "Outlet: <b>{$namaOutlet}</b>\\n";\n'
    tepat_satu(src, a1, 'baris "Outlet:"')
    src = src.replace(
        a1,
        f"        // {TANDA}: tulis jenis mitra yang sebenarnya (Outlet / Reseller / Biller).\n"
        "        $tipeMitra = $deposit->outlet?->partner_type;\n"
        "        $tipeMitra = $tipeMitra instanceof \\BackedEnum ? $tipeMitra->value : (string) $tipeMitra;\n"
        "        $labelMitra = match ($tipeMitra) {\n"
        "            'reseller' => 'Reseller',\n"
        "            'biller' => 'Biller',\n"
        "            default => 'Outlet',\n"
        "        };\n"
        '        $teks .= "{$labelMitra}: <b>{$namaOutlet}</b>\\n";\n')
    c.append('label mitra mengikuti jenisnya')
    a2 = "        $kasir = $deposit->receivedBy?->name ?? '-';\n"
    tepat_satu(src, a2, 'baris kasir')
    src = src.replace(
        a2,
        "        $kasir = $deposit->receivedBy?->name\n"
        "            ?? (str_starts_with((string) $deposit->notes, 'Dicatat via bot Telegram') ? 'via bot Telegram' : '-');\n")
    c.append('"Diterima kasir" untuk setoran lewat bot')
    return src, c


BERKAS = [
    ('app/Http/Controllers/TelegramWebhookController.php', tambal_controller, True),
    ('app/Support/TelegramMenu.php', tambal_menu, True),
    ('app/Observers/CashierDepositObserver.php', tambal_observer, False),
]
KELAS = 'app/Services/SetoranKasirBot.php'


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
        for rel, _, _ in BERKAS:
            path = os.path.join(a.dir, rel)
            cad = sorted(glob.glob(path + '.bak-setoran-*'))
            if cad:
                shutil.copy2(cad[-1], path)
                n += 1
                print(f'DIKEMBALIKAN: {rel}  <-  {os.path.basename(cad[-1])}')
        print(f'{n} berkas dikembalikan.' if n else 'Tidak ada cadangan untuk dikembalikan.')
        return 0

    if not os.path.isfile(os.path.join(a.dir, KELAS)):
        print(f'GAGAL: {KELAS} belum ada di {a.dir}. Unggah isi zip dulu (folder app/ dan tools/).', file=sys.stderr)
        return 1

    rencana = []
    for rel, fungsi, wajib in BERKAS:
        path = os.path.join(a.dir, rel)
        if not os.path.isfile(path):
            if wajib:
                print(f'GAGAL: {rel} tidak ditemukan di {a.dir}', file=sys.stderr)
                return 1
            print(f'{rel}: dilewati (berkas tidak ada)')
            continue
        asli = open(path, encoding='utf-8').read()
        try:
            baru, catatan = fungsi(asli)
        except Batal as e:
            if wajib:
                print(f'GAGAL: {rel}: {e}. TIDAK ADA berkas yang diubah.', file=sys.stderr)
                print('Kemungkinan berkas di server berbeda dari versi yang saya kenal -- kirimkan berkas itu ke saya.', file=sys.stderr)
                return 1
            print(f'{rel}: dilewati ({e}) -- bagian opsional, bot tetap berfungsi tanpanya')
            continue
        rencana.append((rel, path, asli, baru, catatan))

    for rel, _, asli, baru, catatan in rencana:
        status = 'tidak berubah' if asli == baru else ('AKAN DIUBAH' if a.dry_run else 'diubah')
        print(f'{rel}: {status}')
        for c in catatan:
            print(f'    - {c}')

    if a.dry_run:
        print('\nSimulasi selesai, TIDAK ada yang diubah.')
        return 0

    stempel = time.strftime('%Y%m%d%H%M%S')
    ditulis = []
    ok_kelas, pesan_kelas = lint(os.path.join(a.dir, KELAS))
    if ok_kelas is False:
        print(f'GAGAL: sintaks {KELAS} rusak ({pesan_kelas}). Tidak ada yang diubah.', file=sys.stderr)
        return 1

    for rel, path, asli, baru, _ in rencana:
        if asli == baru:
            continue
        cad = f'{path}.bak-setoran-{stempel}'
        shutil.copy2(path, cad)
        open(path, 'w', encoding='utf-8').write(baru)
        ok, pesan = lint(path)
        if ok is False:
            for _, p2, _, _, _ in rencana:
                c2 = f'{p2}.bak-setoran-{stempel}'
                if os.path.exists(c2):
                    shutil.copy2(c2, p2)
            print(f'GAGAL: sintaks {rel} rusak setelah diubah ({pesan}). Semua berkas dikembalikan.', file=sys.stderr)
            return 1
        ditulis.append(rel)
        if ok is None:
            print(f'    (peringatan: {pesan})')

    print(f'\nSelesai: {len(ditulis)} berkas diubah.' if ditulis else '\nTidak ada yang perlu diubah (sudah terpasang).')
    if ditulis:
        print('Langkah berikutnya:\n  composer dump-autoload && php8.4 artisan optimize:clear && sudo systemctl restart php8.4-fpm')
        print('Batalkan kapan saja dengan: python3 tools/pasang-setoran-kasir.py --kembalikan')
    return 0


if __name__ == '__main__':
    sys.exit(main())
