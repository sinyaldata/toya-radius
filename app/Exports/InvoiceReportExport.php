<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Invoice" (matriks Pelanggan x 12 Bulan) ke Excel.
 * Data-nya sudah dalam bentuk matriks siap-pakai (dibangun oleh
 * InvoiceReportPage::getMatrix() -- lihat komentar di sana untuk
 * penjelasan lengkap cara sel terisi, termasuk penanganan pembayaran
 * dimuka), supaya Excel/PDF/tabel di halaman selalu konsisten
 * (satu sumber data yang sama).
 */
class InvoiceReportExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    public function __construct(protected \Illuminate\Support\Collection $matrix, protected int $tahun) {}

    public function collection()
    {
        return $this->matrix->values()->map(function ($row, $index) {
            $row['no'] = $index + 1;

            return $row;
        });
    }

    public function headings(): array
    {
        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return array_merge(['No.', 'Nama Pelanggan', 'Outlet'], $bulan, ['Total']);
    }

    public function map($row): array
    {
        $bulanan = array_map(fn ($nominal) => $nominal !== null ? (float) $nominal : null, $row['bulanan']);

        return array_merge(
            [$row['no'], $row['customer']->name, $row['customer']->outlet->name ?? '-'],
            $bulanan,
            [(float) $row['total']]
        );
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
