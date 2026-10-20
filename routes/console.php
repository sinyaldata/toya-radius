<?php

use Illuminate\Support\Facades\Schedule;

// Cek voucher expired setiap 15 menit — cukup sering utk hotspot voucher jangka pendek (1 jam - 30 hari)
Schedule::command('vouchers:mark-expired')->everyFifteenMinutes()->withoutOverlapping();

// Deteksi voucher yang sudah dipakai (login pertama) tiap 5 menit dari radacct
Schedule::command('vouchers:sync-activation')->everyFiveMinutes()->withoutOverlapping();

// Generate tagihan bulanan pelanggan tetap (Hotspot & PPPoE), jalan tiap awal bulan
// (idempotent, aman kalau server sempat mati pas tanggal 1 — akan generate begitu jalan lagi)
Schedule::command('invoices:generate-bulanan')->dailyAt('01:00')->withoutOverlapping();

// Cek tagihan jatuh tempo & isolir otomatis, jalan tiap hari
Schedule::command('invoices:cek-overdue')->dailyAt('02:00')->withoutOverlapping();

// Catat peristiwa login/logout user jaringan (voucher, hotspot, pppoe) ke log permanen
Schedule::command('radius:sync-session-logs')->everyFiveMinutes()->withoutOverlapping();

// Cek status Online/Offline pelanggan Static IP (ARP-ping dari router masing-masing)
Schedule::command('static-ip:cek-online')->everyFiveMinutes()->withoutOverlapping(15);

// Tutup sesi RADIUS "hantu" -- baris yang masih tercatat Online tapi sudah
// >15 menit tidak ada kabar (mati listrik, router reboot mendadak, dst.).
// Tanpa ini, status Online/Offline bisa nyangkut Online selamanya --
// lihat App\Console\Commands\TutupSesiHantu untuk penjelasan lengkap.
Schedule::command('radius:tutup-sesi-hantu')->everyFiveMinutes()->withoutOverlapping(15);

// Perbaiki otomatis pelanggan Aktif yang kehilangan Cleartext-Password
// di RADIUS -- ditemukan 2 kali terjadi (2026-09-26, akar penyebab
// belum dipastikan), jadi ini SAFETY NET: begitu terjadi lagi, langsung
// diperbaiki sendiri dalam <=30 menit, bukan menunggu pelanggan
// komplain dulu. AMAN dijalankan otomatis -- hanya menulis ulang
// kredensial dari data yang SUDAH ADA di aplikasi (bukan menebak),
// dan pelanggan Suspend/Isolir/tanpa paket sengaja dilewati (lihat
// App\Console\Commands\CekKredensialHilang).
Schedule::command('radius:cek-kredensial-hilang --perbaiki')->everyThirtyMinutes()->withoutOverlapping(25);

// Perbaiki otomatis pelanggan PPPoE Aktif yang Mikrotik-Group-nya
// hilang/salah (tidak dapat IP) -- ditemukan lewat kasus nyata
// "zafran2" (2026-09-26/27, saklar tenant sempat mati). SAFETY NET yang
// sama semangatnya dengan pemeriksa kredensial hilang di atas -- lihat
// App\Console\Commands\CekMikrotikGroupHilang.
Schedule::command('radius:cek-mikrotik-group-hilang --perbaiki')->everyThirtyMinutes()->withoutOverlapping(25);
