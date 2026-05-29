<?php

namespace App\Http\Controllers;

use App\Services\ErpNextService;
use Illuminate\Http\Request;

class OnlineReportController extends Controller
{
    public function index()
    {
        $posProfile = \App\Models\Setting::get('erpnext_pos_profile', '');
        return view('reports.online', compact('posProfile'));
    }

    public function fetch(Request $request)
    {
        $request->validate([
            'date_from'   => 'required|date',
            'date_to'     => 'required|date|after_or_equal:date_from',
            'pos_profile' => 'nullable|string|max:255',
        ]);

        $erp    = new ErpNextService();
        $result = $erp->fetchPosInvoices(
            $request->date_from,
            $request->date_to,
            $request->input('pos_profile', '')
        );

        if (!$result['success']) {
            return response()->json(['success' => false, 'error' => $result['error']], 422);
        }

        $invoices = collect($result['data']);

        $totalSales = $invoices->sum('grand_total');
        $totalCount = $invoices->count();
        $avgPerTx   = $totalCount > 0 ? round($totalSales / $totalCount) : 0;

        // Agregasi per hari untuk chart
        $dailyData = $invoices
            ->groupBy('posting_date')
            ->map(fn($group) => [
                'count' => $group->count(),
                'total' => $group->sum('grand_total'),
            ])
            ->sortKeys();

        return response()->json([
            'success'   => true,
            'invoices'  => $result['data'],
            'truncated' => $result['truncated'],
            'stats'     => [
                'total_sales' => $totalSales,
                'total_count' => $totalCount,
                'avg_per_tx'  => $avgPerTx,
                'daily_data'  => $dailyData,
            ],
        ]);
    }

    public function detail(string $name)
    {
        $erp    = new ErpNextService();
        $result = $erp->fetchPosInvoiceDetail($name);

        if (!$result['success']) {
            return response()->json(['success' => false, 'error' => $result['error']], 422);
        }

        return response()->json(['success' => true, 'data' => $result['data']]);
    }
}
