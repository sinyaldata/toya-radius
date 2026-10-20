<?php

namespace App\Exports;

use App\Services\ReportService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalesReportExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    public function __construct(protected array $filters) {}

    public function collection()
    {
        return app(ReportService::class)->query($this->filters)->get();
    }

    public function headings(): array
    {
        return [
            'No. Transaksi', 'Tanggal', 'Outlet', 'Username Voucher',
            'Profile', 'Harga', 'Diskon', 'Total', 'Metode Bayar', 'Status',
        ];
    }

    public function map($transaction): array
    {
        return [
            $transaction->transaction_number,
            $transaction->transaction_date->format('d-m-Y H:i'),
            $transaction->outlet?->name ?? 'Stok Pusat',
            $transaction->voucher?->username,
            $transaction->voucher?->profile?->name,
            (float) $transaction->price,
            (float) $transaction->discount_amount,
            (float) $transaction->total_amount,
            $transaction->payment_method->label(),
            $transaction->payment_status->label(),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
