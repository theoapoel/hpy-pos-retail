<?php

namespace App\Http\Controllers;

use App\Services\ErpNextService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Laporan Daily Sales — cerminan report "Daily Sales V2" di ERP HPY, diagregasi
 * per hari, per brand, dan per item supaya bisa dibaca tanpa membuka ERP.
 *
 * Brand tidak bisa difilter di SQL report-nya (query-nya cuma menerima rentang
 * tanggal), jadi seluruh brand ikut terambil lalu penyaringan dilakukan di sisi
 * klien — sekali ambil, ganti brand tanpa request ulang.
 */
class DailySalesReportController extends Controller
{
    /** Batas rentang tanggal; report ini satu baris per item per invoice. */
    private const MAX_DAYS = 92;

    public function index()
    {
        return view('reports.daily-sales');
    }

    public function fetch(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $from = Carbon::parse($request->date_from)->startOfDay();
        $to = Carbon::parse($request->date_to)->startOfDay();

        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            return response()->json([
                'success' => false,
                'error' => 'Rentang maksimal '.self::MAX_DAYS.' hari. Perkecil rentang tanggalnya.',
            ], 422);
        }

        $result = (new ErpNextService)->fetchDailySales(
            $from->format('Y-m-d'),
            $to->format('Y-m-d')
        );

        if (! $result['success']) {
            return response()->json(['success' => false, 'error' => $result['error']], 422);
        }

        return response()->json(array_merge(['success' => true], $this->aggregate($result['data'])));
    }

    /**
     * Ringkas baris mentah report jadi tiga tabel: harian (tanggal × brand),
     * per item, dan daftar brand. Nilai uang diambil apa adanya dari report
     * supaya angkanya sama persis dengan yang dilihat di ERP.
     *
     * @param  array<int,array>  $rows
     */
    private function aggregate(array $rows): array
    {
        $daily = [];        // "tanggal|brand" => bucket
        $items = [];        // "barcode|item|brand" => bucket
        $brandNet = [];     // brand => net (untuk urutan)
        $dailyInvoices = []; // tanggal => [invoice => true]
        $brandInvoices = []; // "tanggal|brand" => [invoice => true]
        $allInvoices = [];

        foreach ($rows as $r) {
            $date = $r['tanggal'] ?? null;
            if (! $date) {
                continue;
            }
            $date = substr((string) $date, 0, 10);

            $brand = trim((string) ($r['brand'] ?? '')) ?: 'Tanpa Brand';
            $invoice = (string) $r['name'];
            $qty = (float) ($r['qty'] ?? 0);
            $net = (float) ($r['net'] ?? 0);
            $cogs = (float) ($r['cogs_value'] ?? 0);
            $profit = (float) ($r['profit_value'] ?? 0);
            $disc = (float) ($r['disc'] ?? 0);

            $dKey = $date.'|'.$brand;
            if (! isset($daily[$dKey])) {
                $daily[$dKey] = ['date' => $date, 'brand' => $brand, 'qty' => 0.0, 'net' => 0.0, 'cogs' => 0.0, 'profit' => 0.0, 'disc' => 0.0];
            }
            $daily[$dKey]['qty'] += $qty;
            $daily[$dKey]['net'] += $net;
            $daily[$dKey]['cogs'] += $cogs;
            $daily[$dKey]['profit'] += $profit;
            $daily[$dKey]['disc'] += $disc;

            // Supplier dari Item Supplier; kalau kosong pakai default supplier item.
            $supplier = trim((string) ($r['supplier'] ?? '')) ?: trim((string) ($r['supplier_default'] ?? ''));
            $iKey = ($r['barcode'] ?? '').'|'.($r['item_name'] ?? '').'|'.$brand;
            if (! isset($items[$iKey])) {
                $items[$iKey] = [
                    'barcode' => (string) ($r['barcode'] ?? ''),
                    'item_name' => (string) ($r['item_name'] ?? ''),
                    'group' => (string) ($r['group'] ?? ''),
                    'brand' => $brand,
                    'supplier' => $supplier ?: '-',
                    'qty' => 0.0, 'net' => 0.0, 'cogs' => 0.0, 'profit' => 0.0, 'disc' => 0.0,
                ];
            }
            $items[$iKey]['qty'] += $qty;
            $items[$iKey]['net'] += $net;
            $items[$iKey]['cogs'] += $cogs;
            $items[$iKey]['profit'] += $profit;
            $items[$iKey]['disc'] += $disc;

            $brandNet[$brand] = ($brandNet[$brand] ?? 0) + $net;
            $dailyInvoices[$date][$invoice] = true;
            $brandInvoices[$dKey][$invoice] = true;
            $allInvoices[$invoice] = true;
        }

        // Jumlah invoice: unik per tanggal (semua brand) dan per tanggal × brand.
        $daily = array_values($daily);
        foreach ($daily as $i => $d) {
            $daily[$i]['invoices'] = count($brandInvoices[$d['date'].'|'.$d['brand']] ?? []);
        }
        usort($daily, fn ($a, $b) => [$a['date'], $b['net']] <=> [$b['date'], $a['net']]);

        $items = array_values($items);
        usort($items, fn ($a, $b) => $b['net'] <=> $a['net']);

        arsort($brandNet);

        return [
            'brands' => array_keys($brandNet),
            'daily' => $daily,
            'items' => $items,
            'invoices_per_date' => array_map('count', $dailyInvoices),
            'total_invoices' => count($allInvoices),
            'row_count' => count($rows),
        ];
    }
}
