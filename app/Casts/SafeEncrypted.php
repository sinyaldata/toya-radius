<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Sama persis seperti cast 'encrypted' bawaan Laravel, BEDANYA: kalau nilai
 * di database gagal didekripsi (paling sering karena APP_KEY server pernah
 * berganti sejak data itu disimpan), tidak melempar DecryptException yang
 * bikin halaman crash — cukup dianggap kosong. Cukup normal untuk field
 * kredensial seperti password router: admin tinggal isi ulang, tidak perlu
 * developer turun tangan tiap kali APP_KEY berubah.
 */
class SafeEncrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString($value);
    }
}
