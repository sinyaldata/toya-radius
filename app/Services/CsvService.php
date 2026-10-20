<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\VoucherStatus;
use App\Models\Customer;
use App\Models\ServicePackage;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Export & Import CSV untuk data User Hotspot, PPPoE, dan Voucher.
 *
 * Pakai CSV (bukan Excel) supaya bisa dibuka di aplikasi apa pun dan tidak
 * bergantung pada library berat. Export memakai streaming, jadi ribuan baris
 * pun tidak akan bikin memori server jebol.
 */
class CsvService
{
    public function __construct(
        protected CustomerRadiusService $customerRadiusService,
        protected FreeRadiusService $freeRadiusService,
    ) {}

    // ===================== EXPORT =====================

    public function exportCustomers(Builder $query, string $filename): StreamedResponse
    {
        $headers = ['customer_number', 'name', 'type', 'username', 'password', 'service_package', 'status', 'phone', 'address', 'billing_day', 'static_ip', 'outlet', 'installation_date'];

        return $this->stream($filename, $headers, function ($handle) use ($query) {
            $query->with(['servicePackage', 'outlet'])->chunk(200, function ($rows) use ($handle) {
                foreach ($rows as $r) {
                    fputcsv($handle, [
                        $r->customer_number,
                        $r->name,
                        $r->type?->value,
                        $r->username,
                        $r->password,
                        $r->servicePackage?->name,
                        $r->status?->value,
                        $r->phone,
                        $r->address,
                        $r->billing_day,
                        $r->static_ip,
                        $r->outlet?->name,
                        $r->installation_date?->format('Y-m-d'),
                    ]);
                }
            });
        });
    }

    public function exportVouchers(Builder $query, string $filename): StreamedResponse
    {
        $headers = ['username', 'password', 'profile', 'status', 'sale_price', 'outlet', 'batch_code', 'sold_at', 'activated_at', 'expired_at'];

        return $this->stream($filename, $headers, function ($handle) use ($query) {
            $query->with(['profile', 'outlet'])->chunk(200, function ($rows) use ($handle) {
                foreach ($rows as $r) {
                    fputcsv($handle, [
                        $r->username,
                        $r->password,
                        $r->profile?->name,
                        $r->status?->value,
                        $r->sale_price,
                        $r->outlet?->name,
                        $r->batch_code,
                        $r->sold_at?->format('Y-m-d H:i:s'),
                        $r->activated_at?->format('Y-m-d H:i:s'),
                        $r->expired_at?->format('Y-m-d H:i:s'),
                    ]);
                }
            });
        });
    }

    public function exportOutlets(Builder $query, string $filename): StreamedResponse
    {
        $headers = ['code', 'name', 'owner_name', 'phone', 'address', 'commission_percent', 'is_active'];

        return $this->stream($filename, $headers, function ($handle) use ($query) {
            $query->chunk(200, function ($rows) use ($handle) {
                foreach ($rows as $r) {
                    fputcsv($handle, [
                        $r->code,
                        $r->name,
                        $r->owner_name,
                        $r->phone,
                        $r->address,
                        $r->commission_percent,
                        $r->is_active ? '1' : '0',
                    ]);
                }
            });
        });
    }

    protected function stream(string $filename, array $headers, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $writer) {
            $handle = fopen('php://output', 'w');
            // BOM UTF-8 supaya karakter Indonesia tidak rusak saat dibuka di Excel
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);
            $writer($handle);
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ===================== IMPORT =====================

    /**
     * Import pelanggan (Hotspot tetap / PPPoE) dari CSV.
     * Kolom wajib: name, username, password, service_package
     * Kolom opsional: phone, address, billing_day, static_ip, status
     *
     * @return array{imported:int, skipped:int, errors:array<string>}
     */
    public function importCustomers(string $path, CustomerType $type): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        $rows = $this->readCsv($path);

