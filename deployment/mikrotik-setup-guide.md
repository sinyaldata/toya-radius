# Setup MikroTik RouterOS untuk Toya Radius

Dijalankan di terminal MikroTik (Winbox/SSH) pada router yang didaftarkan di menu
"Router MikroTik" aplikasi.

## 1. Tambahkan RADIUS client (arahkan ke server FreeRADIUS)

```
/radius add service=hotspot address=<IP_SERVER_FREERADIUS> secret=<SECRET_YANG_SAMA_DENGAN_NAS> timeout=3s
/ip hotspot profile set [find] use-radius=yes
```

`secret` HARUS identik dengan kolom `secret` di menu NAS FreeRADIUS / Router pada aplikasi.

## 2. Aktifkan RADIUS accounting (agar radacct terisi otomatis)

```
/radius incoming set accept=yes port=3799
/ip hotspot profile set [find] radius-accounting=yes radius-interim-update=5m
```

## 3. Buat Hotspot server (bila belum ada)

```
/ip hotspot setup
```

Ikuti wizard: pilih interface, DNS name, dsb. Setelah selesai, hotspot profile akan
otomatis dipakai oleh voucher yang digenerate dari aplikasi (field `mikrotik_profile`
pada Profile Voucher harus sama persis dengan nama profile ini).

## 4. Enable API (untuk MikrotikService)

```
/ip service set api port=8728 disabled=no
/ip service set api-ssl disabled=no    # opsional, jika use_ssl=true
```

Buat user API terpisah (bukan admin utama) demi keamanan:

```
/user add name=toyaradius-api password=<password_kuat> group=full
```

Isi `username`/`password` ini ke menu Router MikroTik di aplikasi.

## 5. Uji dari aplikasi

Gunakan tombol **"Test Koneksi"** di halaman Router MikroTik (Filament) — akan
memanggil `MikrotikService::testConnection()` yang query `/system/identity/print`
dan `/system/resource/print` lewat API.

## Cara kerja end-to-end

1. Admin generate voucher di aplikasi → `VoucherGeneratorService` insert ke
   `vouchers` + `radcheck` + `radreply` + `radusergroup` dalam satu transaction.
2. User connect ke Hotspot MikroTik, login pakai username/password voucher.
3. MikroTik kirim Access-Request ke FreeRADIUS (via RADIUS client di langkah 1).
4. FreeRADIUS baca `radcheck`/`radgroupcheck` langsung dari database yang sama →
   Access-Accept, kirim balik `Mikrotik-Rate-Limit` dari `radreply`.
5. MikroTik terapkan rate limit, buat sesi hotspot aktif.
6. Accounting (start/interim/stop) otomatis ditulis FreeRADIUS ke `radacct` →
   muncul real-time di menu **Monitoring → User Online** aplikasi.
