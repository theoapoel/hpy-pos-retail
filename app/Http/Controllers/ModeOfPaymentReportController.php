<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\ErpNextService;
use Illuminate\Http\Request;

class ModeOfPaymentReportController extends Controller
{
    /**
     * Redeem poin dilaporkan di kolom terpisah (`loyalty`) oleh report, di luar
     * kolom `amount` — jadi bisa ditampilkan sebagai komponen bayar tersendiri
     * tanpa menggandakan nilai mode lain.
     */
    private const LOYALTY_MODE = 'Loyalty Point (Redeem)';

    public function index()
    {
        // Kasir (non-manager) hanya melihat transaksinya sendiri — bila pengaturan
        // toko "Cakupan Laporan Transaksi" diset per kasir.
        $user = auth()->user();
        $scopedToUser = Setting::reportScopedByUser() && $user && ! $user->isManager();
        $scopedUserName = $scopedToUser ? $user->name : null;

        return view('reports.mode-of-payment', compact('scopedToUser', 'scopedUserName'));
    }

    public function fetch(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $erp = new ErpNextService;

        // Sumber tunggal: report "POS Sales Mode of Payment" — sudah teragregasi
        // per tanggal × kasir × metode, split payment terpecah ke tiap metodenya.
        $result = $erp->fetchPosSalesModeOfPayment($request->date_from, $request->date_to);

        if (! $result['success']) {
            return response()->json(['success' => false, 'error' => $result['error']], 422);
        }

        // Kasir (non-manager) dibatasi ke barisnya sendiri (kolom `kasir` = email ERP
        // User), hanya bila cakupan laporan diset per kasir. Report tidak menyediakan
        // filter ini di server, jadi disaring di sini.
        $user = auth()->user();
        $onlyCashier = (Setting::reportScopedByUser() && $user && ! $user->isManager()) ? $user->email : '';

        $matrix = [];      // tanggal => mode => total
        $byCashier = [];   // kasir   => mode => total
        $modeTotals = [];  // mode    => total (untuk urutan kolom)

        foreach ($result['data'] as $row) {
            $date = $row['tanggal'] ?? null;
            if ($date === null) {
                continue;
            }

            $cashier = $row['kasir'] ?: 'Lainnya';
            if ($onlyCashier && $cashier !== $onlyCashier) {
                continue;
            }

            $mode = $row['mop'] ?: 'Tanpa Metode';
            $amount = (float) ($row['amount'] ?? 0);

            $matrix[$date][$mode] = ($matrix[$date][$mode] ?? 0) + $amount;
            $byCashier[$cashier][$mode] = ($byCashier[$cashier][$mode] ?? 0) + $amount;
            $modeTotals[$mode] = ($modeTotals[$mode] ?? 0) + $amount;

            $loyalty = (float) ($row['loyalty'] ?? 0);
            if ($loyalty > 0) {
                $lm = self::LOYALTY_MODE;
                $matrix[$date][$lm] = ($matrix[$date][$lm] ?? 0) + $loyalty;
                $byCashier[$cashier][$lm] = ($byCashier[$cashier][$lm] ?? 0) + $loyalty;
                $modeTotals[$lm] = ($modeTotals[$lm] ?? 0) + $loyalty;
            }
        }

        arsort($modeTotals);
        $modes = array_keys($modeTotals);
        ksort($matrix);
        ksort($byCashier);

        // Bangun baris tabel per tanggal + total kolom
        $rows = [];
        $modeTotals = array_fill_keys($modes, 0.0);
        $grandTotal = 0.0;

        foreach ($matrix as $date => $byMode) {
            $cells = [];
            $rowTotal = 0.0;
            foreach ($modes as $mode) {
                $cell = (float) ($byMode[$mode] ?? 0);
                $cells[$mode] = $cell;
                $rowTotal += $cell;
                $modeTotals[$mode] += $cell;
            }
            $rows[] = [
                'date' => $date,
                'cells' => $cells,
                'total' => $rowTotal,
            ];
            $grandTotal += $rowTotal;
        }

        // Rekap per kasir (kolom `kasir` = email ERP User). Petakan email → nama lokal.
        $emails = array_keys($byCashier);
        $cashierNames = User::whereIn('email', $emails)->pluck('name', 'email')->all();

        $cashierRows = [];
        foreach ($byCashier as $email => $byMode) {
            $cells = [];
            $rowTotal = 0.0;
            foreach ($modes as $mode) {
                $cell = (float) ($byMode[$mode] ?? 0);
                $cells[$mode] = $cell;
                $rowTotal += $cell;
            }
            $cashierRows[] = [
                'cashier' => $cashierNames[$email] ?? $email,
                'email' => $email,
                'cells' => $cells,
                'total' => $rowTotal,
            ];
        }
        // Urutkan kasir dari total terbesar
        usort($cashierRows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return response()->json([
            'success' => true,
            'modes' => $modes,
            // Dipakai view untuk menandai kolom redeem poin (bukan uang masuk).
            'loyalty_mode' => self::LOYALTY_MODE,
            'rows' => $rows,
            'cashier_rows' => $cashierRows,
            'totals' => [
                'per_mode' => $modeTotals,
                'grand_total' => $grandTotal,
            ],
        ]);
    }
}