        foreach ($rows as $line => $row) {
            $username = trim($row['username'] ?? '');
            $name = trim($row['name'] ?? '');

            if ($username === '' || $name === '') {
                $skipped++;
                $errors[] = "Baris {$line}: kolom name/username kosong.";

                continue;
            }

            if (Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->where('username', $username)->exists()) {
                $skipped++;
                $errors[] = "Baris {$line}: username '{$username}' sudah ada, dilewati.";

                continue;
            }

            $package = ServicePackage::where('name', trim($row['service_package'] ?? ''))->first();

            if (! $package) {
                $skipped++;
                $errors[] = "Baris {$line}: paket '".($row['service_package'] ?? '')."' tidak ditemukan.";

                continue;
            }

            try {
                DB::transaction(function () use ($row, $type, $package, $username, $name, &$imported) {
                    $customer = Customer::create([
                        'name' => $name,
                        'username' => $username,
                        'password' => trim($row['password'] ?? '') ?: str()->random(8),
                        'type' => $type,
                        'service_package_id' => $package->id,
                        'phone' => trim($row['phone'] ?? '') ?: null,
                        'address' => trim($row['address'] ?? '') ?: null,
                        'billing_day' => (int) ($row['billing_day'] ?? 1) ?: 1,
                        'static_ip' => trim($row['static_ip'] ?? '') ?: null,
                        'status' => CustomerStatus::tryFrom(trim($row['status'] ?? '')) ?? CustomerStatus::Active,
                    ]);

                    // Langsung dorong ke tabel radius supaya bisa dipakai tanpa langkah manual
                    $this->customerRadiusService->provisionCustomer($customer, $package);

                    $imported++;
                });
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Baris {$line}: gagal disimpan — ".$e->getMessage();
                Log::error('Import pelanggan gagal', ['line' => $line, 'error' => $e->getMessage()]);
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Import Outlet/Reseller dari CSV.
     * Kolom wajib: name, owner_name
     * Kolom opsional: phone, address, commission_percent, is_active
     */
    public function importOutlets(string $path): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        $rows = $this->readCsv($path);

        foreach ($rows as $line => $row) {
            $name = trim($row['name'] ?? '');

            if ($name === '') {
                $skipped++;
                $errors[] = "Baris {$line}: kolom name kosong.";

                continue;
            }

            if (\App\Models\Outlet::where('name', $name)->exists()) {
                $skipped++;
                $errors[] = "Baris {$line}: outlet '{$name}' sudah ada, dilewati.";

                continue;
            }

            try {
                \App\Models\Outlet::create([
                    'name' => $name,
                    'owner_name' => trim($row['owner_name'] ?? '') ?: $name,
                    'phone' => trim($row['phone'] ?? '') ?: null,
                    'address' => trim($row['address'] ?? '') ?: null,
                    'commission_percent' => trim($row['commission_percent'] ?? '') !== '' ? (float) $row['commission_percent'] : 0,
                    'is_active' => trim($row['is_active'] ?? '1') !== '0',
                ]);
                $imported++;
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Baris {$line}: gagal disimpan — ".$e->getMessage();
                Log::error('Import outlet gagal', ['line' => $line, 'error' => $e->getMessage()]);
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Import voucher dari CSV.
     * Kolom wajib: username, password, profile
     * Kolom opsional: sale_price, status
     */
    public function importVouchers(string $path): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        $rows = $this->readCsv($path);

        foreach ($rows as $line => $row) {
            $username = trim($row['username'] ?? '');

            if ($username === '') {
                $skipped++;
                $errors[] = "Baris {$line}: kolom username kosong.";

                continue;
            }

            if (Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->where('username', $username)->exists()) {
                $skipped++;
                $errors[] = "Baris {$line}: voucher '{$username}' sudah ada, dilewati.";

                continue;
            }

            $package = ServicePackage::where('name', trim($row['profile'] ?? ''))->first();

            if (! $package) {
                $skipped++;
                $errors[] = "Baris {$line}: profile '".($row['profile'] ?? '')."' tidak ditemukan.";

                continue;
            }

            try {
                DB::transaction(function () use ($row, $package, $username, &$imported) {
                    $voucher = Voucher::create([
                        'username' => $username,
                        'password' => trim($row['password'] ?? '') ?: $username,
                        'voucher_profile_id' => $package->id,
                        'batch_code' => 'IMPORT-'.now()->format('Ymd'),
                        'status' => VoucherStatus::tryFrom(trim($row['status'] ?? '')) ?? VoucherStatus::BelumTerjual,
                        'cost_price' => $package->cost_price,
                        'sale_price' => trim($row['sale_price'] ?? '') !== '' ? (float) $row['sale_price'] : $package->sale_price,
                        'generated_by' => auth()->id(),
                    ]);

                    $this->freeRadiusService->provisionVoucher($voucher, $package);

                    $imported++;
                });
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Baris {$line}: gagal disimpan — ".$e->getMessage();
                Log::error('Import voucher gagal', ['line' => $line, 'error' => $e->getMessage()]);
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Baca CSV jadi array asosiatif berdasarkan baris header.
     *
     * @return array<int, array<string, string>>
     */
    protected function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        if (! $handle) {
            return $rows;
        }

        $header = fgetcsv($handle);

        if (! $header) {
            fclose($handle);

            return $rows;
        }

        // Buang BOM kalau ada, dan rapikan nama kolom
        $header = array_map(fn ($h) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $h))), $header);

        $line = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $line++;

            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // lewati baris kosong
            }

            $data = array_pad($data, count($header), '');
            $rows[$line] = array_combine($header, array_slice($data, 0, count($header)));
        }

        fclose($handle);

        return $rows;
    }
}
