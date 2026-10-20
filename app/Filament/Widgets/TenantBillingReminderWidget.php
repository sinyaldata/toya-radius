<?php

namespace App\Filament\Widgets;

use App\Models\Central\Tenant;
use Filament\Widgets\Widget;

/**
 * Pengingat pembayaran langganan TENANT KE OWNER (bukan tagihan
 * pelanggan-ke-ISP -- itu InvoiceListWidget). Cuma tampil di dashboard
 * SATU tenant tertentu, dan cuma mulai H-10 sebelum billing_due_date-nya
 * sampai lewat jatuh tempo -- lihat Tenant::isBillingDueSoon().
 *
 * TIDAK PERNAH tampil di instalasi utama (app()->bound('currentTenant')
 * hanya true waktu request sedang berjalan di konteks salah satu
 * tenant -- lihat AppServiceProvider::boot() & ResolvesTenantBySubdomain).
 */
class TenantBillingReminderWidget extends Widget
{
    protected static bool $isLazy = false; // MUAT-WIDGET-LANGSUNG: tampil bersama halaman
    // Ditaruh PALING ATAS (di atas GreetingWidget yang sort-nya 0) --
    // ini pengingat yang butuh tindakan, jadi sengaja paling mencolok.
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.tenant-billing-reminder-widget';

    public static function canView(): bool
    {
        $tenant = static::resolveCurrentTenant();

        return $tenant !== null && $tenant->isBillingDueSoon();
    }

    protected static function resolveCurrentTenant(): ?Tenant
    {
        return app()->bound('currentTenant') ? app('currentTenant') : null;
    }

    public function getTenant(): ?Tenant
    {
        return static::resolveCurrentTenant();
    }
}
