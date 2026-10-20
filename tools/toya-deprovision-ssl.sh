#!/usr/bin/env bash
#
# toya-deprovision-ssl.sh <subdomain> [--dry-run]
#
# Kebalikan dari toya-provision-ssl.sh: keluarkan subdomain tenant yang SUDAH
# DIHAPUS dari
#   1. sertifikat Let's Encrypt bersama (daftar domain / SAN), dan
#   2. nginx (baris server_name + blok redirect "managed by Certbot").
#
# URUTAN DISENGAJA -- sertifikat DULU, baru nginx:
#   Kalau nginx dibersihkan tapi sertifikat masih memuat nama itu, pembaruan
#   otomatis (certbot renew) akan GAGAL memvalidasi nama yang sudah tidak ada
#   di nginx -- dan itu menggagalkan pembaruan SEMUA domain di sertifikat yang
#   sama. Dengan urutan ini: kalau sertifikat gagal, nginx tidak disentuh
#   (tidak ada yang rusak); kalau nginx gagal setelahnya, nama itu cuma masih
#   tertulis di nginx (tidak berbahaya) dan skrip bisa dijalankan ulang.
#
# Aman dijalankan berulang (idempoten). Perubahan nginx dicadangkan dulu,
# diperiksa dengan `nginx -t`, dan dikembalikan otomatis kalau gagal.
#
# Pemakaian:
#   sudo /usr/local/bin/toya-deprovision-ssl.sh ahlamraya --dry-run   # lihat rencana
#   sudo /usr/local/bin/toya-deprovision-ssl.sh ahlamraya             # jalankan
#
set -euo pipefail

SUB="${1:-}"
MODE="${2:-}"
NGINX_ENABLED="${NGINX_ENABLED:-/etc/nginx/sites-enabled/toya-radius}"
NGINX_AVAILABLE="${NGINX_AVAILABLE:-/etc/nginx/sites-available/toya-radius}"
# Berkas yang SEBENARNYA dibaca nginx = sites-enabled (symlink diikuti). sites-available
# bisa saja berkas lain (salinan) -- mengedit yang itu tidak mengubah apa pun di nginx.
if [ -z "${NGINX_SITE:-}" ]; then
    if [ -e "$NGINX_ENABLED" ]; then NGINX_SITE="$(readlink -f "$NGINX_ENABLED")"; else NGINX_SITE="$(readlink -f "$NGINX_AVAILABLE")"; fi
fi
CERTBOT="${CERTBOT_BIN:-certbot}"
NGINX_TEST="${NGINX_TEST:-nginx -t}"
NGINX_RELOAD="${NGINX_RELOAD:-systemctl reload nginx}"
KUNCI="${KUNCI_BERKAS:-/var/lock/toya-ssl.lock}"

pesan() { printf '%s\n' "$*"; }
gagal() { printf 'GAGAL: %s\n' "$*" >&2; exit 1; }

[ -n "$SUB" ] || gagal "pemakaian: $0 <subdomain> [--dry-run]"
[[ "$SUB" =~ ^[a-z0-9]([a-z0-9-]{0,60}[a-z0-9])?$ ]] || gagal "subdomain \"$SUB\" tidak valid (huruf kecil, angka, tanda minus)."
case "$SUB" in
    www|isolir|mail|ftp|admin|api) gagal "\"$SUB\" adalah nama yang dilindungi, tidak boleh dihapus lewat skrip ini." ;;
esac
[ -f "$NGINX_SITE" ] || gagal "berkas nginx $NGINX_SITE tidak ditemukan (atur NGINX_SITE kalau lokasinya beda)."
if [ -e "$NGINX_ENABLED" ] && [ -e "$NGINX_AVAILABLE" ] && [ "$(readlink -f "$NGINX_ENABLED")" != "$(readlink -f "$NGINX_AVAILABLE")" ]; then
    pesan "PERINGATAN: sites-enabled dan sites-available BUKAN berkas yang sama. Yang dipakai (dan diubah) skrip ini: $NGINX_SITE"
fi

# Jangan balapan dengan proses lain yang sedang mengubah sertifikat/nginx lewat skrip ini.
exec 9>"$KUNCI"
flock -w 120 9 || gagal "skrip lain sedang berjalan (kunci $KUNCI)."

# ---- 1. Cari sertifikat yang memuat <sub>.<apex> ----------------------------
INFO="$(SUB="$SUB" CERTBOT="$CERTBOT" NGINX_SITE="$NGINX_SITE" python3 - << 'PYEOF'
import os, re, subprocess, sys
sub = os.environ['SUB']
try:
    keluar = subprocess.run(os.environ['CERTBOT'].split() + ['certificates'], capture_output=True, text=True, timeout=120).stdout
except Exception as e:
    print('GALAT\t' + str(e)); sys.exit(0)

