<?php

namespace App\Models\Scopes;

use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope wajib pada Model yang tabelnya DIPINDAH ke database pusat
 * (Nas, Router, VpnAccount) — tabel-tabel ini DIPAKAI BERSAMA oleh
 * SEMUA tenant (FreeRADIUS cuma baca dari satu database), jadi WAJIB
 * difilter `tenant_id` di sini supaya tenant satu tidak pernah bisa
 * melihat/mengedit data milik tenant lain lewat query manapun.
 *
 * Tenant SEKARANG diketahui dari `app('currentTenant')` — diisi di
 * AppServiceProvider::switchToTenantDatabaseIfNeeded() untuk SEMUA
 * request tenant subdomain. Kalau tidak ada (diakses lewat domain
 * utama/pusat), scope ini TIDAK diterapkan sama sekali — instalasi
 * utama dianggap "tanpa tenant_id" (NULL), lihat catatan migrasi data.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! app()->bound('currentTenant')) {
            // Diakses lewat domain utama (bukan subdomain tenant) — cuma
            // tampilkan baris tanpa tenant_id (instalasi utama/legacy).
            $builder->whereNull($model->getTable().'.tenant_id');

            return;
        }

        $tenant = app('currentTenant');

        if (! $tenant instanceof Tenant) {
            return;
        }

        $builder->where($model->getTable().'.tenant_id', $tenant->id);
    }
}
