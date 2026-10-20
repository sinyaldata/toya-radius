<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Contracts\View\View;

class InvoicePrintController extends Controller
{
    public function regular(Invoice $invoice): View
    {
        $invoice->load(['customer.outlet']);

        return view('prints.invoice-regular', ['invoice' => $invoice]);
    }

    public function thermal(Invoice $invoice): View
    {
        $invoice->load(['customer.outlet']);

        return view('prints.invoice-thermal', ['invoice' => $invoice]);
    }
}
