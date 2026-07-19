<?php

namespace App\Http\Controllers;

use App\Models\PosShift;
use App\Models\Setting;
use App\Services\ErpNextService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PosShiftController extends Controller
{
    private function tz(): string
    {
        return Setting::get('timezone', 'Asia/Jakarta') ?: 'Asia/Jakarta';
    }

    /** Status shift kasir yang sedang login (untuk POS mengetahui perlu buka kasir atau tidak). */
    public function current()
    {
        $shift = PosShift::openFor(auth()->id());

        return response()->json([
            'has_open_shift' => (bool) $shift,
            'shift' => $shift ? [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at->toIso8601String(),
                'opening_cash' => (float) $shift->opening_cash,
                'erp_opening_entry' => $shift->erp_opening_entry,
            ] : null,
        ]);
    }

    /** Buka Kasir → buat POS Opening Entry di ERP + shift lokal. */
    public function open(Request $request)
    {
        $request->validate([
            'opening_cash' => 'required|numeric|min:0',
        ]);

        $user = auth()->user();

        if (PosShift::openFor($user->id)) {
            return response()->json(['success' => false, 'error' => 'Masih ada shift terbuka. Tutup dulu sebelum membuka lagi.'], 422);
        }
        if (! $user->email) {
            return response()->json(['success' => false, 'error' => 'Akun kasir tidak punya email (harus sama dengan ERP User).'], 422);
        }

        $erp = new ErpNextService;

        // Bila kasir masih punya opening entry Open di ERP (mis. sisa dari sesi lain), pakai itu.
        $openingName = $erp->findOpenPosOpeningEntry($user->email);
        if (! $openingName) {
            $result = $erp->createPosOpeningEntry($user->email, (float) $request->opening_cash);
            if (! $result['success']) {
                return response()->json(['success' => false, 'error' => 'Gagal buka kasir di ERP: '.($result['error'] ?? 'Unknown')], 422);
            }
            $openingName = $result['docname'];
        }

        $shift = PosShift::create([
            'user_id' => $user->id,
            'pos_profile' => Setting::get('erpnext_pos_profile', ''),
            'status' => 'open',
            'opened_at' => now(),
            'opening_cash' => (float) $request->opening_cash,
            'erp_opening_entry' => $openingName,
            'erp_sync_status' => 'synced',
        ]);

        return response()->json(['success' => true, 'shift_id' => $shift->id, 'erp_opening_entry' => $openingName]);
    }

    /** Pratinjau rekonsiliasi sebelum tutup: expected per metode + ringkasan penjualan. */
    public function reconcile()
    {
        $shift = PosShift::openFor(auth()->id());
        if (! $shift) {
            return response()->json(['success' => false, 'error' => 'Tidak ada shift terbuka.'], 422);
        }

        $erp = new ErpNextService;
        $recon = $this->buildRecon($erp, $shift);
        if (! $recon['success']) {
            return response()->json($recon, 422);
        }

        return response()->json([
            'success' => true,
            'modes' => $recon['modes'],
            'totals' => $recon['totals'],
        ]);
    }

    /** Tutup Kasir → buat POS Closing Entry (dengan hitung kas fisik) + tutup shift lokal. */
    public function close(Request $request)
    {
        $request->validate([
            'counted' => 'required|array',          // { "CASH": 500000, "BCA QR": 100000, ... }
            'counted.*' => 'numeric|min:0',
        ]);

        $shift = PosShift::openFor(auth()->id());
        if (! $shift) {
            return response()->json(['success' => false, 'error' => 'Tidak ada shift terbuka.'], 422);
        }

        $user = auth()->user();
        $erp = new ErpNextService;

        $recon = $this->buildRecon($erp, $shift);
        if (! $recon['success']) {
            return response()->json($recon, 422);
        }

        $counted = $request->counted;
        $result = $erp->createPosClosingEntry(
            $shift->erp_opening_entry,
            $user->email,
            $shift->opened_at->setTimezone($this->tz())->format('Y-m-d H:i:s'),
            $recon,
            $counted
        );

        if (! $result['success']) {
            return response()->json(['success' => false, 'error' => 'Gagal tutup kasir di ERP: '.($result['error'] ?? 'Unknown')], 422);
        }

        // Snapshot per metode (expected vs counted) untuk struk & audit.
        $breakdown = [];
        $cashExpected = 0.0;
        $cashCounted = 0.0;
        foreach ($recon['modes'] as $m) {
            $mode = $m['mode_of_payment'];
            $cnt = (float) ($counted[$mode] ?? $m['expected_amount']);
            $breakdown[] = [
                'mode' => $mode,
                'is_cash' => $m['is_cash'],
                'expected' => $m['expected_amount'],
                'counted' => $cnt,
                'difference' => round($cnt - $m['expected_amount'], 2),
            ];
            if ($m['is_cash']) {
                $cashExpected += $m['expected_amount'];
                $cashCounted += $cnt;
            }
        }

        $shift->update([
            'status' => 'closed',
            'closed_at' => now(),
            'expected_cash' => round($cashExpected, 2),
            'counted_cash' => round($cashCounted, 2),
            'cash_difference' => round($cashCounted - $cashExpected, 2),
            'total_sales' => $recon['totals']['grand_total'],
            'invoice_count' => $recon['totals']['invoice_count'],
            'payment_breakdown' => $breakdown,
            'erp_closing_entry' => $result['docname'],
            'erp_sync_status' => 'synced',
        ]);

        return response()->json([
            'success' => true,
            'shift_id' => $shift->id,
            'erp_closing_entry' => $result['docname'],
            'print_url' => route('pos-shift.receipt', $shift),
        ]);
    }

    /** Struk tutup kasir (X/Z report). */
    public function receipt(PosShift $shift)
    {
        abort_unless($shift->user_id === auth()->id() || auth()->user()->isManager(), 403);
        $shift->load('user');

        return view('pos.shift-receipt', ['shift' => $shift]);
    }

    /** Bangun rekonsiliasi untuk shift (rentang tanggal lokal opened_at → hari ini). */
    private function buildRecon(ErpNextService $erp, PosShift $shift): array
    {
        $from = $shift->opened_at->setTimezone($this->tz())->format('Y-m-d');
        $to = Carbon::now($this->tz())->format('Y-m-d');

        return $erp->getShiftReconciliation(
            $shift->user->email,
            $from,
            $to,
            (float) $shift->opening_cash
        );
    }
}
