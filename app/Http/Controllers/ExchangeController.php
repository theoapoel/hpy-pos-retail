<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Warehouse;
use App\Services\ErpNextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tukar Barang — kebijakan toko: barang boleh ditukar, uang tidak kembali.
 *
 * Dua pintu masuk:
 *  - Dari transaksi lokal (create/store): retur menunjuk transaksi POS ini.
 *  - Dari struk ERP HPY (index/lookup/storeErp): invoice asal dibaca langsung
 *    dari ERP HPY — dipakai saat penjualannya tidak tercatat di POS lokal.
 *
 * Satu kejadian tukar menghasilkan DUA transaksi:
 *  1. Transaksi retur (type=return): qty & nominal negatif, dibayar dengan MOP
 *     RETURN negatif. Disinkron sebagai POS Invoice is_return yang menunjuk
 *     invoice asal di ERP HPY.
 *  2. Transaksi penjualan barang pengganti: porsi senilai retur dibayar MOP
 *     RETURN, selisihnya (barang baru wajib >= nilai retur) dengan MOP pilihan
 *     kasir. Kas hanya bergerak sebesar selisih.
 */
class ExchangeController extends Controller
{
    /** Nama Mode of Payment di ERP HPY untuk porsi tukar. */
    private const MOP_RETURN = 'RETURN';

    public function __construct(private ErpNextService $erp) {}

    // ═════════════════════════ Alur struk ERP HPY ═════════════════════════

    public function index()
    {
        $paymentMethods = $this->diffPaymentMethods();

        return view('exchange.erp', compact('paymentMethods'));
    }

    /**
     * Baca POS Invoice dari ERP HPY dan hitung sisa qty yang masih boleh
     * diretur per item (dikurangi retur yang sudah ada di ERP).
     */
    public function lookup(Request $request)
    {
        $request->validate(['invoice' => 'required|string|max:140']);
        $docname = trim($request->input('invoice'));

        if (! $this->erp->isConfigured() || ! $this->erp->isReachable()) {
            return response()->json(['success' => false, 'error' => 'ERP HPY tidak terjangkau — alur struk ERP butuh koneksi.'], 422);
        }

        $doc = $this->erp->fetchPosInvoice($docname);
        if (! $doc['success']) {
            return response()->json(['success' => false, 'error' => 'Invoice tidak ditemukan di ERP HPY: '.$docname], 422);
        }
        $data = $doc['data'];

        if ((int) ($data['docstatus'] ?? 0) !== 1) {
            return response()->json(['success' => false, 'error' => 'Invoice ini belum submitted / sudah dibatalkan di ERP HPY.'], 422);
        }
        if (! empty($data['is_return'])) {
            return response()->json(['success' => false, 'error' => 'Invoice ini sendiri adalah retur — tidak bisa diretur lagi.'], 422);
        }

        $returned = $this->erp->returnedQtyAgainst($docname);
        if (! $returned['success']) {
            return response()->json(['success' => false, 'error' => $returned['error']], 422);
        }
        $returnedQty = $returned['returned'] ?? [];

        // Item ERP harus punya padanan produk lokal (untuk stok & baris transaksi).
        $codes = collect($data['items'] ?? [])->pluck('item_code')->filter()->all();
        $products = Product::whereIn('erp_item_code', $codes)->get()->keyBy('erp_item_code');

        $items = [];
        foreach (($data['items'] ?? []) as $row) {
            $code = $row['item_code'] ?? '';
            $qty = (float) ($row['qty'] ?? 0);
            $alreadyReturned = (float) ($returnedQty[$code] ?? 0);

            $items[] = [
                'item_code' => $code,
                'item_name' => $row['item_name'] ?? $code,
                'qty' => $qty,
                'remaining' => max(0, $qty - $alreadyReturned),
                // rate = harga bersih per unit setelah diskon item di ERP.
                'rate' => (float) ($row['rate'] ?? 0),
                'uom' => $row['uom'] ?? '',
                'product_id' => $products[$code]->id ?? null,
                'local_missing' => ! isset($products[$code]),
            ];
        }

        return response()->json([
            'success' => true,
            'invoice' => $data['name'],
            'customer' => $data['customer_name'] ?? $data['customer'] ?? '',
            'posting_date' => $data['posting_date'] ?? '',
            'grand_total' => (float) ($data['grand_total'] ?? 0),
            'consolidated' => ! empty($data['consolidated_invoice']),
            'items' => $items,
        ]);
    }