def apex_dari(domain):
    kandidat = [x for x in domain if all(y == x or y.endswith('.' + x) for y in domain)]
    return kandidat[0] if len(kandidat) == 1 else None

sertifikat = {}
for blok in re.split(r'\n\s*(?=Certificate Name:)', keluar):
    m = re.search(r'Certificate Name:\s*(\S+)', blok); d = re.search(r'Domains:\s*(.+)', blok)
    if m and d: sertifikat[m.group(1)] = d.group(1).split()

def hasil(nama):
    domain = sertifikat.get(nama)
    if domain is None:
        print(f'GALAT\tsertifikat "{nama}" dipakai nginx tetapi tidak ada di keluaran "certbot certificates".'); sys.exit(0)
    apex = apex_dari(domain)
    if not apex:
        print(f'GALAT\tdomain utama (apex) sertifikat "{nama}" tidak bisa ditentukan.'); sys.exit(0)
    full = f'{sub}.{apex}'
    if full not in domain:
        print('TIDAK-ADA\t' + apex); sys.exit(0)
    sisa = [x for x in domain if x != full]
    if apex not in sisa:
        print('GALAT\tdomain utama akan ikut hilang -- dibatalkan demi keamanan.'); sys.exit(0)
    print('ADA\t' + '\t'.join([nama, apex, full] + sisa)); sys.exit(0)

# Sertifikat DIPASTIKAN dari nginx (ssl_certificate .../live/<nama>/...), bukan ditebak:
# di server bisa ada beberapa sertifikat (mis. sisa lama seperti "<domain>-0001").
dipakai = set(re.findall(r'/live/([^/\s;]+)/', open(os.environ['NGINX_SITE'], encoding='utf-8').read()))
if len(dipakai) == 1:
    hasil(next(iter(dipakai)))

# Cadangan: nginx tidak memakai jalur certbot baku -> cari sertifikat yang memuat <sub>.<apex>.
cocok = []
for nama, domain in sertifikat.items():
    apex = apex_dari(domain)
    if apex and f'{sub}.{apex}' in domain: cocok.append(nama)
if len(cocok) == 1:
    hasil(cocok[0])
if len(cocok) > 1:
    print('GALAT\tsubdomain ini ada di lebih dari satu sertifikat dan nginx tidak menunjuk satu pun secara jelas -- periksa manual.'); sys.exit(0)
semua_apex = {apex_dari(d) for d in sertifikat.values()} - {None}
print('TIDAK-ADA\t' + (next(iter(semua_apex)) if len(semua_apex) == 1 else ''))
PYEOF
)"

STATUS="${INFO%%$'\t'*}"
case "$STATUS" in
    GALAT) gagal "${INFO#*$'\t'}" ;;
    ADA)
        IFS=$'\t' read -r _ CERT_NAME APEX FULL _ <<< "$INFO"
        mapfile -t DOMAIN_SISA < <(printf '%s' "$INFO" | cut -f5- | tr '\t' '\n')
        ;;
    TIDAK-ADA)
        APEX="$(printf '%s' "$INFO" | cut -f2)"
        [ -n "$APEX" ] || gagal "tidak bisa menentukan domain utama dari certbot (tidak ada atau lebih dari satu sertifikat)."
        FULL="$SUB.$APEX"; CERT_NAME=""; DOMAIN_SISA=()
        ;;
    *) gagal "keluaran certbot tidak dikenali." ;;
esac

# Cocokkan sebagai NAMA UTUH (batas kata) -- bukan potongan teks: "alfa.domain" tidak
# boleh cocok dengan "xalfa.domain" atau "alfa.domain.lain".
DI_NGINX=0
ESC="${FULL//./\\.}"
if grep -qE "(^|[^A-Za-z0-9.-])${ESC}([^A-Za-z0-9.-]|$)" "$NGINX_SITE"; then DI_NGINX=1; fi
DI_CERT=0; [ "$STATUS" = "ADA" ] && DI_CERT=1

if [ "$DI_NGINX" = 0 ] && [ "$DI_CERT" = 0 ]; then
    pesan "$FULL tidak ada di sertifikat maupun di $NGINX_SITE. Tidak ada yang perlu dihapus."
    exit 0
fi

pesan "Subdomain      : $FULL"
if [ "$DI_CERT" = 1 ]; then
    pesan "Sertifikat     : $CERT_NAME -> domain yang TETAP (${#DOMAIN_SISA[@]}): ${DOMAIN_SISA[*]}"
else
    pesan "Sertifikat     : sudah tidak memuat $FULL"
fi
if [ "$DI_NGINX" = 1 ]; then
    pesan "nginx          : $FULL akan dikeluarkan dari server_name$(grep -cF "if (\$host = $FULL)" "$NGINX_SITE" | awk '$1>0{print " dan " $1 " blok redirect Certbot"}')"
