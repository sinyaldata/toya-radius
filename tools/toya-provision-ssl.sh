#!/bin/bash
# toya-provision-ssl.sh (v2, 2026-10-03) -- terbitkan/perluas sertifikat SSL
# Let's Encrypt untuk satu subdomain tenant baru, dan daftarkan subdomain itu ke
# server_name Nginx supaya certbot bisa memasangnya otomatis.
#
# Dipanggil otomatis oleh aplikasi (TenantProvisioningService::provisionSsl())
# lewat: sudo /usr/local/bin/toya-provision-ssl.sh <subdomain>
# Tambahan untuk pemeriksaan:  ... <subdomain> --dry-run   (tidak mengubah apa pun)
#
# Domain utama diambil dari APP_URL di .env aplikasi (tidak di-hardcode).
#
# PERBAIKAN v2 (kasus nyata: tenant "suela" gagal HTTPS padahal "pkmsuela" ada):
#  1. Pencocokan NAMA UTUH. Versi lama memakai `grep "suela.domain"` -- yang juga
#     cocok dengan "pkmsuela.domain" -- sehingga "suela" dikira sudah terdaftar dan
#     tidak pernah ditambahkan ke nginx maupun sertifikat.
#  2. Bekerja pada berkas nginx yang BENAR-BENAR dipakai (sites-enabled, dengan
#     symlink diikuti). Versi lama mengedit sites-available; kalau kedua berkas itu
#     berbeda, certbot (yang membaca sites-enabled) tidak menemukan server block
#     nama-nama baru ("Could not automatically find a matching server block").
#  3. Daftar domain ke certbot = gabungan: nama di nginx + nama yang SUDAH ada di
#     sertifikat + subdomain baru. Dengan --expand certbot mengganti seluruh daftar
#     sertifikat, jadi tidak boleh ada nama sertifikat yang terlewat.
#  4. Nama yang hilang dari server_name ditambahkan kembali ke SETIAP baris
#     server_name milik aplikasi (blok 443 dan 80) -- baris milik blok lain
#     (mis. halaman isolir) tidak disentuh.
#  5. Dicadangkan, diperiksa `nginx -t`, dikembalikan otomatis bila gagal, dan hasil
#     akhirnya DIVERIFIKASI (tidak lagi mencetak "sudah HTTPS" tanpa bukti).
#
# Nama yang sudah tidak dipakai (tenant yang dihapus) TIDAK dibuang di sini --
# gunakan toya-deprovision-ssl.sh untuk itu.

set -euo pipefail

SUBDOMAIN="${1:-}"
MODE="${2:-}"

APP_DIR="${APP_DIR:-/var/www/toya-radius}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"
NGINX_ENABLED="${NGINX_ENABLED:-/etc/nginx/sites-enabled/toya-radius}"
NGINX_AVAILABLE="${NGINX_AVAILABLE:-/etc/nginx/sites-available/toya-radius}"
CERTBOT="${CERTBOT_BIN:-certbot}"
NGINX_TEST="${NGINX_TEST:-nginx -t}"
NGINX_RELOAD="${NGINX_RELOAD:-systemctl reload nginx}"
KUNCI="${KUNCI_BERKAS:-/var/lock/toya-ssl.lock}"

pesan() { printf '%s\n' "$*"; }
gagal() { printf 'GAGAL: %s\n' "$*" >&2; exit 1; }

[ -n "$SUBDOMAIN" ] || gagal "Pemakaian: $0 <subdomain> [--dry-run]"
[[ "$SUBDOMAIN" =~ ^[a-z0-9]([a-z0-9-]{0,60}[a-z0-9])?$ ]] || gagal "subdomain \"$SUBDOMAIN\" tidak valid (huruf kecil, angka, tanda minus)."
case "$SUBDOMAIN" in
    www|isolir|mail|ftp|admin|api) gagal "\"$SUBDOMAIN\" adalah nama yang dilindungi, tidak boleh dipasang sebagai tenant." ;;
esac

[ -f "$ENV_FILE" ] || gagal "Tidak menemukan .env aplikasi di $ENV_FILE"

