# Toya Radius — Panduan Deployment (Tahap 14/14)

## 1. Persiapan Server (Ubuntu 22.04/24.04)

```bash
apt update && apt install -y nginx mysql-server php8.3-fpm php8.3-cli php8.3-mysql \
    php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd \
    php8.3-intl composer supervisor freeradius freeradius-mysql freeradius-utils
```

## 2. Setup Aplikasi

```bash
cd /var/www
composer create-project laravel/laravel toya-radius "^12.0"
cd toya-radius
composer require filament/filament:"^4.0" livewire/livewire barryvdh/laravel-dompdf \
    maatwebsite/excel evilfreelancer/routeros-api-php
php artisan filament:install --panels
composer require pestphp/pest pestphp/pest-plugin-laravel --dev
php artisan pest:install
```

Salin seluruh file dari paket Tahap 1-14 yang sudah dikirim ke struktur folder yang sesuai
(`app/`, `database/`, `routes/`, `resources/`, `tests/`, `bootstrap/providers.php`,
`composer.json`, `.env.example`).

```bash
cp .env.example .env
php artisan key:generate
```

Isi `.env` — minimal `DB_*` dan `APP_URL`.

## 3. Database

```bash
mysql -u root -p
```

```sql
CREATE DATABASE toyaradius CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'toyaradius_app'@'localhost' IDENTIFIED BY 'password_app_kuat';
GRANT ALL PRIVILEGES ON toyaradius.* TO 'toyaradius_app'@'localhost';

-- User terpisah khusus FreeRADIUS, akses read-write ke tabel radius saja
CREATE USER 'toyaradius_radius'@'localhost' IDENTIFIED BY 'password_radius_kuat';
GRANT SELECT, INSERT, UPDATE, DELETE ON toyaradius.radcheck TO 'toyaradius_radius'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON toyaradius.radreply TO 'toyaradius_radius'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON toyaradius.radgroupcheck TO 'toyaradius_radius'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON toyaradius.radgroupreply TO 'toyaradius_radius'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON toyaradius.radusergroup TO 'toyaradius_radius'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON toyaradius.radacct TO 'toyaradius_radius'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON toyaradius.radpostauth TO 'toyaradius_radius'@'localhost';
GRANT SELECT ON toyaradius.nas TO 'toyaradius_radius'@'localhost';
FLUSH PRIVILEGES;
```

```bash
php artisan migrate --seed
```

## 4. FreeRADIUS

Ikuti `deployment/freeradius/mods-available-sql.conf`,
`deployment/freeradius/sites-default-snippet.conf`, dan
`deployment/freeradius/dictionary-mikrotik-note.txt` yang sudah dikirim di Tahap 9.

```bash
ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
systemctl restart freeradius
systemctl enable freeradius
```

## 5. MikroTik

Ikuti `deployment/mikrotik-setup-guide.md` (Tahap 10) di setiap router yang dipakai.

## 6. Web Server

```bash
cp deployment/nginx.conf /etc/nginx/sites-available/toya-radius
ln -s /etc/nginx/sites-available/toya-radius /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
certbot --nginx -d billing.contohisp.com
```

## 7. Queue Worker & Scheduler

```bash
cp deployment/supervisor-worker.conf /etc/supervisor/conf.d/toya-radius-worker.conf
supervisorctl reread && supervisorctl update
supervisorctl start toya-radius-worker:*
```

Tambahkan cron scheduler Laravel (wajib, untuk `vouchers:mark-expired` di `routes/console.php`):

```bash
crontab -e
```

```
* * * * * cd /var/www/toya-radius && php artisan schedule:run >> /dev/null 2>&1
```

## 8. Permission

```bash
chown -R www-data:www-data /var/www/toya-radius
chmod -R 775 storage bootstrap/cache
```

## 9. Optimasi Produksi

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:cache-components
composer install --no-dev --optimize-autoloader
```

**Penting**: setelah `config:cache`, perubahan `.env` tidak berlaku sampai
`php artisan config:cache` dijalankan ulang.

## 10. Buat User Pertama

Sudah otomatis dibuat lewat `UserSeeder` (Tahap 4):
- `superadmin@toyaradius.test` / `password`
- `admin@toyaradius.test` / `password`
- `outlet@toyaradius.test` / `password`

**Wajib ganti password ini sebelum go-live.** Login di `https://domain-anda.com/admin`.

## 11. Menjalankan Test

```bash
php artisan test
# atau
./vendor/bin/pest
```

## 12. Checklist Go-Live

- [ ] `.env` `APP_DEBUG=false`, `APP_ENV=production`
- [ ] Password default super admin/admin/outlet sudah diganti
- [ ] Router MikroTik terhubung (cek tombol "Test Koneksi")
- [ ] `radtest` manual berhasil dari server FreeRADIUS
- [ ] Voucher percobaan berhasil login di hotspot & muncul di menu User Online
- [ ] Backup database terjadwal (mysqldump cron harian)
- [ ] SSL aktif (https)
- [ ] Supervisor worker & cron scheduler jalan (`supervisorctl status`, `crontab -l`)

---

**Aplikasi Toya Radius — 14 tahap selesai.** Seluruh source code sudah dikirim
secara bertahap dalam paket zip terpisah per tahap di respons-respons sebelumnya.
