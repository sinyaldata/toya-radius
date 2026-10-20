<?php

namespace App\Exports;

use App\Enums\PartnerType;
use App\Models\Outlet;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OutletExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    public function __construct(protected PartnerType $type) {}

    public function collection()
    {
        return Outlet::where('partner_type', $this->type)->get();
    }

    public function headings(): array
    {
        return ['Kode', 'Nama', 'Pemilik', 'Telepon', 'Alamat', 'Tipe Komisi', 'Komisi %', 'Komisi Flat', 'Saldo', 'Aktif', 'Catatan'];
    }

    public function map($row): array
    {
        return [
            $row->code,
            $row->name,
            $row->owner_name,
            $row->phone,
            $row->address,
            // BARU: kolom "Tipe Komisi" (percent/flat) -- menyertai 2
            // kolom Komisi di sebelahnya yang sudah ada, supaya jelas
            // yang mana dari dua angka itu yang SEBENARNYA dipakai
            // outlet ini (lihat App\Enums\CommissionType).
            $row->commission_type?->value ?? 'percent',
            (float) $row->commission_percent,
            (float) $row->commission_flat_amount,
            (float) $row->saldo,
            $row->is_active ? 'Ya' : 'Tidak',
            $row->notes,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