APP_URL=$(grep -E '^APP_URL=' "$ENV_FILE" | head -1 | cut -d '=' -f2- | tr -d '"' | tr -d "'")
BASE_DOMAIN=$(echo "$APP_URL" | sed -E 's#^https?://##; s#/.*$##')
[ -n "$BASE_DOMAIN" ] || gagal "Gagal membaca domain utama dari APP_URL di .env"

FULL_DOMAIN="${SUBDOMAIN}.${BASE_DOMAIN}"
pesan "Domain utama : $BASE_DOMAIN"
pesan "Subdomain    : $FULL_DOMAIN"

# Berkas nginx yang SEBENARNYA dibaca nginx: sites-enabled (symlink diikuti).
if [ -e "$NGINX_ENABLED" ]; then
    LIVE="$(readlink -f "$NGINX_ENABLED")"
elif [ -e "$NGINX_AVAILABLE" ]; then
    LIVE="$(readlink -f "$NGINX_AVAILABLE")"
else
    gagal "berkas nginx tidak ditemukan ($NGINX_ENABLED / $NGINX_AVAILABLE)."
fi
pesan "Berkas nginx : $LIVE"
if [ -e "$NGINX_ENABLED" ] && [ -e "$NGINX_AVAILABLE" ] && [ "$(readlink -f "$NGINX_ENABLED")" != "$(readlink -f "$NGINX_AVAILABLE")" ]; then
    pesan "PERINGATAN    : sites-enabled dan sites-available BUKAN berkas yang sama. Yang dipakai nginx (dan skrip ini): $LIVE"
fi

# Jangan balapan dengan proses lain yang mengubah nginx/sertifikat lewat skrip.
exec 9>"$KUNCI"
flock -w 120 9 || gagal "skrip lain sedang berjalan (kunci $KUNCI)."

PLAN_DIR="$(mktemp -d)"
trap 'rm -rf "$PLAN_DIR"' EXIT

# ---- Rencana: gabungan nama nginx + sertifikat + subdomain baru --------------
if ! LIVE="$LIVE" BASE="$BASE_DOMAIN" FULL="$FULL_DOMAIN" CERTBOT="$CERTBOT" PLAN_DIR="$PLAN_DIR" python3 - << 'PYEOF' > "$PLAN_DIR/rencana.txt"
import os, re, subprocess, sys
live, base, full, certbot, plan = os.environ['LIVE'], os.environ['BASE'], os.environ['FULL'], os.environ['CERTBOT'], os.environ['PLAN_DIR']
teks = open(live, encoding='utf-8').read()

def galat(pesan):
    print('GALAT\t' + pesan); sys.exit(0)

milik_app = lambda d: d == base or d.endswith('.' + base)

# Sertifikat yang dipakai nginx (dibaca dari ssl_certificate, bukan ditebak).
nama_cert = sorted(set(re.findall(r'/live/([^/\s;]+)/', teks)))
if len(nama_cert) > 1:
    galat('nginx memakai lebih dari satu sertifikat (' + ', '.join(nama_cert) + ') -- periksa manual.')
cert = nama_cert[0] if nama_cert else ''
domain_cert = []
if cert:
    try:
        keluar = subprocess.run(certbot.split() + ['certificates'], capture_output=True, text=True, timeout=120).stdout
    except Exception as e:
        galat('tidak bisa menjalankan certbot: ' + str(e))
    for blok in re.split(r'\n\s*(?=Certificate Name:)', keluar):
        m = re.search(r'Certificate Name:\s*(\S+)', blok); d = re.search(r'Domains:\s*(.+)', blok)
        if m and m.group(1) == cert and d:
            domain_cert = d.group(1).split()
    if not domain_cert:
        galat(f'sertifikat "{cert}" dipakai nginx tetapi tidak ditemukan di "certbot certificates".')

# Baris server_name MILIK APLIKASI = yang memuat domain utama sebagai token utuh.
pola = re.compile(r'(^[ \t]*server_name[ \t]+)([^;]+)(;)', re.M)
baris_app = [m for m in pola.finditer(teks) if base in m.group(2).split()]
if not baris_app:
    galat(f'tidak ada baris server_name yang memuat {base} di {live}.')

