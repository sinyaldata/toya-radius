#!/usr/bin/env bash
#
# Pasang pesan "voucher kadaluarsa" di FreeRADIUS untuk voucher yang tanggalnya
# BARU SAJA lewat dan belum sempat diproses tugas terjadwal vouchers:mark-expired
# (jeda sampai 15 menit).
#
# Voucher yang SUDAH diproses tugas terjadwal tidak butuh skrip ini -- pesannya
# ikut tertulis di database oleh aplikasi (FreeRadiusService::expireVoucher).
#
# Yang dilakukan skrip ini pada /etc/freeradius/3.0/sites-available/default:
#   - mencadangkan file dulu,
#   - mengganti SATU baris "expiration" di bagian authorize { } dengan blok yang
#     menambahkan Reply-Message kalau modul expiration menolak login,
#   - memeriksa sintaks konfigurasi; kalau gagal -> file DIKEMBALIKAN otomatis,
#   - baru me-restart FreeRADIUS.
#
# Pemakaian:
#   sudo bash pasang-pesan-expired-freeradius.sh              # pasang
#   sudo bash pasang-pesan-expired-freeradius.sh --kembalikan # batalkan (pakai cadangan terakhir)
#
# Opsional (jarang perlu): ganti teks pesan
#   sudo PESAN="Voucher habis. Beli lagi di outlet." bash pasang-pesan-expired-freeradius.sh
#
set -euo pipefail

FR_DIR="${FR_DIR:-/etc/freeradius/3.0}"
SITE="${SITE:-$FR_DIR/sites-available/default}"
PESAN="${PESAN:-Voucher Anda sudah kadaluarsa (expired). Silakan beli voucher baru di outlet terdekat agar bisa internetan dengan lancar.}"
RESTART="${FR_RESTART:-systemctl restart freeradius}"
CEK="${FR_CHECK:-freeradius -Cx -lstdout -d $FR_DIR}"
PENANDA="PESAN-VOUCHER-KADALUARSA (Toya Radius)"

pesan() { printf '%s\n' "$*"; }
gagal() { printf 'GAGAL: %s\n' "$*" >&2; exit 1; }

[ -f "$SITE" ] || gagal "berkas $SITE tidak ditemukan (atur FR_DIR / SITE kalau lokasinya beda)."

cadangan_terakhir() { ls -1t "$SITE".bak-pesan-* 2>/dev/null | head -n 1 || true; }

if [ "${1:-}" = "--kembalikan" ]; then
    CAD="$(cadangan_terakhir)"
    [ -n "$CAD" ] || gagal "tidak ada cadangan ($SITE.bak-pesan-*) untuk dikembalikan."
    cp -a "$CAD" "$SITE"
    bash -c "$RESTART"
    pesan "Dikembalikan dari $CAD dan FreeRADIUS di-restart."
    exit 0
fi

# Teks pesan masuk ke string konfigurasi -> tolak karakter yang merusaknya.
case "$PESAN" in
    *\"*|*\\*|*%*) gagal 'PESAN tidak boleh memuat tanda kutip ganda ("), garis miring terbalik (\) atau persen (%).' ;;
esac

if grep -q "$PENANDA" "$SITE"; then
    # Versi pertama memakai ":=" yang MENIMPA pesan rinci dari database. Perbarui ke "=".
    if awk -v m="$PENANDA" 'index($0, m) { f = 1 } f && /Reply-Message[ \t]*:=/ { print "lama"; exit }' "$SITE" | grep -q lama; then
        CAD="$SITE.bak-pesan-$(date +%Y%m%d%H%M%S)"
        cp -a "$SITE" "$CAD"
        pesan "Pemasangan versi lama terdeteksi (operator := menimpa pesan rinci). Cadangan: $CAD"
        SITE="$SITE" PENANDA="$PENANDA" python3 - << 'PYEOF'
import os, re
p, penanda = os.environ['SITE'], os.environ['PENANDA']
baris = open(p, encoding='utf-8').read().split('\n')
mulai = next(i for i, b in enumerate(baris) if penanda in b)
for i in range(mulai, min(mulai + 20, len(baris))):
    if re.match(r'^\s*Reply-Message\s*:=', baris[i]):
        baris[i] = re.sub(r'Reply-Message\s*:=', 'Reply-Message =', baris[i], count=1)
        baris.insert(mulai + 1, re.match(r'^(\s*)', baris[mulai]).group(1) + '# (diperbarui: operator "=" agar pesan rinci dari database tidak tertimpa)')
        break
