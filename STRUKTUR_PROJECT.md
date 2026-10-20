# Toya Radius — Struktur Project (Tahap 1/14)

Aplikasi Billing Hotspot Voucher untuk RT/RW Net. Laravel 12 + Filament v4 + FreeRADIUS + MikroTik API.

## Cara Setup di Server Anda

```bash
composer create-project laravel/laravel toya-radius "^12.0"
cd toya-radius
composer require filament/filament:"^4.0" livewire/livewire spatie/laravel-permission barryvdh/laravel-dompdf maatwebsite/excel evilfreelancer/routeros-api-php
php artisan filament:install --panels
```

Setelah itu, salin seluruh file dari paket ini ke folder project Anda (migration, model, service, dst — akan menyusul di tahap berikutnya), lalu:

```bash
php artisan migrate --seed
```

## Struktur Folder (app/)

```
app/
├── Enums/
│   ├── VoucherStatus.php          # BelumTerjual, Terjual, Aktif, Expired, Disabled
│   ├── RoleType.php                # SuperAdmin, Admin, Outlet
│   ├── PaymentMethod.php           # Tunai, Transfer
│   └── PaymentStatus.php           # Lunas, Pending
│
├── Models/
│   ├── User.php
│   ├── Router.php                  # MikroTik router
│   ├── Nas.php                     # FreeRADIUS NAS
│   ├── VoucherProfile.php
│   ├── Voucher.php
│   ├── Outlet.php
│   ├── Transaction.php
│   ├── ActivityLog.php
│   ├── LoginLog.php
│   ├── VoucherLog.php
│   ├── ApiLog.php
│   ├── Setting.php
│   ├── Radcheck.php                # maps ke tabel radcheck (freeradius)
│   ├── Radreply.php
│   ├── Radusergroup.php
│   ├── Radacct.php
│   └── Radpostauth.php
│
├── DTO/
│   ├── VoucherGenerationData.php
│   └── MikrotikConnectionData.php
│
├── Repositories/
│   ├── Contracts/
│   │   ├── VoucherRepositoryInterface.php
│   │   ├── TransactionRepositoryInterface.php
│   │   └── RouterRepositoryInterface.php
│   ├── VoucherRepository.php
│   ├── TransactionRepository.php
│   └── RouterRepository.php
│
├── Services/
│   ├── VoucherGeneratorService.php   # generate voucher + insert radcheck/radreply/radusergroup
│   ├── FreeRadiusService.php         # sinkronisasi radcheck/radreply/radusergroup
│   ├── MikrotikService.php           # koneksi RouterOS API, test, disconnect, sync
│   ├── TransactionService.php        # proses penjualan voucher
│   ├── ReportService.php             # laporan penjualan + export
│   └── DashboardService.php          # agregasi widget dashboard
│
├── Actions/
│   ├── GenerateVoucherBatchAction.php
│   ├── SellVoucherAction.php
│   ├── DisconnectUserAction.php
│   └── SyncRouterProfileAction.php
│
├── Observers/
│   ├── VoucherObserver.php           # log otomatis saat create/update/delete
│   └── TransactionObserver.php
│
├── Policies/
│   ├── VoucherPolicy.php
│   ├── TransactionPolicy.php
│   ├── OutletPolicy.php
│   └── RouterPolicy.php
│
└── Filament/Resources/
    ├── RouterResource.php
    ├── NasResource.php
    ├── VoucherProfileResource.php
    ├── VoucherResource.php
    ├── OutletResource.php
    ├── TransactionResource.php
    ├── OnlineUserResource.php        # baca dari radacct
    ├── ReportResource.php
    └── SettingResource.php
```

## Prinsip Arsitektur

- **Repository Pattern**: semua akses data lewat Repository, Controller/Livewire/Filament Resource tidak query langsung ke Model.
- **Service Layer**: logika bisnis (generate voucher, sinkron FreeRADIUS, integrasi MikroTik) ada di Service, bukan di Controller/Resource.
- **Action Classes**: satu class = satu aksi bisnis spesifik (single responsibility), dipanggil dari Service atau langsung dari Filament.
- **DB Transaction wajib** di semua proses yang menyentuh lebih dari satu tabel (mis. generate voucher → insert ke `vouchers`, `radcheck`, `radreply`, `radusergroup` harus atomik).
- **Observer** untuk audit log otomatis (activity_logs, voucher_logs) tanpa mengotori Service.
- **Policy** untuk otorisasi per-role (Outlet hanya bisa akses miliknya sendiri — di-enforce di Policy + query scope, bukan hanya di UI).
- **Enum** untuk semua status agar type-safe (VoucherStatus, PaymentMethod, dst), bukan string mentah.

## Role & Panel Access

- **Super Admin**: akses penuh semua modul, semua outlet.
- **Admin**: akses operasional penuh kecuali pengaturan sistem sensitif (mis. hapus router, ubah role user).
- **Outlet/Reseller**: panel terbatas — hanya lihat voucher, transaksi, dan laporan miliknya sendiri. Di-enforce lewat Global Scope pada Model (`OutletScope`) + Filament Resource query modification, bukan hanya filter UI.

---
**Selanjutnya (Tahap 2): Migration lengkap** — dilampirkan di paket file yang sama.