domain_nginx = set()
for m in baris_app:
    domain_nginx |= {t for t in m.group(2).split() if t != '_' and milik_app(t)}

semua = sorted((domain_nginx | {d for d in domain_cert if milik_app(d)} | {full}))

# Tambahkan nama yang kurang ke SETIAP baris server_name milik aplikasi.
tambahan = set()
def lengkapi(m):
    token = m.group(2).split()
    kurang = [d for d in semua if d not in token]
    tambahan.update(kurang)
    return m.group(1) + ' '.join(token + kurang) + m.group(3) if kurang else m.group(0)
baru = pola.sub(lambda m: lengkapi(m) if base in m.group(2).split() else m.group(0), teks)

berubah = baru != teks
if berubah:
    open(os.path.join(plan, 'nginx.baru'), 'w', encoding='utf-8').write(baru)

print('CERT\t' + cert)
print('BERUBAH\t' + ('1' if berubah else '0'))
print('TAMBAHAN\t' + ' '.join(sorted(tambahan)))
print('CERT_LENGKAP\t' + ('1' if cert and set(semua) <= set(domain_cert) else '0'))
print('DOMAIN\t' + '\t'.join(semua))
PYEOF
then
    gagal "gagal menyusun rencana (lihat pesan di atas)."
fi

PLAN_GALAT="$(grep -P '^GALAT\t' "$PLAN_DIR/rencana.txt" | cut -f2- || true)"
[ -z "$PLAN_GALAT" ] || gagal "$PLAN_GALAT"

ambil() { grep -P "^$1\t" "$PLAN_DIR/rencana.txt" | cut -f2- ; }
CERT_NAME="$(ambil CERT || true)"
BERUBAH="$(ambil BERUBAH)"
TAMBAHAN="$(ambil TAMBAHAN || true)"
CERT_LENGKAP="$(ambil CERT_LENGKAP)"
mapfile -t ALL_DOMAINS < <(ambil DOMAIN | tr '\t' '\n')

pesan "Daftar domain yang akan dikirim ke certbot (${#ALL_DOMAINS[@]}):"
printf '  - %s\n' "${ALL_DOMAINS[@]}"
if [ "$BERUBAH" = 1 ]; then
    pesan "Ditambahkan ke server_name nginx: $TAMBAHAN"
else
    pesan "server_name nginx sudah lengkap."
fi

if [ "$MODE" = "--dry-run" ]; then
    [ "$CERT_LENGKAP" = 1 ] && pesan "Sertifikat sudah memuat semua domain di atas." || pesan "Sertifikat akan diperluas."
    pesan "Simulasi selesai, TIDAK ada yang diubah."
    exit 0
fi

# ---- 1. nginx: cadangkan, ganti, uji, muat ulang (dikembalikan bila gagal) ----
if [ "$BERUBAH" = 1 ]; then
    CAD="$LIVE.bak-pasang-$(date +%Y%m%d%H%M%S)"
    cp -a "$LIVE" "$CAD"
    pesan "Cadangan nginx: $CAD"
    cat "$PLAN_DIR/nginx.baru" > "$LIVE"

    if ! HASIL="$(bash -c "$NGINX_TEST" 2>&1)"; then
        printf '%s\n' "$HASIL" | tail -n 6 >&2
        cat "$CAD" > "$LIVE"
        gagal "nginx -t gagal; konfigurasi DIKEMBALIKAN dari cadangan."
    fi
    if ! bash -c "$NGINX_RELOAD"; then
        cat "$CAD" > "$LIVE"
        bash -c "$NGINX_RELOAD" || true
        gagal "reload nginx gagal; konfigurasi DIKEMBALIKAN dari cadangan."
    fi
    pesan "nginx diperbarui dan di-reload."
fi

# ---- 2. sertifikat ------------------------------------------------------------
dom_cert() {
    $CERTBOT certificates 2>/dev/null | awk -v n="$1" -v f="$FULL_DOMAIN" '
        /Certificate Name:/ { nm = $3 }
        /Domains:/ { sub(/.*Domains:[ \t]*/, ""); if ((n != "" && nm == n) || (n == "" && index(" " $0 " ", " " f " "))) { print; exit } }'
}

