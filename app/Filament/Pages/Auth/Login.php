<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

class Login extends BaseLogin
{
    /**
     * Desain login custom (panel brand + form) — file-nya sudah ada di
     * resources/views/filament/pages/auth/custom-login.blade.php tapi
     * sebelumnya tidak pernah dipakai karena $view ini belum di-set.
     */
    protected string $view = 'filament.pages.auth.custom-login';

    /**
     * SimplePage (induk Login) defaultnya pakai layout
     * 'filament-panels::components.layout.simple' — itu yang membungkus
     * halaman dalam kartu putih sempit (fi-simple-main, max-width ~28rem)
     * yang biasa muncul di halaman login Filament standar.
     *
     * custom-login.blade.php di atas SUDAH membungkus dirinya sendiri
     * lewat <x-filament-panels::layout.base> (html/head/body penuh, full
     * width). Kalau layout bawaan ini tidak diganti, hasilnya numpuk dua
     * <html><body> (layout.simple -> layout.base DI DALAM view kita yang
     * juga panggil layout.base lagi), dan seluruh desain kita otomatis
     * ke-squeeze ke dalam kartu sempit itu — makanya tampil lonjong/pipih.
     *
     * Diganti langsung ke layout.base supaya view kita jadi satu-satunya
     * pembungkus html/body, tanpa kartu sempit bawaan.
     */
    protected static string $layout = 'filament-panels::components.layout.base';

    /**
     * Field "email" bawaan Filament diganti supaya bisa terima EMAIL ATAU
     * USERNAME — validasi ->email() dilepas, cuma jadi TextInput biasa.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email atau Username')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * Deteksi otomatis: kalau isiannya berformat email → cari lewat kolom
     * email, kalau bukan → cari lewat kolom username. Password tetap dicek
     * seperti biasa oleh Auth::attempt().
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $login = trim((string) $data['email']);
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [
            $field => $login,
            'password' => $data['password'],
        ];
    }
}
