<?php

namespace App\Exports;

use App\Enums\CustomerType;
use App\Models\Customer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PppoeUserExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    public function collection()
    {
        return Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->where('type', CustomerType::Pppoe)->with(['servicePackage', 'outlet'])->get();
    }

    public function headings(): array
    {
        return ['No Pelanggan', 'Nama', 'Username', 'Password', 'Telepon', 'Alamat', 'Profile', 'Biller', 'Status', 'Tgl Jatuh Tempo'];
    }

    public function map($row): array
    {
        return [
            $row->customer_number,
            $row->name,
            $row->username,
            $row->password,
            $row->phone,
            $row->address,
            $row->servicePackage?->name,
            $row->outlet?->name,
            $row->status?->value ?? $row->status,
            $row->billing_day,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
