<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportPrintController extends Controller
{
    public function sales(Request $request, ReportService $reportService): View
    {
        $filters = $request->only(['from', 'to', 'outlet_id', 'area_id', 'cashier_id']);

        return view('exports.sales-report-print', [
            'rows' => $reportService->query($filters)->get(),
            'summary' => $reportService->summarize($filters),
            'filters' => $filters,
            'ispName' => Setting::get('isp_name', 'Toya Radius'),
        ]);
    }

    public function outlets(Request $request, ReportService $reportService): View
    {
        $filters = $request->only(['from', 'to', 'outlet_id', 'area_id', 'cashier_id']);

        return view('exports.outlet-report-print', [
            'outlets' => $reportService->outletBreakdown($filters),
            'filters' => $filters,
            'ispName' => Setting::get('isp_name', 'Toya Radius'),
        ]);
    }
}
