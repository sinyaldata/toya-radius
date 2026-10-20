<?php

namespace App\Imports;

use App\Enums\PartnerType;
use App\Models\Outlet;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import Outlet/Reseller/Biller — dicocokkan lewat "Kode" (kolom unik).
 * Kalau kode sudah ada, datanya DIPERBARUI (bukan dobel). Kolom di file
 * Excel harus PERSIS sama urutan/nama dengan hasil Export (Kode, Nama,
 * Pemilik, Telepon, Alamat, Komisi %, Komisi Flat, Saldo, Aktif, Catatan).
 */
class OutletImport implements ToModel, WithHeadingRow, SkipsEmptyRows
{
    public function __construct(protected PartnerType $type) {}

    public function model(array $row)
    {
        if (empty($row['kode'])) {
            return null;
        }

        return Outlet::updateOrCreate(
            ['code' => $row['kode']],
            [
                'partner_type' => $this->type,
                'name' => $row['nama'] ?? $row['kode'],
                'owner_name' => $row['pemilik'] ?? null,
                'phone' => $row['telepon'] ?? null,
                'address' => $row['alamat'] ?? null,
                'commission_percent' => $row['komisi_'] ?? $row['komisi'] ?? 0,
                'commission_type' => in_array(strtolower((string) ($row['tipe_komisi'] ?? 'percent')), ['flat', 'nominal tetap', 'nominal_tetap']) ? 'flat' : 'percent',
                'commission_flat_amount' => $row['komisi_flat'] ?? 0,
                'saldo' => $row['saldo'] ?? 0,
                'is_active' => in_array(strtolower((string) ($row['aktif'] ?? 'ya')), ['ya', 'yes', '1', 'true']),
                'notes' => $row['catatan'] ?? null,
            ]
        );
    }
}
