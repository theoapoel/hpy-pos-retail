<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\ErpNextService;
use Illuminate\Http\Request;

/**
 * Audit dokumen ERP terhadap transaksi kasir.
 *
 * Transaksi yang sudah ter-sync tidak otomatis benar: POS Invoice dibangun ulang
 * oleh ERP saat submit, dan bug pada payload bisa membuat dokumennya berbeda dari
 * nota kasir tanpa ada yang berstatus `failed`. Halaman ini membandingkan keduanya
 * baris per baris, lalu menyediakan koreksi batalkan-dan-terbitkan-ulang.
 */
class ErpAuditController extends Controller
{
    /** Selisih di bawah ini dianggap pembulatan, bukan kesalahan. */
    private const TOLERANSI = 0.5;

    /**
     * Batas nota per pemeriksaan. Tiap nota berarti satu panggilan API ke ERP,
     * jadi rentang tanggal yang lebar bisa memakan menit — lebih baik dibatasi
     * dan diberitahukan daripada request-nya mati di tengah jalan.
     */
    private const MAKS_NOTA = 300;

    public function __construct(private ErpNextService $erp) {}

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());
        $hanyaSelisih = $request->boolean('only_diff', true);
        $dijalankan = $request->boolean('run');

        $rows = [];
        $error = null;
        $terpotong = false;
        $diperiksa = 0;

        if ($dijalankan) {
            if (! $this->erp->isConfigured()) {
                $error = 'Koneksi ERP HPY belum dikonfigurasi.';
            } else {
                set_time_limit(0);

                $query = Transaction::whereNull('deleted_at')
                    ->where('erp_sync_status', 'synced')
                    ->whereNotNull('erp_pos_invoice')
                    ->whereBetween('created_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
                    ->with('items.product')
                    ->orderBy('created_at');

                $terpotong = $query->count() > self::MAKS_NOTA;

                foreach ($query->limit(self::MAKS_NOTA)->get() as $trx) {
                    $diperiksa++;
                    $row = $this->bandingkan($trx);

                    if (! $hanyaSelisih || $row['selisih'] || $row['error']) {
                        $rows[] = $row;
                    }
                }
            }
        }

        return view('sync.audit', [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hanyaSelisih' => $hanyaSelisih,
            'dijalankan' => $dijalankan,
            'rows' => $rows,
            'diperiksa' => $diperiksa,
            'terpotong' => $terpotong,
            'maksNota' => self::MAKS_NOTA,
            'error' => $error,
        ]);
    }

    /**
     * Bandingkan satu transaksi lokal dengan POS Invoice-nya di ERP.
     *
     * @return array{trx: Transaction, error: ?string, selisih: bool, ...}
     */
    private function bandingkan(Transaction $trx): array
    {
        $hasil = [
            'trx' => $trx,
            'error' => null,
            'selisih' => false,
            'consolidated' => null,
            'docstatus' => null,
            'total_lokal' => (float) $trx->total,
            'total_erp' => null,
            'selisih_total' => 0.0,
            'items' => [],
        ];

        $doc = $this->erp->fetchPosInvoice($trx->erp_pos_invoice);

        if (! $doc['success']) {
            $hasil['error'] = $doc['error'];

            return $hasil;
        }

        $data = $doc['data'];
        $hasil['consolidated'] = $data['consolidated_invoice'] ?? null;
        $hasil['docstatus'] = (int) ($data['docstatus'] ?? 0);
        $hasil['total_erp'] = (float) ($data['grand_total'] ?? 0);
        $hasil['selisih_total'] = $hasil['total_erp'] - $hasil['total_lokal'];

        if (abs($hasil['selisih_total']) > self::TOLERANSI) {
            $hasil['selisih'] = true;
        }

        // Baris ERP dikelompokkan per item_code: satu item bisa muncul lebih dari
        // sekali dalam satu invoice, dan yang bisa dibandingkan adalah totalnya.
        $erpPerItem = [];
        foreach (($data['items'] ?? []) as $row) {
            $code = $row['item_code'] ?? '';
            $erpPerItem[$code]['qty'] = ($erpPerItem[$code]['qty'] ?? 0) + (float) ($row['qty'] ?? 0);
            $erpPerItem[$code]['amount'] = ($erpPerItem[$code]['amount'] ?? 0) + (float) ($row['amount'] ?? 0);
            $erpPerItem[$code]['rate'] = (float) ($row['rate'] ?? 0);
            $erpPerItem[$code]['discount_amount'] = (float) ($row['discount_amount'] ?? 0);
        }

        foreach ($trx->items as $item) {
            $code = $item->product->erp_item_code ?? $item->product_sku;

            // Harga bersih yang seharusnya diterima ERP — rumus yang sama dengan
            // yang dipakai saat membangun payload POS Invoice.
            $disc = (float) $item->discount_amount;
            $unitDisc = $item->quantity > 0 ? $disc / $item->quantity : 0.0;
            $rateLokal = max(0, (float) $item->price - $unitDisc);
            $amountLokal = $rateLokal * $item->quantity;

            $erp = $erpPerItem[$code] ?? null;
            $amountErp = $erp['amount'] ?? null;
            $beda = $erp === null || abs($amountErp - $amountLokal) > self::TOLERANSI;

            if ($beda) {
                $hasil['selisih'] = true;
            }

            $hasil['items'][] = [
                'nama' => $item->product_name,
                'kode' => $code,
                'qty' => (float) $item->quantity,
                'diskon' => $disc,
                'rate_lokal' => $rateLokal,
                'rate_erp' => $erp['rate'] ?? null,
                'amount_lokal' => $amountLokal,
                'amount_erp' => $amountErp,
                'beda' => $beda,
            ];
        }

        return $hasil;
    }

    /**
     * Batalkan POS Invoice lama lalu terbitkan ulang dari data lokal.
     *
     * Data lokal tidak disentuh — yang salah hanya dokumen ERP. Satu-satunya kolom
     * lokal yang berubah adalah `erp_pos_invoice`, karena dokumen penggantinya
     * bernomor baru.
     */
    public function resync(Transaction $transaction)
    {
        $nomorLama = $transaction->erp_pos_invoice;
        $result = $this->erp->resyncTransaction($transaction);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Gagal menerbitkan ulang.',
                'consolidated' => $result['consolidated'] ?? false,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'invoice_lama' => $nomorLama,
            'invoice_baru' => $result['docname'],
        ]);
    }
}