open(p, 'w', encoding='utf-8').write('\n'.join(baris))
PYEOF
        pulihkan() { cp -a "$CAD" "$SITE"; pesan "Konfigurasi dikembalikan dari cadangan."; }
        if ! HASIL="$(bash -c "$CEK" 2>&1)"; then
            printf '%s\n' "$HASIL" | tail -n 8 >&2; pulihkan; gagal "pemeriksaan sintaks gagal. Konfigurasi dikembalikan, FreeRADIUS tidak di-restart."
        fi
        if ! bash -c "$RESTART"; then
            pulihkan; bash -c "$RESTART" || true; gagal "FreeRADIUS gagal di-restart. Dikembalikan ke konfigurasi lama."
        fi
        pesan "Diperbarui: pesan rinci per voucher sekarang TIDAK lagi tertimpa. FreeRADIUS sudah di-restart."
        exit 0
    fi
    pesan "Sudah terpasang sebelumnya (versi terbaru, penanda ditemukan di $SITE). Tidak ada yang diubah."
    exit 0
fi

CAD="$SITE.bak-pesan-$(date +%Y%m%d%H%M%S)"
cp -a "$SITE" "$CAD"
pesan "Cadangan: $CAD"

pulihkan() { cp -a "$CAD" "$SITE"; pesan "Konfigurasi dikembalikan dari cadangan."; }

# Ganti persis SATU baris 'expiration' di dalam authorize { }.
if ! SITE="$SITE" PESAN="$PESAN" PENANDA="$PENANDA" python3 - << 'PYEOF'
import os, re, sys
p, pesan, penanda = os.environ['SITE'], os.environ['PESAN'], os.environ['PENANDA']
baris = open(p, encoding='utf-8').read().split('\n')

mulai = next((i for i, b in enumerate(baris) if re.match(r'^authorize\s*\{', b)), None)
if mulai is None:
    sys.exit('bagian "authorize {" tidak ditemukan di berkas ini.')
akhir = next((i for i in range(mulai + 1, len(baris)) if baris[i].startswith('}')), None)
if akhir is None:
    sys.exit('akhir bagian authorize tidak ditemukan.')

cocok = [i for i in range(mulai, akhir) if re.match(r'^\s*expiration\s*$', baris[i])]
if len(cocok) != 1:
    sys.exit(f'ditemukan {len(cocok)} baris "expiration" berdiri sendiri di authorize (harus tepat 1). Tidak diubah.')

i = cocok[0]
indent = re.match(r'^(\s*)', baris[i]).group(1)
blok = [
    f'{indent}# {penanda}: modul expiration saja menolak TANPA pesan.',
    f'{indent}# Operator "=" (bukan ":="): pesan ini HANYA ditambahkan bila database belum',
    f'{indent}# menyediakan Reply-Message -- supaya pesan rinci per voucher (paket, aktif,',
    f'{indent}# kadaluarsa) dari radreply tidak tertimpa.',
    f'{indent}expiration {{',
    f'{indent}\tuserlock = 1',
    f'{indent}}}',
    f'{indent}if (userlock) {{',
    f'{indent}\tupdate reply {{',
    f'{indent}\t\tReply-Message = "{pesan}"',
    f'{indent}\t}}',
    f'{indent}\treject',
    f'{indent}}}',
]
baris[i:i + 1] = blok
open(p, 'w', encoding='utf-8').write('\n'.join(baris))
PYEOF
then
    pulihkan
    gagal "tidak bisa menyisipkan blok (lihat pesan di atas). Konfigurasi tidak berubah."
fi

if ! HASIL="$(bash -c "$CEK" 2>&1)"; then
    pesan "$HASIL" | tail -n 8
    pulihkan
    gagal "pemeriksaan sintaks gagal. Konfigurasi dikembalikan, FreeRADIUS tidak di-restart."
fi

if ! bash -c "$RESTART"; then
    pulihkan
    bash -c "$RESTART" || true
    gagal "FreeRADIUS gagal di-restart dengan konfigurasi baru. Dikembalikan ke konfigurasi lama."
fi

pesan "Selesai. Pesan kadaluarsa terpasang dan FreeRADIUS sudah di-restart."
pesan "Batalkan kapan saja dengan: sudo bash $0 --kembalikan"
