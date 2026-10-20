#!/usr/bin/env python3
"""Pasang perintah Mini ATM ke bot Telegram Kasir26.  python3 patch_telegram.py [/var/www/kasir26]"""
import os, re, shutil, sys
root = ([a for a in sys.argv[1:] if not a.startswith('--')] or ['/var/www/kasir26'])[0]
p = os.path.join(root, 'app/Services/TelegramCommandHandler.php')
if not os.path.exists(p): sys.exit('Tidak ada: ' + p)
s = open(p, encoding='utf-8').read()
if 'MiniAtmBot' in s: sys.exit('Sudah terpasang.')
shutil.copy2(p, p + '.bak-miniatm')

# 1. rute perintah (sebelum baris /help)
arm = "            '/atm', '/saldoatm', '/tarik', '/setor', '/transfer', '/laporanatm', '/batalatm' => $this->reply($store, $chatId, app(\\App\\Services\\MiniAtmBot::class)->handle($store, $command, $arg)),\n"
m = re.search(r"^(\s*)'/help', '/menu' =>", s, re.M)
if not m: sys.exit("Baris  '/help', '/menu' =>  tidak ditemukan; tidak ada yang diubah.")
s = s[:m.start()] + arm + s[m.start():]

# 2. teks /help
h = re.search(r"(\"/hutang — [^\n]*\\n\"\.\n)", s)
if h:
    s = s[:h.end()] + "            \\App\\Services\\MiniAtmBot::helpLines().\"\\n\".\n" + s[h.end():]
    hm = 'ditambahkan'
else:
    hm = 'TIDAK ditemukan (perintah tetap jalan, hanya belum tampil di /help)'
open(p, 'w', encoding='utf-8').write(s)
print('OK: rute perintah dipasang; teks /help', hm)
