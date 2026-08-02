<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Warehouse;
use App\Services\ErpNextService;
use App\Services\ThermalPrintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function kasirRedirect()
    {
        $layout = Setting::get('pos_layout', 'index');

        return match ($layout) {
            'quick' => redirect()->route('pos.quick'),
            'express' => redirect()->route('pos.express'),
            default => redirect()->route('pos.index'),
        };
    }

    public function quickIndex()
    {
        $categories = Category::filteredByGroups('pos_item_groups')->get();
        $customers = Customer::where('is_active', true)->get(['id', 'code', 'name', 'phone', 'loyalty_points']);
        $storeSettings = SettingsController::storeSettings();
        $posClass = $storeSettings['pos_class'] ?? '';
        $posPaymentMethods = pos_payment_methods();
        $erpBaseUrl = rtrim(Setting::get('erpnext_url', ''), '/');

        return view('pos.quick', compact(
            'categories', 'customers', 'posClass',
            'erpBaseUrl', 'storeSettings', 'posPaymentMethods'
        ));
    }

    public function expressIndex()
    {
        $categories = Category::filteredByGroups('pos_item_groups')->get();
        $customers = Customer::where('is_active', true)->get(['id', 'code', 'name', 'phone', 'loyalty_points']);
        $storeSettings = SettingsController::storeSettings();
        $posClass = $storeSettings['pos_class'] ?? '';
        $posPaymentMethods = pos_payment_methods();
        $erpBaseUrl = rtrim(Setting::get('erpnext_url', ''), '/');

        return view('pos.express', compact(
            'categories', 'customers', 'posClass',
            'erpBaseUrl', 'storeSettings', 'posPaymentMethods'
        ));
    }

    public function index()
    {
        $categories = Category::filteredByGroups('pos_item_groups')->get();
        $products = Product::active()->inItemGroups('pos_item_groups')->with('category')->get();
        $customers = Customer::where('is_active', true)->get(['id', 'code', 'name', 'phone', 'loyalty_points']);
        $storeSettings = SettingsController::storeSettings();
        $posClass = $storeSettings['pos_class'] ?? '';
        $posProductDisplay = $storeSettings['pos_product_display'] ?? 'image';
        $posPaymentMethods = pos_payment_methods();
        $erpBaseUrl = rtrim(Setting::get('erpnext_url', ''), '/');

        return view('pos.index', compact(
            'categories', 'products', 'customers', 'posClass', 'posProductDisplay',
            'erpBaseUrl', 'storeSettings', 'posPaymentMethods'
        ));
    }

    public function searchProducts(Request $request)
    {
        $term = $request->get('q', '');
        $categoryId = $request->get('category_id');

        $query = Product::active()->with('category')->inItemGroups('pos_item_groups');

        if ($term) {
            $query->search($term);
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->limit(50)->get();

        return response()->json($products->map(function ($p) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'price' => $p->price,
                'stock' => $p->stock,
                'unit' => $p->unit,
                'tax_rate' => $p->tax_rate,
                'track_stock' => (bool) $p->track_stock,
                'image' => $p->image,
                'category' => $p->category?->name,
                'category_id' => $p->category_id,
                'category_color' => $p->category?->color ?? '#4285F4',
                'is_low_stock' => $p->isLowStock(),
                'erp_item_code' => $p->erp_item_code,
            ];
        }));
    }

    public function validateCoupon(Request $request)
    {
        $code = strtoupper(trim($request->code ?? ''));
        $subtotal = (float) ($request->subtotal ?? 0);

        if (! $code) {
            return response()->json(['valid' => false, 'message' => 'Kode kupon tidak boleh kosong']);
        }

        $coupon = Coupon::where('code', $code)->first();
        if (! $coupon) {
            return response()->json(['valid' => false, 'message' => 'Kode kupon tidak ditemukan']);
        }

        $check = $coupon->isValid($subtotal);
        if (! $check['valid']) {
            return response()->json(['valid' => false, 'message' => $check['message']]);
        }

        $discount = $coupon->calculateDiscount($subtotal);

        return response()->json([
            'valid' => true,
            'message' => $check['message'],
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'description' => $coupon->description,
                'discount_type' => $coupon->discount_type,
                'discount_value' => (float) $coupon->discount_value,
                'calculated_discount' => $discount,
            ],
        ]);
    }

    /**
     * Saldo & nilai tukar poin milik satu pelanggan, diambil langsung dari ERP.
     *
     * Poin sengaja tidak dihitung lokal: ERP membuat Loyalty Point Entry sendiri
     * dari POS Invoice yang kita kirim, jadi kalau POS ikut menghitung, kedua
     * angkanya pasti melenceng. Kolom customers.loyalty_points hanya cache.
     */
    public function loyaltyDetails(Customer $customer)
    {
        if (! $customer->erp_customer_name) {
            return response()->json(['has_program' => false, 'points' => 0, 'reason' => 'not_linked']);
        }

        $erp = new ErpNextService;
        if (! $erp->isConfigured()) {
            // ERP tidak tersedia — tampilkan saldo hasil pull terakhir, tapi jangan
            // izinkan penukaran, karena angkanya belum tentu masih berlaku.
            return response()->json([
                'has_program' => false,
                'points' => (float) $customer->loyalty_points,
                'reason' => 'erp_offline',
            ]);
        }

        $result = $erp->getLoyaltyDetails($customer->erp_customer_name, $customer->erp_loyalty_program);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'has_program' => false,
                'points' => (float) $customer->loyalty_points,
                'reason' => 'erp_error',
                'error' => $result['error'] ?? null,
            ]);
        }

        // Segarkan cache lokal supaya badge di daftar pelanggan ikut akurat.
        $customer->update([
            'loyalty_points' => $result['points'],
            'erp_loyalty_program' => $result['loyalty_program'] ?? null,
            'loyalty_synced_at' => now(),
        ]);

        return response()->json([
            'has_program' => $result['has_program'],
            'loyalty_program' => $result['loyalty_program'] ?? null,
            'points' => $result['points'],
            'conversion_factor' => $result['conversion_factor'] ?? 0,
            'expiry_date' => $result['expiry_date'] ?? null,
        ]);
    }

    /**
     * Validasi penukaran poin terhadap ERP dan hitung nilai rupiahnya.
     *
     * @return array{points: float, amount: float, program: ?string, warning: ?string}
     *
     * @throws \RuntimeException bila poin tidak cukup / program tidak ada
     */
    private function resolveLoyaltyRedemption(?int $customerId, float $requestedPoints, float $total): array
    {
        $none = ['points' => 0.0, 'amount' => 0.0, 'program' => null, 'warning' => null];

        if ($requestedPoints <= 0 || ! $customerId) {
            return $none;
        }

        $customer = Customer::find($customerId);
        if (! $customer?->erp_customer_name) {
            throw new \RuntimeException('Pelanggan belum tertaut ke ERP, poin tidak bisa ditukar.');
        }

        $erp = new ErpNextService;

        // ERP mati bukan alasan untuk menolak transaksi — poin dilewati saja dan
        // kasir diberi tahu. Saldo poin tidak ikut terpotong, jadi pelanggan bisa
        // menukarnya lagi nanti; tidak ada risiko saldo minus.
        if (! $erp->isConfigured() || ! $erp->isReachable()) {
            return array_merge($none, [
                'warning' => 'ERP HPY tidak terjangkau — poin TIDAK ditukar pada transaksi ini. Saldo poin pelanggan tetap utuh.',
            ]);
        }

        $details = $erp->getLoyaltyDetails($customer->erp_customer_name, $customer->erp_loyalty_program);

        if (! ($details['success'] ?? false)) {
            throw new \RuntimeException('Gagal memeriksa saldo poin di ERP: '.($details['error'] ?? 'unknown'));
        }

        if (! ($details['has_program'] ?? false)) {
            throw new \RuntimeException('Pelanggan ini tidak terdaftar pada Loyalty Program manapun.');
        }

        // Saldo di ERP bisa negatif (ada pelanggan HPY yang tercatat minus karena
        // penukaran melebihi perolehan). Kondisi di bawah menolaknya dengan sendirinya.
        $available = (float) $details['points'];

        // Field loyalty_points di POS Invoice bertipe Int — poin selalu bilangan bulat.
        $points = (int) floor($requestedPoints);

        if ($points > $available) {
            throw new \RuntimeException('Poin tidak cukup. Saldo tersedia: '.(int) floor($available).'.');
        }

        $factor = (float) ($details['conversion_factor'] ?? 0);
        if ($factor <= 0) {
            throw new \RuntimeException('Loyalty Program belum punya conversion factor di ERP.');
        }

        // Nilai penukaran tidak boleh melebihi tagihan — ERP menolak invoice yang
        // loyalty_amount-nya lebih besar dari grand_total, dan kembalian dari poin
        // bukan hal yang wajar di kasir.
        $amount = round($points * $factor, 2);

        if ($amount > $total) {
            $points = (int) floor($total / $factor);
            $amount = round($points * $factor, 2);
        }

        if ($points <= 0) {
            return $none;
        }

        return ['points' => (float) $points, 'amount' => $amount, 'program' => $details['loyalty_program'], 'warning' => null];
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            // Customer wajib: setiap POS Invoice harus punya pelanggan terdaftar.
            'customer_id' => 'required|exists:customers,id',
            'payment_method' => 'required|string',
            'paid_amount' => 'required|numeric|min:0',
            // Pembayaran campuran: {Mode of Payment => nominal}. Dipakai ErpNextService
            // untuk menulis satu baris `payments` per metode di POS Invoice.
            'payment_details' => 'nullable|array',
            'payment_details.*' => 'numeric|min:0',
            'loyalty_points' => 'nullable|numeric|min:0',
        ], [
            'customer_id.required' => 'Customer wajib diisi.',
        ]);

        DB::beginTransaction();
        try {
            $defaultWarehouse = Warehouse::getDefault();
            $subtotal = 0;
            $taxAmount = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $qty = $item['quantity'];
                $price = $item['price'];
                $discount = $item['discount_amount'] ?? 0;
                $taxRate = $product->tax_rate ?? 0;
                $itemSubtotal = ($price * $qty) - $discount;
                $itemTax = $itemSubtotal * ($taxRate / 100);

                $subtotal += $itemSubtotal;
                $taxAmount += $itemTax;

                // Update stock
                if ($product->track_stock) {
                    $product->decrement('stock', $qty);
                    if ($defaultWarehouse) {
                        ProductStock::forProductWarehouse($product->id, $defaultWarehouse->id)
                            ->decrementQty($qty);
                    }
                }

                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'price' => $price,
                    'cost_price' => $product->cost_price,
                    'quantity' => $qty,
                    'discount_amount' => $discount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $itemTax,
                    'subtotal' => $itemSubtotal + $itemTax,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $discountAmount = $request->discount_amount ?? 0;
            $discountPercent = $request->discount_percent ?? 0;
            if ($discountPercent > 0) {
                $discountAmount = $subtotal * ($discountPercent / 100);
            }

            // Coupon discount
            $couponCode = null;
            $couponDiscount = 0;
            if ($request->coupon_code) {
                $coupon = Coupon::where('code', strtoupper(trim($request->coupon_code)))->first();
                if ($coupon) {
                    $check = $coupon->isValid($subtotal);
                    if ($check['valid']) {
                        $couponCode = $coupon->code;
                        $couponDiscount = $coupon->calculateDiscount($subtotal);
                        $coupon->increment('used_count');
                    }
                }
            }

            $total = $subtotal + $taxAmount - $discountAmount - $couponDiscount;

            // Penukaran poin tidak mengurangi `total` — di ERP grand_total tetap penuh
            // dan poin menutup sebagian tagihan lewat akun penukaran loyalty. Yang
            // berkurang hanyalah jumlah yang harus dibayar kasir.
            $loyalty = $this->resolveLoyaltyRedemption(
                $request->customer_id,
                (float) ($request->loyalty_points ?? 0),
                $total
            );

            $amountDue = max(0, $total - $loyalty['amount']);
            $paidAmount = $request->paid_amount;
            $change = $paidAmount - $amountDue;

            $transaction = Transaction::create([
                'invoice_no' => Transaction::generateInvoiceNo(),
                'user_id' => Auth::id(),
                'customer_id' => $request->customer_id,
                'status' => 'completed',
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'discount_percent' => $discountPercent,
                'coupon_code' => $couponCode,
                'coupon_discount' => $couponDiscount,
                'loyalty_points_redeemed' => $loyalty['points'],
                'loyalty_amount' => $loyalty['amount'],
                'loyalty_program' => $loyalty['program'],
                'tax_amount' => $taxAmount,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'change_amount' => max(0, $change),
                'payment_method' => $request->payment_method,
                'payment_details' => $request->payment_details,
                'notes' => $request->notes,
                'pos_class' => $request->pos_class,
                'erp_sync_status' => 'pending',
            ]);

            $transaction->items()->insert(array_map(fn ($i) => array_merge($i, ['transaction_id' => $transaction->id]), $itemsData));

            // Update customer total purchase
            if ($request->customer_id) {
                Customer::where('id', $request->customer_id)
                    ->increment('total_purchase', $total);
            }

            DB::commit();

            // Auto-sync to ERPNext if configured, reachable, and auto-sync is enabled
            try {
                $autoSync = Setting::get('erp_auto_sync', '1') === '1';
                $erp = new ErpNextService;
                // isReachable() wajib diperiksa, bukan cuma isConfigured(): kalau ERP
                // hidup tapi menggantung, tiap panggilan di bawah menunggu sampai 30
                // detik dan kasir mengira POS-nya mati. Transaksi sudah ter-commit di
                // atas, jadi melewati sync sepenuhnya aman — statusnya tetap pending.
                if ($autoSync && $erp->isConfigured() && $erp->isReachable()) {
                    $erp->syncTransaction($transaction->load('items.product', 'customer'));

                    // Setelah invoice masuk, ERP menambah (dan bila ditukar, memotong)
                    // Loyalty Point Entry. Tarik ulang saldonya supaya badge di kasir
                    // tidak menampilkan angka sebelum transaksi ini.
                    $cust = $transaction->customer;
                    if ($cust?->erp_customer_name) {
                        $details = $erp->getLoyaltyDetails($cust->erp_customer_name, $cust->erp_loyalty_program);
                        if ($details['success'] ?? false) {
                            $cust->update([
                                'loyalty_points' => $details['points'],
                                'loyalty_synced_at' => now(),
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Silent — sync failure must not affect checkout response
            }

            return response()->json([
                'success' => true,
                'transaction' => $transaction->load('items.product', 'customer', 'user'),
                'invoice_no' => $transaction->invoice_no,
                'warning' => $loyalty['warning'] ?? null,
            ]);

        } catch (\RuntimeException $e) {
            // Penukaran poin ditolak — ini kesalahan input kasir, bukan error server.
            DB::rollBack();

            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function receipt(Transaction $transaction)
    {
        $transaction->load('items.product', 'customer', 'user');
        $store = SettingsController::storeSettings();

        return view('pos.receipt', compact('transaction', 'store'));
    }

    public function printReceipt(Transaction $transaction)
    {
        $transaction->load('items.product', 'customer', 'user');
        $store = SettingsController::storeSettings();

        return view('pos.print-receipt', compact('transaction', 'store'));
    }

    public function directPrint(Transaction $transaction)
    {
        $transaction->load('items.product', 'customer', 'user');
        $store = SettingsController::storeSettings();

        try {
            (new ThermalPrintService)->printReceipt($transaction, $store);

            return response()->json([
                'success' => true,
                'message' => 'Struk berhasil dikirim ke printer.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
