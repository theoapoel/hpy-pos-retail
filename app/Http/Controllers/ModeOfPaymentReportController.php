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

        // Kasir (non-manager) hanya melihat transaksinya sendiri — bila pengaturan
        // toko "Cakupan Laporan Transaksi" diset per kasir.
        $user = auth()->user();
        $scopedToUser = Setting::reportScopedByUser() && $user && ! $user->isManager();
        $scopedUserName = $scopedToUser ? $user->name : null;

        return view('reports.mode-of-payment', compact('posProfile', 'scopedToUser', 'scopedUserName'));
    }

    public function fetch(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'pos_profile' => 'nullable|string|max:255',
        ]);

        $erp = new ErpNextService;

        // Kasir (non-manager) dibatasi ke invoice miliknya sendiri
        // (owner POS Invoice = email ERP User kasir), hanya bila cakupan laporan
        // diset per kasir. Default 'all' → semua invoice toko.
        $user = auth()->user();
        $owner = (Setting::reportScopedByUser() && $user && ! $user->isManager()) ? $user->email : '';

        // Ambil invoice untuk membangun peta nama → tanggal
        $result = $erp->fetchPosInvoices(
            $request->date_from,
            $request->date_to,
            $request->input('pos_profile', ''),
            1000,
            $owner
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
        $byCashier = $payment['data']['by_cashier'] ?? [];

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

        // Rekap per kasir (owner POS Invoice = email ERP User). Petakan email → nama lokal.
        $owners = array_keys($byCashier);
        $ownerNames = \App\Models\User::whereIn('email', $owners)->pluck('name', 'email')->all();

        $cashierRows = [];
        foreach ($byCashier as $owner => $byMode) {
            $cells = [];
            $rowTotal = 0.0;
            $rowCount = 0;
            foreach ($modes as $mode) {
                $cell = $byMode[$mode] ?? ['count' => 0, 'total' => 0.0];
                $cells[$mode] = $cell;
                $rowTotal += $cell['total'];
                $rowCount += $cell['count'];
            }
            $cashierRows[] = [
                'cashier' => $ownerNames[$owner] ?? $owner,
                'email' => $owner,
                'cells' => $cells,
                'total' => $rowTotal,
                'count' => $rowCount,
            ];
        }
        // Urutkan kasir dari total terbesar
        usort($cashierRows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return response()->json([
            'success' => true,
            'truncated' => $result['truncated'],
            'modes' => $modes,
            'rows' => $rows,
            'cashier_rows' => $cashierRows,
            'totals' => [
                'per_mode' => $modeTotals,
                'grand_count' => $grandCount,
                'grand_total' => $grandTotal,
            ],
        ]);
    }
}
