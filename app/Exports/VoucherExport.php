<?php

namespace App\Exports;

use App\Models\Voucher;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VoucherExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        protected ?int $outletId = null,
        protected ?int $profileId = null,
    ) {}

    public function collection()
    {
        return Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->with(['profile', 'outlet'])
            ->when($this->outletId, fn ($q) => $q->where('outlet_id', $this->outletId))
            ->when($this->profileId, fn ($q) => $q->where('voucher_profile_id', $this->profileId))
            ->get();
    }

    public function headings(): array
    {
        return ['Username', 'Password', 'Profile', 'Outlet', 'Status', 'Harga Modal', 'Harga Jual', 'Terjual Pada', 'Aktivasi Pertama', 'Kedaluwarsa'];
    }

    public function map($row): array
    {
        return [
            $row->username,
            $row->password,
            $row->profile?->name,
            $row->outlet?->name,
            $row->status?->value ?? $row->status,
            (float) $row->cost_price,
            (float) $row->sale_price,
            // Waktu pemakaian voucher IKUT diexport — kalau voucher belum
            // pernah dipakai, kolom ini kosong (bukan error, memang belum
            // ada datanya sampai voucher itu diaktifkan/dipakai login).
            $row->sold_at?->format('Y-m-d H:i:s'),
            $row->activated_at?->format('Y-m-d H:i:s'),
            $row->expired_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
