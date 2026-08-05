<?php

namespace App\Http\Controllers;

use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Warehouse;
use App\Services\ErpNextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class StockController extends Controller
{
    public function __construct(private ErpNextService $erp) {}

    public function debugBinEndpoint()
    {
        $erpUrl    = \App\Models\Setting::get('erpnext_url', env('ERPNEXT_URL', ''));
        $apiKey    = \App\Models\Setting::get('erpnext_api_key', env('ERPNEXT_API_KEY', ''));
        $warehouses = Warehouse::where('is_active', true)->get(['id', 'name', 'warehouse_name', 'is_default']);

        $endpoints = $warehouses->map(function ($wh) use ($erpUrl) {
            $params = http_build_query([
                'fields'            => json_encode(['item_code', 'warehouse', 'actual_qty']),
                'filters'           => json_encode([['warehouse', '=', $wh->name]]),
                'limit_page_length' => 0,
            ]);
            return [
                'warehouse_lokal' => $wh->warehouse_name ?: $wh->name,
                'warehouse_erp'   => $wh->name,
                'is_default'      => $wh->is_default,
                'url'             => rtrim($erpUrl, '/') . '/api/resource/Bin?' . $params,
            ];
        });

        return response()->json([
            'base_url'          => $erpUrl,
            'authorization'     => 'token ' . $apiKey . ':***',
            'active_warehouses' => $warehouses->count(),
            'endpoints'         => $endpoints,
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function debugSync()
    {
        $log = [];

        // STEP 1: Cek warehouse aktif di lokal
        $activeWarehouses = Warehouse::where('is_active', true)->get();
        $log[] = [
            'step'   => '1. Warehouse aktif di lokal',
            'count'  => $activeWarehouses->count(),
            'detail' => $activeWarehouses->map(fn($w) => [
                'id'         => $w->id,
                'name'       => $w->name,
                'label'      => $w->warehouse_name,
                'is_default' => $w->is_default,
            ])->values(),
        ];

        if ($activeWarehouses->isEmpty()) {
            return response()->json(['log' => $log, 'halted' => 'Tidak ada warehouse aktif'], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // STEP 2: Produk lokal yang track_stock & punya SKU
        $products = Product::where('track_stock', true)
            ->whereNotNull('sku')
            ->select('id', 'sku')
            ->get()
            ->keyBy('sku');

        $log[] = [
            'step'        => '2. Produk lokal (track_stock=true, sku not null)',
            'count'       => $products->count(),
            'sample_skus' => $products->keys()->take(10)->values(),
        ];

        // STEP 3: Per warehouse — tarik Bin dari ERP dan cocokkan SKU
        foreach ($activeWarehouses as $warehouse) {
            $whLabel = $warehouse->warehouse_name ?: $warehouse->name;

            $result = $this->erp->pullStockFromBin($warehouse->name);

            if (!$result['success']) {
                $log[] = [
                    'step'      => "3. Bin ERP — {$whLabel}",
                    'erp_name'  => $warehouse->name,
                    'error'     => $result['error'],
                ];
                continue;
            }

            $bins = $result['data'];

            $matched   = [];
            $unmatched = [];

            foreach ($bins as $bin) {
                $found = $products->has($bin['item_code']);
                if ($found) {
                    $matched[] = [
                        'item_code'  => $bin['item_code'],
                        'actual_qty' => $bin['actual_qty'],
                    ];
                } else {
                    $unmatched[] = $bin['item_code'];
                }
            }

            $log[] = [
                'step'              => "3. Bin ERP — {$whLabel}",
                'erp_name'          => $warehouse->name,
                'is_default'        => $warehouse->is_default,
                'total_bins'        => count($bins),
                'matched_count'     => count($matched),
                'unmatched_count'   => count($unmatched),
                'matched_sample'    => array_slice($matched, 0, 5),
                'unmatched_sample'  => array_slice($unmatched, 0, 10),
            ];
        }

        return response()->json(['log' => $log], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function syncWarehouse(Warehouse $warehouse)
    {
        // Puluhan ribu bin per gudang — jangan dipotong batas 120 detik PHP.
        set_time_limit(0);

        // Pastikan tabel ada
        if (!Schema::hasTable('product_stocks')) {
            return response()->json([
                'success'   => false,
                'warehouse' => $warehouse->warehouse_name ?: $warehouse->name,
                'error'     => 'Tabel product_stocks belum ada. Jalankan: php artisan migrate',
            ]);
        }

        // Index produk by erp_item_code untuk lookup O(1)
        $products = Product::where('track_stock', true)
            ->whereNotNull('erp_item_code')
            ->select('id', 'erp_item_code', 'stock')
            ->get()
            ->keyBy('erp_item_code');

        if ($products->isEmpty()) {
            return response()->json([
                'success'   => false,
                'warehouse' => $warehouse->warehouse_name ?: $warehouse->name,
                'error'     => 'Tidak ada produk dengan erp_item_code. Lakukan Pull Products dari menu Sync HPY terlebih dahulu.',
            ]);
        }

        // Tarik Bin dari ERP untuk warehouse ini
        $result = $this->erp->pullStockFromBin($warehouse->name);

        if (!$result['success']) {
            return response()->json([
                'success'   => false,
                'warehouse' => $warehouse->warehouse_name ?: $warehouse->name,
                'error'     => $result['error'],
            ]);
        }

        $bins    = $result['data'];
        $updated = 0;
        $skipped = 0;
        $writeError  = null;
        $updatedIds  = [];

        // Stok yang sudah tersimpan, untuk membandingkan sebelum menulis. Antar sync
        // hampir semua qty tidak berubah — menulis ulang 64 ribu baris tiap kali
        // adalah beban terbesar di endpoint ini.
        $existing = ProductStock::where('warehouse_id', $warehouse->id)
            ->pluck('quantity', 'product_id')
            ->all();

        // Kumpulkan dulu, tulis belakangan secara borongan. updateOrCreate per bin
        // berarti satu SELECT + satu INSERT/UPDATE untuk tiap item — dengan puluhan
        // ribu bin, request-nya habis waktu sebelum selesai.
        $now      = now();
        $rows     = [];
        $idsByQty = [];

        foreach ($bins as $bin) {
            // Cocokkan bin.item_code dengan products.erp_item_code
            $product = $products->get($bin['item_code']);

            if (!$product) {
                $skipped++;
                continue;
            }

            $qty = (int) round($bin['actual_qty']);

            // updatedIds dipakai untuk menentukan baris basi di bawah — semua produk
            // yang ada di ERP masuk hitungan, berubah atau tidak.
            $updatedIds[] = $product->id;

            if (($existing[$product->id] ?? null) !== $qty) {
                $rows[] = [
                    'product_id'   => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity'     => $qty,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];
                $updated++;
            }

            // products.stock hanya mengikuti warehouse default. Dikelompokkan per
            // qty supaya bisa diupdate borongan, bukan satu query per produk.
            if ($warehouse->is_default && (int) $product->stock !== $qty) {
                $idsByQty[$qty][] = $product->id;
            }
        }

        try {
            // 500 baris x 5 kolom = 2.500 placeholder, aman di bawah batas 65.535 MySQL.
            foreach (array_chunk($rows, 500) as $chunk) {
                ProductStock::upsert($chunk, ['product_id', 'warehouse_id'], ['quantity', 'updated_at']);
            }

            foreach ($idsByQty as $qty => $ids) {
                foreach (array_chunk($ids, 1000) as $chunk) {
                    Product::whereIn('id', $chunk)->update(['stock' => $qty]);
                }
            }
        } catch (\Throwable $e) {
            $writeError = $e->getMessage();
        }

        if ($writeError) {
            return response()->json([
                'success'   => false,
                'warehouse' => $warehouse->warehouse_name ?: $warehouse->name,
                'error'     => 'Gagal tulis ke DB: ' . $writeError,
                'updated'   => $updated,
            ]);
        }

        // Hapus baris stok lama yang tidak ada di ERP (sudah tidak relevan).
        // whereNotIn($updatedIds) tidak dipakai: tiap id jadi satu placeholder dan
        // dengan puluhan ribu produk query-nya menembus batas 65.535 milik MySQL
        // (error 1390). Bandingkan di PHP, lalu hapus per potongan.
        $keep     = array_flip($updatedIds);
        $staleIds = array_keys(array_diff_key($existing, $keep));

        $removed = 0;
        foreach (array_chunk($staleIds, 1000) as $chunk) {
            $removed += ProductStock::where('warehouse_id', $warehouse->id)
                ->whereIn('product_id', $chunk)
                ->delete();
        }

        // Verifikasi: hitung baris yang tersimpan
        $savedRows = ProductStock::where('warehouse_id', $warehouse->id)->count();

        return response()->json([
            'success'    => true,
            'warehouse'  => $warehouse->warehouse_name ?: $warehouse->name,
            'erp_name'   => $warehouse->name,
            'is_default' => $warehouse->is_default,
            'bin_count'  => count($bins),
            'matched'    => count($updatedIds),
            'updated'    => $updated,
            'skipped'    => $skipped,
            'removed'    => $removed,
            'db_rows'    => $savedRows,
        ]);
    }

    public function syncFromBin()
    {
        set_time_limit(0);

        $activeWarehouses = Warehouse::where('is_active', true)->get();

        if ($activeWarehouses->isEmpty()) {
            return response()->json(['success' => false, 'error' => 'Tidak ada warehouse aktif di database lokal.']);
        }

        $products = Product::where('track_stock', true)
            ->whereNotNull('sku')
            ->select('id', 'sku')
            ->get()
            ->keyBy('sku');

        $totalUpdated  = 0;
        $warehouseLogs = [];
        $errors        = [];
        $writeErrors   = [];

        foreach ($activeWarehouses as $warehouse) {
            $result = $this->erp->pullStockFromBin($warehouse->name);

            if (!$result['success']) {
                $errors[] = ($warehouse->warehouse_name ?: $warehouse->name) . ': ' . $result['error'];
                continue;
            }

            $bins    = $result['data'];
            $updated = 0;
            $skipped = 0;

            foreach ($bins as $bin) {
                $product = $products->get($bin['item_code']);

                if (!$product) {
                    $skipped++;
                    continue;
                }

                $qty = (int) round($bin['actual_qty']);

                try {
                    ProductStock::updateOrCreate(
                        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
                        ['quantity'   => $qty]
                    );

                    if ($warehouse->is_default) {
                        $product->update(['stock' => $qty]);
                    }

                    $updated++;
                } catch (\Exception $e) {
                    $writeErrors[] = "SKU {$bin['item_code']}: " . $e->getMessage();
                    if (count($writeErrors) >= 3) break;
                }
            }

            $totalUpdated += $updated;

            // Verifikasi: hitung baris aktual di product_stocks untuk warehouse ini
            $actualRows = ProductStock::where('warehouse_id', $warehouse->id)->count();

            $warehouseLogs[] = [
                'warehouse'        => $warehouse->warehouse_name ?: $warehouse->name,
                'erp_name'         => $warehouse->name,
                'bin_count'        => count($bins),
                'updated'          => $updated,
                'skipped'          => $skipped,
                'is_default'       => $warehouse->is_default,
                'db_rows_after'    => $actualRows,
            ];
        }

        return response()->json([
            'success'        => true,
            'warehouses'     => $warehouseLogs,
            'total'          => $totalUpdated,
            'errors'         => $errors,
            'write_errors'   => $writeErrors,
            'local_products' => $products->count(),
        ]);
    }

    public function index(Request $request)
    {
        $warehouses = Warehouse::active()->orderBy('warehouse_name')->get();
        $defaultWarehouse = Warehouse::getDefault();

        // Warehouse yang dipilih: dari query string, fallback ke default, fallback ke pertama
        $selectedWarehouseId = $request->integer('warehouse_id')
            ?: $defaultWarehouse?->id
            ?: $warehouses->first()?->id;

        $selectedWarehouse = $warehouses->firstWhere('id', $selectedWarehouseId);

        // Join langsung ke products. whereHas bersarang menghasilkan EXISTS subquery
        // per baris — dengan puluhan ribu baris stok request-nya kehabisan waktu.
        $query = ProductStock::with(['product.category', 'product.itemCategory'])
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.warehouse_id', $selectedWarehouseId)
            ->where('products.is_active', true)
            ->where('products.track_stock', true);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('products.name', 'like', "%{$request->search}%")
                  ->orWhere('products.sku', 'like', "%{$request->search}%")
                  ->orWhere('products.barcode', 'like', "%{$request->search}%");
            });
        }

        if ($request->item_category_id) {
            $query->where('products.item_category_id', $request->item_category_id);
        }

        if ($request->status === 'empty') {
            $query->where('product_stocks.quantity', '<=', 0);
        } elseif ($request->status === 'low') {
            $query->where('product_stocks.quantity', '>', 0)
                  ->whereColumn('product_stocks.quantity', '<=', 'products.min_stock');
        } elseif ($request->status === 'safe') {
            $query->where('product_stocks.quantity', '>', 0)
                  ->whereColumn('product_stocks.quantity', '>', 'products.min_stock');
        }

        $stocks = $query->orderBy('products.name')
            ->select('product_stocks.*')
            ->paginate(50)
            ->withQueryString();

        // Empat ringkasan dihitung dalam satu query agregat, bukan empat count terpisah.
        $summary = ProductStock::query()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.warehouse_id', $selectedWarehouseId)
            ->where('products.is_active', true)
            ->where('products.track_stock', true)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(product_stocks.quantity <= 0) AS empty_count')
            ->selectRaw('SUM(product_stocks.quantity > 0 AND product_stocks.quantity <= products.min_stock) AS low_count')
            ->first();

        $totalProducts = (int) ($summary->total ?? 0);
        $totalEmpty    = (int) ($summary->empty_count ?? 0);
        $totalLow      = (int) ($summary->low_count ?? 0);
        $totalSafe     = $totalProducts - $totalEmpty - $totalLow;

        $itemCategories = ItemCategory::active()->orderBy('name')->get();

        return view('stock.index', compact(
            'stocks', 'itemCategories', 'warehouses', 'selectedWarehouse', 'selectedWarehouseId',
            'totalProducts', 'totalEmpty', 'totalLow', 'totalSafe'
        ));
    }
}
