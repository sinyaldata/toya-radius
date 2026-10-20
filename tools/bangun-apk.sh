#!/usr/bin/env bash
# =====================================================================
# bangun-apk.sh -- bangun APK Android "Toya Radius" di server ini, lalu
# terbitkan ke halaman unduh  https://<domain-utama>/aplikasi
#
#   bash tools/bangun-apk.sh                       # versi naik otomatis
#   bash tools/bangun-apk.sh "Catatan rilis"        # + catatan untuk pengguna
#   WAJIB=1 bash tools/bangun-apk.sh "..."          # pengguna wajib update
#
# Pertama kali: mengunduh Android SDK (~1 GB) & Gradle, butuh 5-15 menit.
# Berikutnya: 1-3 menit. Jalankan sebagai user aplikasi (mis. deploy).
# Kunci tanda tangan APK disimpan di storage/app/aplikasi-hp/ -- CADANGKAN.
# =====================================================================
set -euo pipefail

SINI="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="${APP_DIR:-/var/www/toya-radius}"
SUMBER="$SINI/aplikasi-hp/toya-radius-app"
KERJA="${KERJA:-$HOME/toya-radius-app}"
SDK="${ANDROID_HOME:-$HOME/android-sdk}"
KUNCI_DIR="$APP_DIR/storage/app/aplikasi-hp"
CATATAN="${1:-}"
WAJIB="${WAJIB:-0}"

langkah() { echo; echo "==> $*"; }
gagal() { echo; echo "GAGAL: $*" >&2; exit 1; }

[ "$(id -u)" = "0" ] && gagal "jangan dijalankan sebagai root. Pakai: sudo -u deploy bash tools/bangun-apk.sh"
[ -d "$SUMBER/android" ] || gagal "folder proyek $SUMBER tidak ada (ekstrak zip-nya dulu)"
[ -f "$APP_DIR/.env" ] || gagal "$APP_DIR/.env tidak ditemukan (set APP_DIR=... kalau folder aplikasi beda)"

# ---------- 1. Java 21 ----------
langkah "Cek Java 21"
for j in /usr/lib/jvm/java-21-openjdk-amd64 /usr/lib/jvm/java-21-openjdk-arm64 /usr/lib/jvm/java-21-openjdk /usr/lib/jvm/temurin-21-jdk-amd64; do
  if [ -x "$j/bin/java" ]; then export JAVA_HOME="$j"; break; fi
done
JAVA="java"; KEYTOOL="keytool"
if [ -n "${JAVA_HOME:-}" ]; then JAVA="$JAVA_HOME/bin/java"; KEYTOOL="$JAVA_HOME/bin/keytool"; export PATH="$JAVA_HOME/bin:$PATH"; fi
command -v "$JAVA" >/dev/null 2>&1 || gagal "Java belum terpasang. Jalankan dulu: sudo apt install -y openjdk-21-jdk-headless unzip"
VJ=$("$JAVA" -version 2>&1 | head -1 | sed -E 's/.*version "([0-9]+).*/\1/')
[ "${VJ:-0}" -ge 21 ] 2>/dev/null || gagal "perlu Java 21 (terpasang: $VJ). Jalankan: sudo apt install -y openjdk-21-jdk-headless"
command -v unzip >/dev/null 2>&1 || gagal "unzip belum ada. Jalankan: sudo apt install -y unzip"
command -v curl >/dev/null 2>&1 || gagal "curl belum ada. Jalankan: sudo apt install -y curl"
echo "Java $VJ OK (${JAVA_HOME:-java di PATH})"

BEBAS=$(df -Pk "$HOME" | awk 'NR==2{print $4}')
[ "${BEBAS:-0}" -gt 4000000 ] || gagal "ruang disk di $HOME kurang dari 4 GB"

# ---------- 2. Android SDK ----------
langkah "Cek Android SDK ($SDK)"
SDKM="$SDK/cmdline-tools/latest/bin/sdkmanager"
if [ ! -x "$SDKM" ]; then
  echo "Mengunduh Android command-line tools..."
  mkdir -p "$SDK/cmdline-tools"
  TMP=$(mktemp -d)
  OK=0
  for v in 13114758 11076708; do
    if curl -fsSL --retry 3 -o "$TMP/ct.zip" "https://dl.google.com/android/repository/commandlinetools-linux-${v}_latest.zip"; then OK=1; break; fi
  done
  [ "$OK" = "1" ] || gagal "tidak bisa mengunduh Android command-line tools dari dl.google.com"
  unzip -q "$TMP/ct.zip" -d "$TMP"
  rm -rf "$SDK/cmdline-tools/latest"
  mv "$TMP/cmdline-tools" "$SDK/cmdline-tools/latest"
  rm -rf "$TMP"
fi
export ANDROID_HOME="$SDK" ANDROID_SDK_ROOT="$SDK"
(yes 2>/dev/null | "$SDKM" --sdk_root="$SDK" --licenses >/dev/null 2>&1) || true
if [ ! -d "$SDK/platforms/android-35" ] || [ ! -d "$SDK/build-tools/35.0.0" ]; then
  echo "Memasang platform Android 35 & build-tools (sekali saja)..."
  "$SDKM" --sdk_root="$SDK" "platform-tools" "platforms;android-35" "build-tools;35.0.0" >/dev/null
