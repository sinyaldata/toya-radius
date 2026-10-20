<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

/**
 * Model ini SENGAJA ditaruh di namespace terpisah (App\Models\Central)
 * biar tidak ketuker sama model-model tenant biasa (App\Models\*) yang
 * jumlahnya banyak di aplikasi ini.
 */
class Tenant extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'name',
        'owner_name',
        'subdomain',
        'database_name',
        'telegram_bot_token',
        'telegram_bot_username',
        'plan_catalog_id',
        'billing_due_date',
        'owner_email',
        'owner_phone',
        'address',
        'status',
        'provisioned_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'provisioned_at' => 'datetime',
            'billing_due_date' => 'date',
        ];
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PlanCatalog::class, 'plan_catalog_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Berapa hari lagi sampai jatuh tempo tagihan tenant ini (negatif
     * kalau sudah lewat). Null kalau belum diatur sama sekali -- tenant
     * lama sebelum fitur ini ada, atau memang sengaja dikosongkan
     * (mis. tenant gratis/internal).
     */
    public function daysUntilBillingDue(): ?int
    {
        if (! $this->billing_due_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->billing_due_date->copy()->startOfDay(), false);
    }

    /**
     * True mulai H-10 sampai jatuh tempo lewat -- dipakai
     * TenantBillingReminderWidget buat memutuskan tampil/tidaknya
     * pengingat pembayaran di dashboard tenant.
     */
    public function isBillingDueSoon(): bool
    {
        $sisaHari = $this->daysUntilBillingDue();

        return $sisaHari !== null && $sisaHari <= 10;
    }
}