PERINGATAN_PASANG=""

if [ "$CERT_LENGKAP" = 1 ]; then
    pesan "Sertifikat \"$CERT_NAME\" sudah memuat semua domain -- tidak perlu diterbitkan ulang."
else
    DOMAIN_ARGS=()
    for d in "${ALL_DOMAINS[@]}"; do DOMAIN_ARGS+=(-d "$d"); done

    RC=0
    if [ -z "$CERT_NAME" ]; then
        pesan "Belum ada sertifikat aktif untuk $BASE_DOMAIN -- membuat baru mencakup SEMUA domain di atas..."
        $CERTBOT --nginx --non-interactive --agree-tos --redirect "${DOMAIN_ARGS[@]}" || RC=$?
    else
        pesan "Memperluas sertifikat \"$CERT_NAME\" supaya mencakup SEMUA domain di atas..."
        $CERTBOT --nginx --non-interactive --agree-tos --redirect \
            --cert-name "$CERT_NAME" --expand "${DOMAIN_ARGS[@]}" || RC=$?
    fi

    if [ "$RC" != 0 ]; then
        # Kasus nyata: certbot MENYIMPAN sertifikat baru tetapi gagal MEMASANGNYA ke
        # salah satu server block ("The certificate was saved, but could not be
        # installed"). nginx membaca sertifikat lewat jalur live/<nama>/ yang
        # diperbarui di tempat, jadi sertifikat barunya langsung dipakai begitu
        # nginx di-reload. Periksa dulu apakah sertifikatnya memang sudah lengkap.
        SETELAH_GALAT="$(dom_cert "$CERT_NAME")"
        LENGKAP=1
        for d in "${ALL_DOMAINS[@]}"; do
            printf '%s\n' "$SETELAH_GALAT" | tr ' ' '\n' | grep -qxF "$d" || LENGKAP=0
        done

        if [ -n "$SETELAH_GALAT" ] && [ "$LENGKAP" = 1 ]; then
            PERINGATAN_PASANG="certbot melaporkan galat saat MEMASANG sertifikat ke nginx (kode $RC), tetapi sertifikat BARU sudah tersimpan dan memuat semua domain. nginx di-reload supaya sertifikat baru dipakai. Periksa baris galat certbot di atas (biasanya ada server block yang tidak cocok dengan salah satu nama)."
            pesan "PERINGATAN: $PERINGATAN_PASANG"
            bash -c "$NGINX_RELOAD" || gagal "reload nginx gagal setelah galat pemasangan certbot."
        elif [ -z "$CERT_NAME" ]; then
            gagal "certbot gagal membuat sertifikat. Nama sudah ada di nginx; jalankan ulang skrip ini setelah penyebabnya diperbaiki."
        else
            gagal "certbot gagal memperluas sertifikat (sertifikat lama tetap utuh). Jalankan ulang skrip ini setelah penyebabnya diperbaiki."
        fi
    fi
fi

# ---- 3. Verifikasi: semua nama ada di sertifikat --------------------------------
SESUDAH="$(dom_cert "$CERT_NAME")"
[ -n "$SESUDAH" ] || gagal "verifikasi: sertifikat yang memuat $FULL_DOMAIN tidak ditemukan. Periksa: sudo certbot certificates"
for d in "${ALL_DOMAINS[@]}"; do
    printf '%s\n' "$SESUDAH" | tr ' ' '\n' | grep -qxF "$d" || gagal "verifikasi: $d TIDAK ada di sertifikat. Periksa: sudo certbot certificates"
done

pesan "Selesai -- $FULL_DOMAIN sudah ada di sertifikat dan nginx (diverifikasi)."
[ -z "$PERINGATAN_PASANG" ] || pesan "Catatan: ada galat pemasangan certbot (lihat PERINGATAN di atas) -- sertifikat sudah dipakai, tetapi sebaiknya diperiksa."
