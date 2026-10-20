<?php

// APLIKASI-HP -- rute untuk aplikasi Android "Toya Radius"
// (dimuat dari routes/web.php oleh tools/pasang-aplikasi-hp.py)

use App\Http\Controllers\AplikasiHpController;
use Illuminate\Support\Facades\Route;

Route::get('/api-app/isp/{kode}', [AplikasiHpController::class, 'isp'])
    ->middleware('throttle:30,1')
    ->name('aplikasi-hp.isp');

Route::get('/api-app/versi', [AplikasiHpController::class, 'versi'])
    ->middleware('throttle:60,1')
    ->name('aplikasi-hp.versi');

Route::get('/aplikasi', [AplikasiHpController::class, 'unduh'])
    ->name('aplikasi-hp.unduh');
