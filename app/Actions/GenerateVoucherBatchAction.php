<?php

namespace App\Actions;

use App\DTO\VoucherGenerationData;
use App\Models\Outlet;
use App\Models\ServicePackage;
use App\Services\SaldoService;
use App\Services\VoucherGeneratorService;

class GenerateVoucherBatchAction
{
    public function __construct(
        protected VoucherGeneratorService $voucherGeneratorService,
        protected SaldoService $saldoService,
    ) {}

    public function execute(array $formData): \Illuminate\Support\Collection
    {
        // ATURAN SALDO -- hanya berlaku untuk voucher yang dibuat LEWAT
        // BOT TELEGRAM (permintaan user).
        //
        // Alasannya: generate lewat APLIKASI hanya bisa dilakukan orang
        // yang punya akses panel admin (admin/super admin), yaitu pihak
        // yang justru MENGELOLA saldo itu sendiri -- memaksanya top up
        // dulu tidak ada gunanya, malah menghalangi pekerjaan. Sedangkan
        // lewat Telegram, yang menekan tombol adalah outlet/reseller,
        // jadi di situ saldo memang berfungsi sebagai pembatas.
        //
        // Jadi urutan pemeriksaannya:
        //   1. Dibuat lewat aplikasi  -> saldo TIDAK disentuh sama sekali.
        //   2. Dibuat lewat Telegram  -> saldo dipotong HANYA kalau
        //      mitranya bertipe Reseller (kebijakan: yang pakai saldo
        //      cuma Reseller). Outlet TIDAK PERNAH wajib saldo, sama
        //      sekali tidak dicek/dipotong -- lihat Outlet::requiresSaldo().
        //      Reseller tertentu masih bisa dikecualikan manual lewat
        //      toggle "Bebas Saldo" (is_saldo_exempt) di form Reseller.
        //
        // CATATAN PENTING kalau suatu saat aturan ini dipertanyakan:
        // karena jalur aplikasi tidak lagi memotong saldo, dan Outlet
        // memang sudah tidak wajib saldo sama sekali, outlet yang
        // saldonya habis (atau memang tidak pernah top up) tetap bisa
        // generate voucher lewat Telegram. Itu memang konsekuensi yang
        // dikehendaki, bukan celah yang terlewat.
        $dariTelegram = ($formData['generated_via'] ?? 'app')
            === \App\Enums\VoucherSource::Telegram->value;

        if ($dariTelegram && ! empty($formData['outlet_id'])) {
            $outlet = Outlet::find($formData['outlet_id']);
            $package = ServicePackage::find($formData['service_package_id']);
            $quantity = (int) ($formData['quantity'] ?? 1);

            if ($outlet && $package && $outlet->requiresSaldo()) {
                $totalHarga = (float) $package->cost_price * $quantity;

                $this->saldoService->deduct(
                    $outlet,
                    $totalHarga,
                    "Generate {$quantity} voucher {$package->name}"
                );
            }
        }

        $data = VoucherGenerationData::fromArray(array_merge($formData, [
            'generated_by' => auth()->id(),
        ]));

        return $this->voucherGeneratorService->generate($data);
    }
}
