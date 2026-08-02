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

        // Cocokkan nomor invoice HPY dengan transaksi lokal (erp_pos_invoice → invoice_no)
        $localMap = \App\Models\Transaction::whereIn('erp_pos_invoice', $invoices->pluck('name')->filter()->all())
            ->pluck('invoice_no', 'erp_pos_invoice');

        // Metode bayar per transaksi diambil dari report POS Register (split payment
        // muncul sebagai mode gabungan, mis. "BCA QR, CASH").
        $register = $erp->fetchPosRegister(
            $request->date_from,
            $request->date_to,
            $request->input('pos_profile', '')
        );

        if (!$register['success']) {
            return response()->json(['success' => false, 'error' => $register['error']], 422);
        }

        // Redeem Loyalty Point per invoice. Poin yang ditukar tidak muncul sebagai
        // baris payments di POS Invoice, padahal ikut menutup grand_total — kalau
        // diabaikan, nilai tiap mode bayar jadi lebih besar dari uang yang benar-benar
        // masuk. Nilainya dipisah jadi komponen "Loyalty Point" tersendiri.
        $loyalty = $erp->fetchLoyaltyRedemptions(
            $request->date_from,
            $request->date_to,
            $request->input('pos_profile', '')
        );
        $loyaltyByInvoice = $loyalty['data'];

        $modeByInvoice = collect($register['data'])
            ->pluck('mode_of_payment', 'pos_invoice')
            ->all();

        $invoices = $invoices->map(function ($inv) use ($localMap, $modeByInvoice, $loyaltyByInvoice) {
            $inv['local_invoice']    = $localMap[$inv['name']] ?? null;
            $inv['mode_of_payment']  = $modeByInvoice[$inv['name']] ?? null;
            $inv['loyalty_amount']   = (float) ($loyaltyByInvoice[$inv['name']]['amount'] ?? 0);
            $inv['loyalty_points']   = (float) ($loyaltyByInvoice[$inv['name']]['points'] ?? 0);
            return $inv;
        });

        // Statistik & chart dihitung dari POS Register, bukan dari $invoices: daftar
        // invoice dibatasi 1.000 baris, sedangkan POS Register mengembalikan seluruh
        // rentang. Kalau dihitung dari $invoices, kartu total akan lebih kecil daripada
        // rincian metode bayar di halaman yang sama.
        $registerRows = collect($register['data']);

        $totalSales = $registerRows->sum(fn($r) => (float) $r['grand_total']);
        $totalCount = $registerRows->count();
        $avgPerTx   = $totalCount > 0 ? round($totalSales / $totalCount) : 0;

        // Agregasi per hari untuk chart
        $dailyData = $registerRows
            ->groupBy('posting_date')
            ->map(fn($group) => [
                'count' => $group->count(),
                'total' => $group->sum(fn($r) => (float) $r['grand_total']),
            ])
            ->sortKeys();

        // Agregasi per metode pembayaran, dari POS Register. Nilainya pakai grand_total
        // (bukan paid_amount) — lihat catatan di ErpNextService::fetchPosRegister() —
        // dikurangi redeem loyalty invoice tersebut, supaya tiap mode hanya memuat uang
        // yang benar-benar diterima.
        $paymentData = $registerRows
            ->groupBy(fn($r) => $r['mode_of_payment'] ?: 'Tanpa Metode')
            ->map(fn($g, $mode) => [
                'mode_of_payment' => $mode,
                'count'           => $g->count(),
                'total'           => $g->sum(fn($r) => (float) $r['grand_total']
                    - (float) ($loyaltyByInvoice[$r['pos_invoice']]['amount'] ?? 0)),
            ])
            ->values();

        // Hanya invoice yang ikut terhitung di POS Register yang dipakai, supaya
        // total kolom tetap sama dengan omzet di kartu atas.
        $registerInvoices = $registerRows->pluck('pos_invoice')->flip();
        $loyaltyRows = collect($loyaltyByInvoice)
            ->filter(fn($v, $name) => $registerInvoices->has($name));

        $loyaltyTotal  = $loyaltyRows->sum('amount');
        $loyaltyPoints = $loyaltyRows->sum('points');

        if ($loyaltyTotal > 0) {
            $paymentData->push([
                'mode_of_payment' => 'Loyalty Point (Redeem)',
                'count'           => $loyaltyRows->count(),
                'total'           => $loyaltyTotal,
            ]);
        }

        $paymentData = $paymentData->sortByDesc('total')->values()->all();

        return response()->json([
            'success'   => true,
            'invoices'  => $invoices->values()->all(),
            'truncated' => $result['truncated'],
            'stats'     => [
                'total_sales' => $totalSales,
                'total_count' => $totalCount,
                'avg_per_tx'  => $avgPerTx,
                'daily_data'  => $dailyData,
                'payment_data' => $paymentData,
                'loyalty_total'  => $loyaltyTotal,
                'loyalty_points' => $loyaltyPoints,
                'loyalty_count'  => $loyaltyRows->count(),
                'loyalty_error'  => $loyalty['success'] ? null : ($loyalty['error'] ?? null),
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
