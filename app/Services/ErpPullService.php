<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Customer;
use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Logika pull data master dari ERP HPY (produk, customer, harga jual).
 *
 * Dipakai dua jalur: tombol di halaman Sync HPY (ErpSyncController) dan
 * auto-sync terjadwal (erp:full-sync). Semua method mengembalikan array
 * dengan key `success` — bukan response HTTP — supaya netral terhadap jalurnya.
 */
class ErpPullService
{
    public function __construct(private ErpNextService $erp) {}

    /**
     * Tarik produk dari ERP. Mode inkremental (default) hanya menarik item yang
     * berubah dalam N hari terakhir; `$reset` atau `$sinceDays <= 0` memaksa full pull.
     */
    public function pullProducts(bool $reset = false, int $sinceDays = 30): array
    {
        set_time_limit(0);

        $modifiedSince = ($reset || $sinceDays <= 0)
            ? null
            : now()->subDays($sinceDays)->format('Y-m-d H:i:s');

        $imported = 0;
        $updated = 0;
        $disabledDeactivated = 0;
        $page = 0;
        // 200/halaman: lebih besar berisiko membuat query string Item Price/Item Barcode
        // (yang mengirim seluruh item code) menembus batas panjang URL server ERP.
        $pageSize = 200;
        $seenItemCodes = [];

        do {
            $result = $this->erp->pullProducts($pageSize, $page * $pageSize, $modifiedSince);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'],
                    'imported' => $imported,
                    'updated' => $updated,
                ];
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

        return [
            'success' => true,
            'mode' => $modifiedSince ? "perubahan {$sinceDays} hari terakhir" : 'semua produk',
            'modified_since' => $modifiedSince,
            'imported' => $imported,
            'updated' => $updated,
            'total' => $imported + $updated,
            'deactivated' => $deactivated,
            'disabled_deactivated' => $disabledDeactivated,
            'categories_pruned' => $categoriesPruned,
        ];
    }

    /**
     * Tarik seluruh customer aktif dari ERP beserta (opsional) saldo poin loyalty.
     */
    public function pullCustomers(bool $withLoyalty = true): array
    {
        set_time_limit(0);

        if (! $this->erp->isConfigured()) {
            return ['success' => false, 'error' => 'Koneksi ERP HPY belum dikonfigurasi.'];
        }

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
                return [
                    'success' => false,
                    'error' => $result['error'],
                    'imported' => $imported,
                    'updated' => $updated,
                ];
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

        return [
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'total' => $imported + $updated,
            'loyalty_updated' => $loyaltyUpdated,
            'loyalty_error' => $loyaltyError,
        ];
    }

    /**
     * Perbarui harga jual produk lokal dari Item Price di ERP HPY, memakai price
     * list yang diset di halaman Sync HPY (field "Price List").
     *
     * Hanya kolom `price` yang disentuh — nama, kategori, gambar, dan stok tidak
     * ikut berubah, jadi aman dipakai sesering perlu tanpa menunggu Pull Produk
     * yang jauh lebih berat.
     */
    public function pullItemPrices(): array
    {
        set_time_limit(0);

        $priceList = trim((string) Setting::get('erpnext_price_list', ''));
        if ($priceList === '') {
            return [
                'success' => false,
                'error' => 'Price List belum dikonfigurasi. Isi field "Price List" di pengaturan Sync HPY, lalu simpan.',
            ];
        }

        $result = $this->erp->getPriceListPrices($priceList);

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error']];
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

        return [
            'success' => true,
            'price_list' => $priceList,
            'prices_found' => $result['count'],
            'matched' => $matched,
            'updated' => $updated,
            'unchanged' => $unchanged,
            // Item Price yang tidak punya pasangan produk lokal — biasanya item yang
            // belum pernah ditarik lewat Pull Produk.
            'without_product' => max(0, $result['count'] - $matched),
        ];
    }

    /**
     * Jalankan seluruh rangkaian sync rutin sekaligus: pull produk (inkremental),
     * pull customer, update harga jual, lalu push transaksi pending ke ERP.
     *
     * Kegagalan satu langkah tidak menghentikan langkah berikutnya — setiap
     * langkah dilaporkan terpisah di key `steps`. Full pull / reset sengaja
     * tidak termasuk: itu operasi berat yang harus tetap dijalankan manual.
     */
    public function syncEverything(int $sinceDays = 30): array
    {
        $steps = [
            'products' => $this->pullProducts(false, $sinceDays),
            'customers' => $this->pullCustomers(),
            'prices' => $this->pullItemPrices(),
            'transactions' => $this->erp->syncPendingTransactions(),
        ];

        // `success` pada hasil syncPendingTransactions adalah JUMLAH transaksi
        // terkirim (int), bukan boolean — hanya `success === false` yang berarti
        // langkahnya benar-benar gagal.
        $failed = collect($steps)
            ->filter(fn ($r) => ($r['success'] ?? null) === false)
            ->keys()
            ->all();

        return [
            'success' => empty($failed),
            'failed_steps' => $failed,
            'steps' => $steps,
        ];
    }
}
