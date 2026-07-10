<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\ErpNextService;
use Illuminate\Http\Request;

class ModeOfPaymentReportController extends Controller
{
    public function index()
    {
        $posProfile = Setting::get('erpnext_pos_profile', '');

        return view('reports.mode-of-payment', compact('posProfile'));
    }

    public function fetch(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'pos_profile' => 'nullable|string|max:255',
        ]);

        $erp = new ErpNextService;

        // Ambil invoice untuk membangun peta nama → tanggal
        $result = $erp->fetchPosInvoices(
            $request->date_from,
            $request->date_to,
            $request->input('pos_profile', '')
        );

        if (! $result['success']) {
            return response()->json(['success' => false, 'error' => $result['error']], 422);
        }

        $invoices = collect($result['data']);

        // nama POS Invoice → posting_date
        $nameToDate = $invoices->pluck('posting_date', 'name')->all();

        // Jumlah transaksi (dokumen) per tanggal
        $txPerDate = $invoices
            ->groupBy('posting_date')
            ->map(fn ($g) => $g->count())
            ->all();

        $payment = $erp->fetchPosPaymentMatrix($nameToDate);
        if (! $payment['success']) {
            return response()->json(['success' => false, 'error' => $payment['error']], 422);
        }

        $modes = $payment['data']['modes'];
        $matrix = $payment['data']['matrix'];

        // Bangun baris tabel per tanggal + total kolom
        $rows = [];
        $modeTotals = array_fill_keys($modes, ['count' => 0, 'total' => 0.0]);
        $grandCount = 0;
        $grandTotal = 0.0;

        foreach ($matrix as $date => $byMode) {
            $cells = [];
            $rowTotal = 0.0;
            $rowCount = 0;
            foreach ($modes as $mode) {
                $cell = $byMode[$mode] ?? ['count' => 0, 'total' => 0.0];
                $cells[$mode] = $cell;
                $rowTotal += $cell['total'];
                $rowCount += $cell['count'];
                $modeTotals[$mode]['count'] += $cell['count'];
                $modeTotals[$mode]['total'] += $cell['total'];
            }
            $rows[] = [
                'date' => $date,
                'tx_count' => $txPerDate[$date] ?? 0,
                'cells' => $cells,
                'total' => $rowTotal,
                'count' => $rowCount,
            ];
            $grandCount += $rowCount;
            $grandTotal += $rowTotal;
        }

        return response()->json([
            'success' => true,
            'truncated' => $result['truncated'],
            'modes' => $modes,
            'rows' => $rows,
            'totals' => [
                'per_mode' => $modeTotals,
                'grand_count' => $grandCount,
                'grand_total' => $grandTotal,
            ],
        ]);
    }
}