else
    pesan "nginx          : sudah tidak memuat $FULL"
fi

if [ "$MODE" = "--dry-run" ]; then
    pesan "Simulasi selesai, TIDAK ada yang diubah."
    exit 0
fi

# ---- 2. Sertifikat dulu ----------------------------------------------------
if [ "$DI_CERT" = 1 ]; then
    ARGS=()
    for d in "${DOMAIN_SISA[@]}"; do ARGS+=(-d "$d"); done
    pesan "Menerbitkan ulang sertifikat tanpa $FULL ..."
    if ! $CERTBOT --nginx --cert-name "$CERT_NAME" --expand --non-interactive "${ARGS[@]}" >/tmp/toya-deprovision-certbot.log 2>&1; then
        tail -n 8 /tmp/toya-deprovision-certbot.log >&2 || true
        gagal "certbot gagal. nginx TIDAK diubah (semuanya masih seperti semula). Log: /tmp/toya-deprovision-certbot.log"
    fi

    # Verifikasi: nama target hilang DAN semua nama lain masih ada.
    SESUDAH="$($CERTBOT certificates 2>/dev/null | awk -v c="$CERT_NAME" '$0 ~ "Certificate Name: "c"$"{f=1} f&&/Domains:/{print; exit}')"
    for d in "${DOMAIN_SISA[@]}"; do
        printf '%s\n' "$SESUDAH" | tr ' ' '\n' | grep -qxF "$d" || gagal "verifikasi: $d hilang dari sertifikat setelah diterbitkan ulang! Periksa: sudo certbot certificates"
    done
    if printf '%s\n' "$SESUDAH" | tr ' ' '\n' | grep -qxF "$FULL"; then
        gagal "verifikasi: $FULL masih ada di sertifikat. nginx TIDAK diubah."
    fi
    pesan "Sertifikat sudah diperbarui dan diverifikasi."
fi

# ---- 3. Lalu nginx ----------------------------------------------------------
if [ "$DI_NGINX" = 1 ]; then
    CAD="$NGINX_SITE.bak-hapus-$(date +%Y%m%d%H%M%S)"
    cp -a "$NGINX_SITE" "$CAD"
    pesan "Cadangan nginx : $CAD"

    if ! NGINX_SITE="$NGINX_SITE" FULL="$FULL" python3 - << 'PYEOF'
import os, re, sys
p, full = os.environ['NGINX_SITE'], os.environ['FULL']
teks = open(p, encoding='utf-8').read()

# (a) keluarkan nama dari SETIAP baris server_name (token utuh, bukan potongan nama lain)
def bersihkan(m):
    token = [t for t in m.group(2).split() if t != full]
    if not token:                      # jangan sampai server_name kosong
        return m.group(0)
    return m.group(1) + ' '.join(token) + ';'
teks2 = re.sub(r'(^[ \t]*server_name[ \t]+)([^;]+);', bersihkan, teks, flags=re.M)

# (b) buang blok redirect buatan Certbot untuk nama ini:
#     if ($host = full) { return 301 https://$host$request_uri; } # managed by Certbot
teks2 = re.sub(r'\n?[ \t]*if \(\$host = ' + re.escape(full) + r'\) \{[^{}]*\} # managed by Certbot[ \t]*(?=\n)', '', teks2)

if teks2 != teks:
    open(p, 'w', encoding='utf-8').write(teks2)
PYEOF
    then
        cp -a "$CAD" "$NGINX_SITE"
        gagal "gagal mengubah $NGINX_SITE; dikembalikan dari cadangan. Sertifikat sudah bersih, nginx masih memuat $FULL (tidak berbahaya)."
    fi

    if cmp -s "$CAD" "$NGINX_SITE"; then
        rm -f "$CAD"
        pesan "nginx          : tidak ada baris server_name / blok redirect Certbot yang memuat $FULL (mungkin hanya di komentar) -- tidak ada yang diubah."
        pesan "Selesai: $FULL sudah dikeluarkan dari sertifikat."
        exit 0
    fi

    if ! HASIL="$(bash -c "$NGINX_TEST" 2>&1)"; then
        printf '%s\n' "$HASIL" | tail -n 6 >&2
        cp -a "$CAD" "$NGINX_SITE"
        gagal "nginx -t gagal; konfigurasi DIKEMBALIKAN. Sertifikat sudah bersih, nginx masih memuat $FULL (tidak berbahaya)."
    fi

    if ! bash -c "$NGINX_RELOAD"; then
        cp -a "$CAD" "$NGINX_SITE"
        bash -c "$NGINX_RELOAD" || true
        gagal "reload nginx gagal; konfigurasi DIKEMBALIKAN."
    fi
    pesan "nginx sudah dibersihkan dan di-reload."
fi

pesan "Selesai: $FULL sudah dikeluarkan dari sertifikat dan nginx."
