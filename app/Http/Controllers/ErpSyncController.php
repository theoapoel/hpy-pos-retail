<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryPrice;
use App\Models\ErpSyncLog;
use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ErpNextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ErpSyncController extends Controller
{
    public function __construct(private ErpNextService $erp) {}

    public function index()
    {
        $stats = [
            'pending' => Transaction::where('erp_sync_status', 'pending')->where('status', 'completed')->count(),
            'synced' => Transaction::where('erp_sync_status', 'synced')->count(),
            'failed' => Transaction::where('erp_sync_status', 'failed')->count(),
        ];

        $recentLogs = ErpSyncLog::latest()->limit(20)->get();
        $failedTransactions = Transaction::where('erp_sync_status', 'failed')
            ->with('user')->latest()->limit(10)->get();

        $settings = [
            'erpnext_url' => Setting::get('erpnext_url', env('ERPNEXT_URL')),
            'erpnext_api_key' => Setting::get('erpnext_api_key', env('ERPNEXT_API_KEY')),
            'erpnext_api_secret' => Setting::get('erpnext_api_secret'),
            'erpnext_company' => Setting::get('erpnext_company', env('ERPNEXT_COMPANY')),
            'erpnext_pos_profile' => Setting::get('erpnext_pos_profile', env('ERPNEXT_POS_PROFILE')),
            'erpnext_walkin_customer' => Setting::get('erpnext_walkin_customer', 'Walk-in Customer'),
            'erpnext_price_list' => Setting::get('erpnext_price_list', ''),
            'delivery_pricelist_gofood' => Setting::get('delivery_pricelist_gofood', ''),
            'delivery_pricelist_grabfood' => Setting::get('delivery_pricelist_grabfood', ''),
            'delivery_pricelist_shopeefood' => Setting::get('delivery_pricelist_shopeefood', ''),
            'erpnext_naming_series' => Setting::get('erpnext_naming_series', 'ACC-PSINV-.YYYY.-'),
            'erpnext_currency' => Setting::get('erpnext_currency', 'IDR'),
            'erp_so_naming_series' => Setting::get('erp_so_naming_series', 'SAL-ORD-.YYYY.-'),
            'erp_dn_naming_series' => Setting::get('erp_dn_naming_series', 'MAT-DN-.YYYY.-'),
            'erpnext_tax_account' => Setting::get('erpnext_tax_account', ''),
            'service_charge_erp_type' => Setting::get('service_charge_erp_type', 'On Net Total'),
            'service_charge_erp_account' => Setting::get('service_charge_erp_account', ''),
            'pb1_erp_type' => Setting::get('pb1_erp_type', 'On Net Total'),
            'pb1_erp_account' => Setting::get('pb1_erp_account', ''),
            'erp_auto_sync' => Setting::get('erp_auto_sync', '1'),
            'stock_auto_sync' => Setting::get('stock_auto_sync', '0'),
            'pos_shift_enabled' => Setting::get('pos_shift_enabled', '0'),
        ];

        return view('sync.index', compact('stats', 'recentLogs', 'failedTransactions', 'settings'));
    }

    public function testConnection(Request $request)
    {
        $url = trim($request->input('url', ''));
        $apiKey = trim($request->input('api_key', ''));
        $apiSecret = trim($request->input('api_secret', ''));

        // Jika form mengirim nilai, test dengan nilai form (belum tersimpan)
        // Jika form kosong, fallback ke nilai tersimpan di DB
        if ($url) {
            $result = $this->erp->testConnectionWith($url, $apiKey, $apiSecret);
        } else {
            $result = $this->erp->testConnection();
        }

        return response()->json($result);
    }

    public function pingErp()
    {
        if (! $this->erp->isConfigured()) {
            return response()->json(['reachable' => false, 'reason' => 'not_configured']);
        }

        return response()->json(['reachable' => $this->erp->quickPing()]);
    }

    public function syncAll()
    {
        $result = $this->erp->syncPendingTransactions();

        return response()->json($result);
    }

    public function syncSingle(Transaction $transaction)
    {
        $transaction->load(['items.product', 'customer']);
        $result = $this->erp->syncTransaction($transaction);

        return response()->json($result);
    }

    public function retryFailed()
    {
        $failed = Transaction::where('erp_sync_status', 'failed')
            ->where('status', 'completed')
            ->update(['erp_sync_status' => 'pending']);

        return response()->json(['success' => true, 'reset' => $failed]);
    }

    public function pullPaymentMethods()
    {
        $result = $this->erp->pullPosPaymentMethods();

        return response()->json($result);
    }

    public function pullProducts(Request $request)
    {
        set_time_limit(0);

        $reset = $request->boolean('reset');

        $imported = 0;
        $updated = 0;
        $disabledDeactivated = 0;
        $page = 0;
        $pageSize = 100;
        $seenItemCodes = [];

        do {
            $result = $this->erp->pullProducts($pageSize, $page * $pageSize);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'],
                    'imported' => $imported,
                    'updated' => $updated,
                ], 422);
            }

            $batch = $result['data'];

            foreach ($batch as $item) {
                $seenItemCodes[] = $item['name'];

                $category = null;
                if (! empty($item['item_group'])) {
                    $category = Category::firstOrCreate(
                        ['name' => $item['item_group']],
                        ['slug' => Str::slug($item['item_group']), 'erp_item_group' => $item['item_group']]
                    );
                }

                $itemCategory = null;
                if (! empty($item['kategori'])) {
                    $itemCategory = ItemCategory::firstOrCreate(
                        ['name' => $item['kategori']],
                        ['erp_last_sync' => now()]
                    );
                    $itemCategory->update(['erp_last_sync' => now()]);
                }

                $exists = Product::where('erp_item_code', $item['name'])->first();
                $erpImage = $item['image'] ?? null;

                $data = [
                    'name' => $item['item_name'] ?? $item['name'],
                    'sku' => $item['item_code'] ?? $item['name'],
                    'price' => (float) ($item['standard_rate'] ?? 0),
                    'cost_price' => (float) ($item['valuation_rate'] ?? 0),
                    'unit' => $item['stock_uom'] ?? 'Nos',
                    'category_id' => $category?->id,
                    'item_category_id' => $itemCategory?->id,
                    'erp_item_code' => $item['name'],
                    'erp_last_sync' => now(),
                    'is_active' => ! ($item['disabled'] ?? false),
                ];

                // Barcode hanya ditulis kalau ERP memang punya. Kalau kosong, barcode
                // yang diisi manual di POS dibiarkan — jangan ditimpa null tiap sync.
                $erpBarcode = trim((string) ($item['barcode'] ?? ''));
                if ($erpBarcode !== '') {
                    $data['barcode'] = $erpBarcode;
                }

                // Download image only when ERPNext has one and the path has changed
                if ($erpImage && $erpImage !== ($exists?->erp_image)) {
                    $localPath = $this->erp->downloadProductImage($erpImage, $item['name']);
                    if ($localPath) {
                        $data['image'] = $localPath;
                        $data['erp_image'] = $erpImage;
                    }
                } elseif (! $erpImage && $exists?->erp_image) {
                    // Image removed on ERPNext side — clear local reference too
                    $data['image'] = null;
                    $data['erp_image'] = null;
                }

                $isDisabled = (bool) ($item['disabled'] ?? false);
                $isTemplate = (bool) ($item['has_variants'] ?? false); // Item Template ERPNext — bukan barang jual
                $excludeFromSale = $isDisabled || $isTemplate;

                // Item template/disabled tidak boleh aktif sebagai produk jual
                if ($excludeFromSale) {
                    $data['is_active'] = false;
                }

                if ($exists) {
                    if ($excludeFromSale && $exists->is_active) {
                        $disabledDeactivated++;
                    }
                    $exists->update($data);
                    $updated++;
                } else {
                    // Item template/disabled yang belum ada tidak perlu diimpor
                    if ($excludeFromSale) {
                        continue;
                    }
                    Product::create(array_merge($data, ['track_stock' => true]));
                    $imported++;
                }
            }

            $page++;

        } while (count($batch) >= $pageSize);

        $deactivated = 0;
        $categoriesPruned = 0;

        if ($reset) {
            // Produk lokal yang sudah punya erp_item_code tapi tidak lagi muncul di ERP saat ini —
            // dinonaktifkan (bukan dihapus, supaya riwayat transaksi/order lama tetap utuh).
            //
            // Jangan pakai whereNotIn($seenItemCodes): tiap item code jadi satu placeholder,
            // dan dengan ribuan item ERP query-nya menembus batas 65535 placeholder MySQL
            // (error 1390). Bandingkan di PHP, lalu update per potongan id.
            $seen = array_flip($seenItemCodes);

            $staleIds = Product::whereNotNull('erp_item_code')
                ->where('is_active', true)
                ->pluck('erp_item_code', 'id')
                ->reject(fn ($code) => isset($seen[$code]))
                ->keys()
                ->all();

            foreach (array_chunk($staleIds, 1000) as $chunk) {
                $deactivated += Product::whereIn('id', $chunk)->update(['is_active' => false]);
            }

            // Bersihkan kategori/item group lokal yang sudah tidak dipakai produk manapun
            $categoriesPruned = Category::doesntHave('products')->delete()
                + ItemCategory::doesntHave('products')->delete();
        }

        return response()->json([
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'total' => $imported + $updated,
            'deactivated' => $deactivated,
            'disabled_deactivated' => $disabledDeactivated,
            'categories_pruned' => $categoriesPruned,
        ]);
    }

    public function pullUsers()
    {
        $posProfile = Setting::get('erpnext_pos_profile', '');
        if (! $posProfile) {
            return response()->json(['success' => false, 'error' => 'POS Profile belum dikonfigurasi. Isi dan simpan terlebih dahulu.'], 422);
        }

        $result = $this->erp->syncUsersFromPosProfile($posProfile);

        if (! $result['success']) {
            return response()->json($result, 422);
        }

        $created = 0;
        $updated = 0;

        foreach ($result['users'] as $u) {
            $existing = User::where('email', $u['email'])->first();

            if (! $existing) {
                User::create([
                    'name' => $u['full_name'],
                    'email' => $u['email'],
                    'password' => Hash::make(Str::random(32)),
                    'role' => $u['role'],
                    'is_active' => true,
                ]);
                $created++;
            } else {
                // Update nama saja; role & PIN tetap sesuai setting lokal
                $existing->update(['name' => $u['full_name']]);
                $updated++;
            }
        }

        return response()->json([
            'success' => true,
            'total' => count($result['users']),
            'created' => $created,
            'updated' => $updated,
            'message' => "{$created} user baru ditambahkan, {$updated} user diperbarui dari POS Profile '{$posProfile}'.",
        ]);
    }

    /**
     * Tarik seluruh Customer dari ERP HPY ke lokal, berikut saldo poin loyalty-nya.
     *
     * Arah ini yang membuat kasir bisa jalan sama sekali: customer wajib diisi saat
     * checkout, sementara tabel lokal bisa saja kosong di instalasi baru.
     */
    public function pullCustomers(Request $request)
    {
        set_time_limit(0);

        if (! $this->erp->isConfigured()) {
            return response()->json(['success' => false, 'error' => 'Koneksi ERP HPY belum dikonfigurasi.'], 422);
        }

        $withLoyalty = $request->boolean('loyalty', true);

        $imported = 0;
        $updated = 0;
        $page = 0;
        $pageSize = 100;
        $erpNames = [];

        // Nomor urut kode lokal dihitung sekali, lalu dinaikkan di memori. Memanggil
        // Customer::generateCode() per baris akan query ulang tiap kali dan — karena
        // baris belum tentu ter-flush — bisa menghasilkan kode kembar.
        $lastCode = Customer::orderByDesc('id')->value('code');
        $seq = $lastCode ? (int) substr($lastCode, 4) : 0;

        do {
            $result = $this->erp->pullCustomers($pageSize, $page * $pageSize);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'],
                    'imported' => $imported,
                    'updated' => $updated,
                ], 422);
            }

            $batch = $result['data'];

            foreach ($batch as $row) {
                $docname = $row['name'] ?? null;
                if (! $docname) {
                    continue;
                }

                $erpNames[] = $docname;

                $data = [
                    'name' => ($row['customer_name'] ?? '') ?: $docname,
                    'email' => ($row['email_id'] ?? '') ?: null,
                    'phone' => ($row['mobile_no'] ?? '') ?: null,
                    'erp_customer_name' => $docname,
                    'erp_loyalty_program' => ($row['loyalty_program'] ?? '') ?: null,
                    'erp_last_sync' => now(),
                    'is_active' => true,
                ];

                // Cocokkan dulu lewat erp_customer_name; kalau belum pernah ditautkan,
                // pakai nama supaya customer yang terlanjur diketik manual di kasir
                // tersambung ke dokumen ERP-nya, bukan jadi baris kembar.
                $existing = Customer::where('erp_customer_name', $docname)->first()
                    ?: Customer::whereNull('erp_customer_name')->where('name', $data['name'])->first();

                if ($existing) {
                    $existing->update($data);
                    $updated++;
                } else {
                    $seq++;
                    Customer::create(array_merge($data, [
                        'code' => 'CUST'.str_pad($seq, 5, '0', STR_PAD_LEFT),
                    ]));
                    $imported++;
                }
            }

            $page++;

        } while (count($batch) >= $pageSize);

        $loyaltyUpdated = 0;
        $loyaltyError = null;

        if ($withLoyalty && $erpNames) {
            $result = $this->erp->fetchLoyaltyBalances();
            $balances = $result['balances'];

            if (! $result['success']) {
                // Ledger terbaca separuh jalan. Jangan ditulis sama sekali: halaman
                // yang belum terbaca membuat saldo tampak jauh lebih kecil dari
                // yang di ERP, dan itu justru sumber selisih yang paling sulit
                // dilacak karena angkanya terlihat wajar.
                $loyaltyError = ($result['error'] ?? 'gagal membaca Loyalty Point Entry')
                    .' — saldo poin tidak diperbarui agar tidak terisi angka separuh.';
                $balances = [];
            }

            // Hanya customer yang memang ada di ERP hasil tarikan ini yang disentuh.
            $wanted = array_flip($erpNames);

            // Pelanggan yang sama sekali tidak punya baris Loyalty Point Entry berarti
            // saldonya nol di ERP. Tanpa baris ini nilai lama di lokal akan bertahan
            // selamanya dan terlihat seperti "poin tidak update".
            if ($result['success']) {
                foreach ($erpNames as $erpName) {
                    if (! isset($balances[$erpName])) {
                        $balances[$erpName] = 0.0;
                    }
                }
            }

            foreach ($balances as $erpName => $points) {
                if (! isset($wanted[$erpName])) {
                    continue;
                }

                // Yang dilaporkan adalah jumlah pelanggan yang saldonya diproses,
                // bukan nilai balik update(). update() mengembalikan baris
                // terpengaruh, dan saldo yang kebetulan sama dengan nilai lama
                // menghasilkan 0 — angkanya jadi jauh lebih kecil dari kenyataan.
                Customer::where('erp_customer_name', $erpName)
                    ->update(['loyalty_points' => $points, 'loyalty_synced_at' => now()]);

                $loyaltyUpdated++;
            }
        }

        return response()->json([
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'total' => $imported + $updated,
            'loyalty_updated' => $loyaltyUpdated,
            'loyalty_error' => $loyaltyError,
        ]);
    }

    public function pushCustomer(Customer $customer)
    {
        $result = $this->erp->pushCustomer($customer);

        return response()->json($result);
    }

    public function pullDeliveryPrices()
    {
        $platforms = [
            'gofood' => Setting::get('delivery_pricelist_gofood', ''),
            'grabfood' => Setting::get('delivery_pricelist_grabfood', ''),
            'shopeefood' => Setting::get('delivery_pricelist_shopeefood', ''),
        ];

        $results = [];
        $errors = [];

        foreach ($platforms as $key => $priceList) {
            if (empty($priceList)) {
                $errors[] = ucfirst($key).': Price list belum dikonfigurasi';

                continue;
            }

            $result = $this->erp->getPriceListPrices($priceList);

            if (! $result['success']) {
                $errors[] = ucfirst($key).': '.$result['error'];

                continue;
            }

            $now = now();
            $rows = [];
            foreach ($result['prices'] as $itemCode => $price) {
                $rows[] = [
                    'erp_item_code' => $itemCode,
                    'platform' => $key,
                    'price' => (float) $price,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DeliveryPrice::where('platform', $key)->delete();
            if (! empty($rows)) {
                DeliveryPrice::insert($rows);
            }
            $results[$key] = $result['count'];
        }

        return response()->json([
            'success' => ! empty($results),
            'results' => $results,
            'errors' => $errors,
        ]);
    }

    /**
     * Perbarui harga jual produk lokal dari Item Price di ERP HPY, memakai price
     * list yang diset di halaman Sync HPY (field "Price List").
     *
     * Hanya kolom `price` yang disentuh — nama, kategori, gambar, dan stok tidak
     * ikut berubah, jadi tool ini aman dipakai sesering perlu tanpa menunggu
     * Pull Produk yang jauh lebih berat.
     */
    public function pullItemPrices()
    {
        set_time_limit(0);

        $priceList = trim((string) Setting::get('erpnext_price_list', ''));
        if ($priceList === '') {
            return response()->json([
                'success' => false,
                'error' => 'Price List belum dikonfigurasi. Isi field "Price List" di pengaturan Sync HPY, lalu simpan.',
            ], 422);
        }

        $result = $this->erp->getPriceListPrices($priceList);

        if (! $result['success']) {
            return response()->json(['success' => false, 'error' => $result['error']], 422);
        }

        $prices = $result['prices'];

        $updated = 0;
        $unchanged = 0;
        $matched = 0;

        // Bandingkan di PHP lalu update per item yang harganya benar-benar berubah,
        // supaya tidak menulis ulang ribuan baris tiap kali tool dijalankan.
        Product::whereNotNull('erp_item_code')
            ->select(['id', 'erp_item_code', 'price'])
            ->chunkById(500, function ($chunk) use ($prices, &$updated, &$unchanged, &$matched) {
                foreach ($chunk as $product) {
                    if (! array_key_exists($product->erp_item_code, $prices)) {
                        continue;
                    }
                    $matched++;

                    $newPrice = (float) $prices[$product->erp_item_code];
                    if (abs($newPrice - (float) $product->price) < 0.01) {
                        $unchanged++;

                        continue;
                    }

                    Product::whereKey($product->id)->update([
                        'price' => $newPrice,
                        'erp_last_sync' => now(),
                    ]);
                    $updated++;
                }
            });

        return response()->json([
            'success' => true,
            'price_list' => $priceList,
            'prices_found' => $result['count'],
            'matched' => $matched,
            'updated' => $updated,
            'unchanged' => $unchanged,
            // Item Price yang tidak punya pasangan produk lokal — biasanya item yang
            // belum pernah ditarik lewat Pull Produk.
            'without_product' => max(0, $result['count'] - $matched),
        ]);
    }

    public function pullCoupons()
    {
        $result = $this->erp->pullCoupons();

        return response()->json($result);
    }

    public function saveSettings(Request $request)
    {
        $chargeTypes = ['On Net Total', 'Actual', 'On Previous Row Total', 'On Previous Row Amount', 'On Item Qty'];

        $request->validate([
            'erpnext_url' => 'required|url',
            'erpnext_api_key' => 'required|string',
            'erpnext_api_secret' => 'required|string',
            'erpnext_company' => 'required|string',
            'erpnext_pos_profile' => 'nullable|string',
            'erpnext_walkin_customer' => 'nullable|string|max:140',
            'erpnext_price_list' => 'nullable|string|max:140',
            'delivery_pricelist_gofood' => 'nullable|string|max:140',
            'delivery_pricelist_grabfood' => 'nullable|string|max:140',
            'delivery_pricelist_shopeefood' => 'nullable|string|max:140',
            'erpnext_naming_series' => 'nullable|string|max:100',
            'erpnext_currency' => 'nullable|string|max:10',
            'erp_so_naming_series' => 'nullable|string|max:100',
            'erp_dn_naming_series' => 'nullable|string|max:100',
            'erpnext_tax_account' => 'nullable|string|max:200',
            'service_charge_erp_type' => 'nullable|in:'.implode(',', $chargeTypes),
            'service_charge_erp_account' => 'nullable|string|max:200',
            'pb1_erp_type' => 'nullable|in:'.implode(',', $chargeTypes),
            'pb1_erp_account' => 'nullable|string|max:200',
            'erp_auto_sync' => 'nullable|in:0,1',
            'stock_auto_sync' => 'nullable|in:0,1',
            'pos_shift_enabled' => 'nullable|in:0,1',
        ]);

        $keys = [
            'erpnext_url', 'erpnext_api_key', 'erpnext_api_secret', 'erpnext_company',
            'erpnext_pos_profile', 'erpnext_walkin_customer', 'erpnext_price_list',
            'delivery_pricelist_gofood', 'delivery_pricelist_grabfood', 'delivery_pricelist_shopeefood',
            'erpnext_naming_series', 'erpnext_currency', 'erp_so_naming_series', 'erp_dn_naming_series',
            'erpnext_tax_account',
            'service_charge_erp_type', 'service_charge_erp_account',
            'pb1_erp_type', 'pb1_erp_account',
        ];

        foreach ($request->only($keys) as $k => $v) {
            $group = str_starts_with($k, 'delivery_') ? 'delivery' : 'erpnext';
            Setting::set($k, $v, $group);
        }

        // Checkbox — kirim '1' kalau centang, '0' kalau tidak
        Setting::set('erp_auto_sync', $request->input('erp_auto_sync', '0') === '1' ? '1' : '0', 'erpnext');
        Setting::set('stock_auto_sync', $request->input('stock_auto_sync', '0') === '1' ? '1' : '0', 'erpnext');
        Setting::set('pos_shift_enabled', $request->input('pos_shift_enabled', '0') === '1' ? '1' : '0', 'erpnext');

        // URL/kredensial berubah → status "terjangkau" hasil cache tidak berlaku lagi.
        ErpNextService::forgetReachableCache();

        return response()->json(['success' => true]);
    }

    public function logs()
    {
        $logs = ErpSyncLog::latest()->paginate(30);

        return response()->json($logs);
    }
}