    /**
     * Proses tukar berbasis struk ERP HPY. Berbeda dengan alur lokal, invoice
     * retur WAJIB berhasil terkirim ke ERP sebelum tukar dianggap sah —
     * validasi qty retur yang sesungguhnya ada di ERP (data asal bukan milik
     * kita), jadi penolakan ERP harus membatalkan seluruh tukar.
     */
    public function storeErp(Request $request)
    {
        $request->validate([
            'erp_invoice' => 'required|string|max:140',
            'returns' => 'required|array|min:1',
            'returns.*.item_code' => 'required|string',
            'returns.*.quantity' => 'required|integer|min:1',
            'new_items' => 'required|array|min:1',
            'new_items.*.product_id' => 'required|exists:products,id',
            'new_items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'nullable|string',
        ]);

        if (! $this->erp->isConfigured() || ! $this->erp->isReachable()) {
            return response()->json(['success' => false, 'error' => 'ERP HPY tidak terjangkau — tukar dari struk ERP butuh koneksi.'], 422);
        }

        $docname = trim($request->input('erp_invoice'));

        // Baca ulang dari ERP di sisi server — data dari browser tidak dipercaya.
        $doc = $this->erp->fetchPosInvoice($docname);
        if (! $doc['success'] || (int) ($doc['data']['docstatus'] ?? 0) !== 1 || ! empty($doc['data']['is_return'])) {
            return response()->json(['success' => false, 'error' => 'Invoice '.$docname.' tidak valid untuk diretur.'], 422);
        }
        $erpItems = collect($doc['data']['items'] ?? [])->keyBy('item_code');

        $returned = $this->erp->returnedQtyAgainst($docname);
        if (! $returned['success']) {
            return response()->json(['success' => false, 'error' => $returned['error']], 422);
        }
        $returnedQty = $returned['returned'] ?? [];

        DB::beginTransaction();
        try {
            $defaultWarehouse = Warehouse::getDefault();

            // ── 1. Item retur dari baris invoice ERP ─────────────────────
            $returnItems = [];
            $returnValue = 0.0;

            foreach ($request->returns as $ret) {
                $code = $ret['item_code'];
                $erpRow = $erpItems[$code] ?? null;
                if (! $erpRow) {
                    throw new \RuntimeException("Item {$code} tidak ada di invoice {$docname}.");
                }

                $qty = (int) $ret['quantity'];
                $remaining = (float) ($erpRow['qty'] ?? 0) - (float) ($returnedQty[$code] ?? 0);
                if ($qty > $remaining) {
                    throw new \RuntimeException(
                        "Qty retur {$code} melebihi sisa yang bisa diretur (".max(0, (int) $remaining).').'
                    );
                }

                $product = Product::where('erp_item_code', $code)->first();
                if (! $product) {
                    throw new \RuntimeException(
                        "Item {$code} belum ada di produk lokal. Jalankan Pull Produk / Sync Semua dulu."
                    );
                }

                $rate = (float) ($erpRow['rate'] ?? 0);
                $lineValue = $rate * $qty;
                $returnValue += $lineValue;

                $returnItems[] = [
                    'product_id' => $product->id,
                    'product_name' => $erpRow['item_name'] ?? $product->name,
                    'product_sku' => $product->sku,
                    'price' => $rate,
                    'cost_price' => $product->cost_price,
                    'quantity' => -$qty,
                    'discount_amount' => 0,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'subtotal' => -$lineValue,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // ── 2. Barang pengganti + kebijakan selisih ──────────────────
            [$newItems, $newSubtotal, $newTax] = $this->buildNewItems($request->new_items, $defaultWarehouse);
            $newTotal = $newSubtotal + $newTax;
            $selisih = round($newTotal - $returnValue, 2);

            $diffMethod = $this->assertNoRefund($returnValue, $newTotal, $selisih, $request->input('payment_method'));

            // ── 3. Transaksi retur + kembalikan stok lokal ───────────────
            $this->restoreStock($returnItems, $defaultWarehouse);

            $returnTx = Transaction::create([
                'invoice_no' => Transaction::generateInvoiceNo('RTN'),
                'user_id' => Auth::id(),
                'customer_id' => null,
                'status' => 'completed',
                'type' => 'return',
                'return_against_erp' => $docname,
                'subtotal' => -$returnValue,
                'tax_amount' => 0,
                'total' => -$returnValue,
                'paid_amount' => -$returnValue,
                'change_amount' => 0,
                'payment_method' => self::MOP_RETURN,
                'notes' => 'Tukar barang atas struk ERP HPY '.$docname,
                'erp_sync_status' => 'pending',
            ]);
            $returnTx->items()->insert(array_map(
                fn ($i) => array_merge($i, ['transaction_id' => $returnTx->id]), $returnItems
            ));

            // ── 4. Transaksi penjualan pengganti ─────────────────────────
            $saleTx = $this->createSaleTransaction($newItems, $newSubtotal, $newTax, $returnValue, $selisih, $diffMethod, null, 'Barang pengganti tukar atas struk ERP HPY '.$docname);

            // ── 5. Retur WAJIB tembus ke ERP sebelum commit ──────────────
            // Validasi sisa qty yang final ada di ERP; kalau ditolak, seluruh
            // tukar dibatalkan utuh — stok kembali, tidak ada struk terbit.
            $sync = $this->erp->syncTransaction($returnTx->load('items.product'), false);
            if (! ($sync['success'] ?? false)) {
                throw new \RuntimeException('ERP HPY menolak retur: '.($sync['error'] ?? 'tidak diketahui'));
            }

            DB::commit();

            // Penjualan pengganti: gagal sync tidak membatalkan (masuk antrean).
            $warnings = [];
            try {
                $saleSync = $this->erp->syncTransaction($saleTx->load('items.product', 'customer'), false);
                if (! ($saleSync['success'] ?? false)) {
                    $warnings[] = $saleTx->invoice_no.': '.($saleSync['error'] ?? 'gagal sync — menunggu di antrean');
                }
            } catch (\Exception $e) {
                $warnings[] = $saleTx->invoice_no.': '.$e->getMessage();
            }

            return $this->exchangeResponse($returnTx, $saleTx, $returnValue, $newTotal, $selisih, $warnings);

        } catch (\RuntimeException $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ═════════════════════════ Alur transaksi lokal ═══════════════════════

    public function create(Transaction $transaction)
    {
        $error = $this->exchangeableError($transaction);
        if ($error) {
            return redirect()->route('transactions.index')->with('error', $error);
        }

        $transaction->load('items.product', 'customer', 'user');
        $returnable = $this->returnableQuantities($transaction);
        $paymentMethods = $this->diffPaymentMethods();

        return view('exchange.create', compact('transaction', 'returnable', 'paymentMethods'));
    }

    public function store(Request $request, Transaction $transaction)
    {
        $error = $this->exchangeableError($transaction);
        if ($error) {
            return response()->json(['success' => false, 'error' => $error], 422);
        }

        $request->validate([
            'returns' => 'required|array|min:1',
            'returns.*.item_id' => 'required|integer',
            'returns.*.quantity' => 'required|integer|min:1',
            'new_items' => 'required|array|min:1',
            'new_items.*.product_id' => 'required|exists:products,id',
            'new_items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $transaction->load('items.product');
            $returnable = $this->returnableQuantities($transaction);
            $defaultWarehouse = Warehouse::getDefault();

            // ── 1. Susun item retur dari item transaksi asal ─────────────
            $returnItems = [];
            $returnSubtotal = 0.0;
            $returnTax = 0.0;

            foreach ($request->returns as $ret) {
                $orig = $transaction->items->firstWhere('id', (int) $ret['item_id']);
                if (! $orig) {
                    throw new \RuntimeException('Item retur tidak ditemukan pada transaksi asal.');
                }

                $qty = (int) $ret['quantity'];
                $max = $returnable[$orig->id] ?? 0;
                if ($qty > $max) {
                    throw new \RuntimeException(
                        "Qty retur {$orig->product_name} melebihi sisa yang bisa diretur ({$max})."
                    );
                }

                // Nilai per unit mengikuti transaksi asal secara proporsional
                // (harga, diskon item, pajak) supaya nilai retur = nilai beli.
                $unitDisc = $orig->quantity > 0 ? (float) $orig->discount_amount / $orig->quantity : 0.0;
                $unitTax = $orig->quantity > 0 ? (float) $orig->tax_amount / $orig->quantity : 0.0;
                $lineSubtotal = ((float) $orig->price - $unitDisc) * $qty; // sebelum pajak
                $lineTax = $unitTax * $qty;

                $returnSubtotal += $lineSubtotal;
                $returnTax += $lineTax;

                $returnItems[] = [
                    'product_id' => $orig->product_id,
                    'product_name' => $orig->product_name,
                    'product_sku' => $orig->product_sku,
                    'price' => $orig->price,
                    'cost_price' => $orig->cost_price,
                    'quantity' => -$qty,
                    'discount_amount' => -($unitDisc * $qty),
                    'tax_rate' => $orig->tax_rate,
                    'tax_amount' => -$lineTax,
                    'subtotal' => -($lineSubtotal + $lineTax),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $returnValue = $returnSubtotal + $returnTax;

            // ── 2. Barang pengganti + kebijakan selisih ──────────────────
            [$newItems, $newSubtotal, $newTax] = $this->buildNewItems($request->new_items, $defaultWarehouse);
            $newTotal = $newSubtotal + $newTax;
            $selisih = round($newTotal - $returnValue, 2);

            $diffMethod = $this->assertNoRefund($returnValue, $newTotal, $selisih, $request->input('payment_method'));

            // ── 3. Transaksi retur (negatif) + kembalikan stok barang lama ─
            $this->restoreStock($returnItems, $defaultWarehouse);

            $returnTx = Transaction::create([
                'invoice_no' => Transaction::generateInvoiceNo('RTN'),
                'user_id' => Auth::id(),
                'customer_id' => $transaction->customer_id,
                'status' => 'completed',
                'type' => 'return',
                'return_against_id' => $transaction->id,
                'subtotal' => -$returnSubtotal,
                'tax_amount' => -$returnTax,
                'total' => -$returnValue,
                'paid_amount' => -$returnValue,
                'change_amount' => 0,
                'payment_method' => self::MOP_RETURN,
                'notes' => 'Tukar barang atas '.$transaction->invoice_no,
                'pos_class' => $transaction->pos_class,
                'erp_sync_status' => 'pending',
            ]);
            $returnTx->items()->insert(array_map(
                fn ($i) => array_merge($i, ['transaction_id' => $returnTx->id]), $returnItems
            ));

            // ── 4. Transaksi penjualan pengganti ──────────────────────────
            $saleTx = $this->createSaleTransaction($newItems, $newSubtotal, $newTax, $returnValue, $selisih, $diffMethod, $transaction->customer_id, 'Barang pengganti tukar atas '.$transaction->invoice_no, $transaction->pos_class);

            // ── 5. Sync ke ERP HPY (retur dulu, lalu penjualan) ───────────
            // Gagal sync tidak menggagalkan tukar: transaksinya tetap sah dan
            // tinggal disinkron ulang dari menu Sinkronisasi (urutan lama→baru
            // di sana menjamin retur terkirim setelah invoice asal).
            $warnings = [];
            $canSync = Setting::get('erp_auto_sync', '1') === '1'
                && $this->erp->isConfigured()
                && $this->erp->isReachable();

            DB::commit();

            if ($canSync) {
                foreach ([$returnTx, $saleTx] as $tx) {
                    try {
                        $sync = $this->erp->syncTransaction($tx->load('items.product', 'customer', 'returnAgainst'), false);
                        if (! ($sync['success'] ?? false)) {
                            $warnings[] = $tx->invoice_no.': '.($sync['error'] ?? 'gagal sync');
                        }
                    } catch (\Exception $e) {
                        $warnings[] = $tx->invoice_no.': '.$e->getMessage();
                    }
                }
            } else {
                $warnings[] = 'ERP HPY tidak terjangkau — kedua dokumen menunggu di antrean sync.';
            }

            return $this->exchangeResponse($returnTx, $saleTx, $returnValue, $newTotal, $selisih, $warnings);

        } catch (\RuntimeException $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ═════════════════════════ Helper bersama ═════════════════════════════

    /** Metode pembayaran untuk selisih — RETURN sendiri disembunyikan. */
    private function diffPaymentMethods(): array
    {
        return collect(pos_payment_methods())
            ->reject(fn ($m) => strcasecmp($m['mode_of_payment'] ?? '', self::MOP_RETURN) === 0)
            ->values()->all();
    }

    /**
     * Susun baris item barang pengganti (harga produk saat ini) sambil
     * memotong stok lokal.
     *
     * @return array{0: array, 1: float, 2: float} [items, subtotal, tax]
     */
    private function buildNewItems(array $requested, ?Warehouse $defaultWarehouse): array
    {
        $items = [];
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($requested as $ni) {
            $product = Product::findOrFail($ni['product_id']);
            $qty = (int) $ni['quantity'];
            $price = (float) $product->price;
            $taxRate = (float) ($product->tax_rate ?? 0);
            $lineSubtotal = $price * $qty;
            $lineTax = $lineSubtotal * ($taxRate / 100);

            $subtotal += $lineSubtotal;
            $tax += $lineTax;

            if ($product->track_stock) {
                $product->decrement('stock', $qty);
                if ($defaultWarehouse) {
                    ProductStock::forProductWarehouse($product->id, $defaultWarehouse->id)
                        ->decrementQty($qty);
                }
            }

            $items[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'price' => $price,
                'cost_price' => $product->cost_price,
                'quantity' => $qty,
                'discount_amount' => 0,
                'tax_rate' => $taxRate,
                'tax_amount' => $lineTax,
                'subtotal' => $lineSubtotal + $lineTax,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return [$items, $subtotal, $tax];
    }

    /**
     * Tegakkan kebijakan tidak-ada-uang-kembali dan pastikan metode bayar
     * selisih terisi bila perlu. Mengembalikan nama metode selisih.
     */
    private function assertNoRefund(float $returnValue, float $newTotal, float $selisih, ?string $method): string
    {
        if ($selisih < 0) {
            throw new \RuntimeException(
                'Total barang pengganti (Rp '.number_format($newTotal, 0, ',', '.').') lebih kecil dari nilai retur (Rp '
                .number_format($returnValue, 0, ',', '.').'). Tambah barang sampai minimal senilai retur.'
            );
        }

        $method = trim((string) $method);
        if ($selisih > 0 && $method === '') {
            throw new \RuntimeException('Ada selisih tambah bayar — pilih metode pembayarannya.');
        }

        return $method;
    }

    /** Kembalikan stok lokal untuk item retur (qty item negatif). */
    private function restoreStock(array $returnItems, ?Warehouse $defaultWarehouse): void
    {
        foreach ($returnItems as $ri) {
            $product = $ri['product_id'] ? Product::find($ri['product_id']) : null;
            if ($product?->track_stock) {
                $qtyBack = -$ri['quantity'];
                $product->increment('stock', $qtyBack);
                if ($defaultWarehouse) {
                    ProductStock::forProductWarehouse($product->id, $defaultWarehouse->id)
                        ->incrementQty($qtyBack);
                }
            }
        }
    }

    private function createSaleTransaction(
        array $newItems,
        float $newSubtotal,
        float $newTax,
        float $returnValue,
        float $selisih,
        string $diffMethod,
        ?int $customerId,
        string $notes,
        ?string $posClass = null,
    ): Transaction {
        $paymentDetails = [self::MOP_RETURN => $returnValue];
        if ($selisih > 0) {
            $paymentDetails[$diffMethod] = $selisih;
        }

        $saleTx = Transaction::create([
            'invoice_no' => Transaction::generateInvoiceNo(),
            'user_id' => Auth::id(),
            'customer_id' => $customerId,
            'status' => 'completed',
            'type' => 'sale',
            'subtotal' => $newSubtotal,
            'tax_amount' => $newTax,
            'total' => $newSubtotal + $newTax,
            'paid_amount' => $newSubtotal + $newTax,
            'change_amount' => 0,
            'payment_method' => 'mixed',
            'payment_details' => $paymentDetails,
            'notes' => $notes,
            'pos_class' => $posClass,
            'erp_sync_status' => 'pending',
        ]);
        $saleTx->items()->insert(array_map(
            fn ($i) => array_merge($i, ['transaction_id' => $saleTx->id]), $newItems
        ));

        return $saleTx;
    }

    private function exchangeResponse(Transaction $returnTx, Transaction $saleTx, float $returnValue, float $newTotal, float $selisih, array $warnings)
    {
        return response()->json([
            'success' => true,
            'return_invoice' => $returnTx->invoice_no,
            'sale_invoice' => $saleTx->invoice_no,
            'return_value' => $returnValue,
            'new_total' => $newTotal,
            'selisih' => $selisih,
            'print_url' => route('pos.print', $saleTx->id),
            'print_return_url' => route('pos.print', $returnTx->id),
            'warning' => $warnings ? implode(' | ', $warnings) : null,
        ]);
    }

    /** Alasan transaksi tidak bisa ditukar; null bila boleh. */
    private function exchangeableError(Transaction $transaction): ?string
    {
        if ($transaction->status !== 'completed') {
            return 'Hanya transaksi selesai yang bisa ditukar.';
        }
        if ($transaction->isReturn()) {
            return 'Transaksi retur tidak bisa diretur lagi.';
        }
        if (! $transaction->erp_pos_invoice) {
            return 'Transaksi ini belum tersinkron ke ERP HPY — sinkronkan dulu sebelum tukar barang, supaya retur bisa menunjuk POS Invoice asalnya.';
        }

        return null;
    }

    /**
     * Sisa qty yang masih boleh diretur per item transaksi asal:
     * qty terjual dikurangi seluruh retur sebelumnya (per produk).
     */
    private function returnableQuantities(Transaction $transaction): array
    {
        $returnedPerProduct = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.return_against_id', $transaction->id)
            ->where('transactions.status', 'completed')
            ->whereNull('transactions.deleted_at')
            ->groupBy('transaction_items.product_id')
            ->selectRaw('transaction_items.product_id, SUM(-transaction_items.quantity) as returned')
            ->pluck('returned', 'product_id');

        $result = [];
        foreach ($transaction->items as $item) {
            $returned = (int) ($returnedPerProduct[$item->product_id] ?? 0);
            // Retur sebelumnya dihitung per produk, dikurangkan berurutan dari
            // baris item pertama produk itu.
            $take = min($item->quantity, $returned);
            $result[$item->id] = max(0, $item->quantity - $take);
            $returnedPerProduct[$item->product_id] = $returned - $take;
        }

        return $result;
    }
}