fi
echo "Android SDK OK"

# ---------- 3. salin proyek ----------
langkah "Siapkan proyek di $KERJA/src"
mkdir -p "$KERJA"
P="$KERJA/src"
if command -v rsync >/dev/null 2>&1; then
  rsync -a --delete --exclude 'android/app/build' --exclude 'android/build' --exclude 'android/.gradle' --exclude 'android/local.properties' "$SUMBER/" "$P/"
else
  rm -rf "$P"; cp -a "$SUMBER" "$P"
fi
echo "sdk.dir=$SDK" > "$P/android/local.properties"
chmod +x "$P/android/gradlew"

# ---------- 4. nomor versi ----------
mkdir -p "$KUNCI_DIR"; chmod 700 "$KUNCI_DIR"
F_VERSI="$KUNCI_DIR/versi-terakhir"
KODE=$(( $(cat "$F_VERSI" 2>/dev/null || echo 0) + 1 ))
NAMA="${VERSI:-1.0.$KODE}"
sed -i -E "s/versionCode [0-9]+/versionCode $KODE/; s/versionName \"[^\"]*\"/versionName \"$NAMA\"/" "$P/android/app/build.gradle"
for f in "$P/www/index.html" "$P/android/app/src/main/assets/public/index.html"; do
  [ -f "$f" ] && sed -i "s/__VERSI_NAMA__/$NAMA/g; s/__VERSI_KODE__/$KODE/g" "$f"
done

# ---------- 5. kunci tanda tangan ----------
KS="$KUNCI_DIR/toya-radius.jks"
KP="$KUNCI_DIR/kunci.properties"
if [ ! -f "$KS" ]; then
  langkah "Membuat kunci tanda tangan APK (sekali saja)"
  PW=$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)
  "$KEYTOOL" -genkeypair -keystore "$KS" -alias toyaradius -keyalg RSA -keysize 2048 -validity 10950 \
    -storepass "$PW" -keypass "$PW" -dname "CN=Toya Radius, O=Toya Radius, C=ID" >/dev/null 2>&1
  printf 'storePassword=%s\nkeyAlias=toyaradius\n' "$PW" > "$KP"
  chmod 600 "$KS" "$KP"
  echo "!! Kunci baru: $KS"
  echo "!! SIMPAN CADANGAN file ini + $KP di tempat aman (mis. laptop)."
  echo "!! Kalau hilang, APK berikutnya TIDAK BISA memperbarui APK yang sudah terpasang."
fi
PW=$(grep '^storePassword=' "$KP" | cut -d= -f2-)

# ---------- 6. build ----------
langkah "Membangun APK v$NAMA (kode $KODE) -- pertama kali bisa 5-15 menit"
cd "$P/android"
./gradlew --no-daemon --console=plain assembleRelease \
  -Pandroid.injected.signing.store.file="$KS" \
  -Pandroid.injected.signing.store.password="$PW" \
  -Pandroid.injected.signing.key.alias=toyaradius \
  -Pandroid.injected.signing.key.password="$PW"
APK="$P/android/app/build/outputs/apk/release/app-release.apk"
[ -f "$APK" ] || gagal "file APK tidak terbentuk"

# ---------- 7. terbitkan ----------
langkah "Menerbitkan ke $APP_DIR/public/unduh"
OUT="$APP_DIR/public/unduh"
mkdir -p "$OUT"
cp "$APK" "$OUT/toya-radius-$NAMA.apk"
cp "$APK" "$OUT/.toya-radius.apk.baru"
mv -f "$OUT/.toya-radius.apk.baru" "$OUT/toya-radius.apk"
HOST=$(grep -E '^APP_URL=' "$APP_DIR/.env" | head -1 | cut -d= -f2- | tr -d "\"'" | sed -E 's#^https?://##; s#/.*$##')
[ -n "$HOST" ] || HOST="toyaqi.web.id"
UKURAN=$(stat -c %s "$APK")
python3 - "$OUT/versi.json" "$KODE" "$NAMA" "$HOST" "$UKURAN" "$CATATAN" "$WAJIB" <<'PY'
import json, sys, datetime
f, kode, nama, host, ukuran, catatan, wajib = sys.argv[1:8]
data = {
    "kode": int(kode),
    "versi": nama,
    "url": f"https://{host}/unduh/toya-radius.apk?v={kode}",
    "halaman": f"https://{host}/aplikasi",
    "ukuran": int(ukuran),
    "tanggal": datetime.datetime.now().astimezone().isoformat(timespec="seconds"),
    "catatan": catatan,
    "wajib": wajib == "1",
}
json.dump(data, open(f + ".baru", "w"), ensure_ascii=False, indent=2)
import os; os.replace(f + ".baru", f)
PY
echo "$KODE" > "$F_VERSI"

echo
echo "SELESAI: Toya Radius v$NAMA (kode $KODE), $(( UKURAN / 1024 )) KB"
echo "  Halaman unduh : https://$HOST/aplikasi"
echo "  File APK      : https://$HOST/unduh/toya-radius.apk"
