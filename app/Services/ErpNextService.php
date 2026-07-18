<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderPayment;
use App\Models\DeliveryShipment;
use App\Models\ErpSyncLog;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Setting;
use App\Models\Slice;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\Transaction;
use App\Models\Warehouse;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;

class ErpNextService
{
    private Client $client;

    private string $baseUrl;

    private string $apiKey;

    private string $apiSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(Setting::get('erpnext_url', env('ERPNEXT_URL', '')), '/');
        $this->apiKey = Setting::get('erpnext_api_key', env('ERPNEXT_API_KEY', ''));
        $this->apiSecret = Setting::get('erpnext_api_secret', env('ERPNEXT_API_SECRET', ''));

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'token '.$this->apiKey.':'.$this->apiSecret,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'verify' => false,
        ]);
    }

    // =========================================================
    // TIMEZONE HELPERS
    // App berjalan di UTC, tetapi ERPNext harus menerima waktu lokal (mis. WIB)
    // supaya posting_date/posting_time transaksi sesuai jam sebenarnya.
    // =========================================================
    private function localTimezone(): string
    {
        return Setting::get('timezone', 'Asia/Jakarta') ?: 'Asia/Jakarta';
    }

    /** Konversi datetime (UTC di DB) ke timezone lokal untuk dikirim ke ERP. */
    private function toLocal($dt): Carbon
    {
        return Carbon::parse($dt)->setTimezone($this->localTimezone());
    }

    /** Waktu "sekarang" dalam timezone lokal. */
    private function localNow(): Carbon
    {
        return Carbon::now($this->localTimezone());
    }

    // =========================================================
    // CONFIGURATION CHECK
    // =========================================================
    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->apiKey) && ! empty($this->apiSecret);
    }

    public function quickPing(): bool
    {
        if (empty($this->baseUrl)) {
            return false;
        }
        try {
            $client = new Client([
                'base_uri' => $this->baseUrl,
                'timeout' => 4,
                'verify' => false,
                'http_errors' => false,
            ]);
            $resp = $client->get('/api/method/frappe.utils.ping');

            return $resp->getStatusCode() < 500;
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================
    // TEST CONNECTION
    // =========================================================
    public function testConnection(): array
    {
        return $this->testConnectionWith($this->baseUrl, $this->apiKey, $this->apiSecret);
    }

    /**
     * Test koneksi dengan parameter eksplisit (dari form, belum tersimpan).
     * - Hanya url → cek reachability saja (tanpa credentials)
     * - url + apiKey + apiSecret → cek reachability + autentikasi API
     */
    public function testConnectionWith(string $url, string $apiKey = '', string $apiSecret = ''): array
    {
        $url = rtrim($url, '/');

        if (empty($url)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum diisi.'];
        }

        try {
            // ── Langkah 1: cek URL bisa dijangkau ───────────────────
            $plain = new Client([
                'base_uri' => $url,
                'timeout' => 10,
                'verify' => false,
                'http_errors' => false,
                'allow_redirects' => true,
            ]);

            $ping = $plain->get('/api/method/frappe.utils.ping');

            if ($ping->getStatusCode() >= 500) {
                return ['success' => false, 'error' => 'Server ERP HPY merespons dengan error '.$ping->getStatusCode().'.'];
            }

            // ── Langkah 2: jika credentials tersedia, test API auth ──
            if ($apiKey && $apiSecret) {
                $auth = new Client([
                    'base_uri' => $url,
                    'timeout' => 10,
                    'verify' => false,
                    'headers' => [
                        'Authorization' => 'token '.$apiKey.':'.$apiSecret,
                        'Accept' => 'application/json',
                    ],
                    'http_errors' => false,
                ]);

                $resp = $auth->get('/api/method/frappe.auth.get_logged_user');
                $data = json_decode($resp->getBody()->getContents(), true);

                if ($resp->getStatusCode() === 403 || $resp->getStatusCode() === 401) {
                    return ['success' => false, 'error' => 'URL terjangkau, tetapi API Key / Secret tidak valid atau tidak memiliki izin.'];
                }

                if (isset($data['message'])) {
                    return ['success' => true, 'user' => $data['message'], 'mode' => 'full'];
                }

                return ['success' => false, 'error' => 'API Key/Secret tidak valid. Response: '.json_encode($data)];
            }

            // Hanya URL yang ditest — sudah lolos ping
            return ['success' => true, 'user' => null, 'mode' => 'url_only',
                'message' => 'Server ERP HPY dapat dijangkau. Isi API Key & Secret lalu test kembali untuk verifikasi lengkap.'];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Tidak dapat menjangkau server: '.$e->getMessage()];
        }
    }

    // =========================================================
    // SYNC TRANSACTIONS → ERPNext POS Invoice
    // =========================================================
    public function syncTransaction(Transaction $transaction): array
    {
        // Auto-push customer ke ERPNext jika belum punya erp_customer_name
        if ($transaction->customer && ! ($transaction->customer->erp_customer_name ?: null)) {
            $this->pushCustomer($transaction->customer);
            $transaction->customer->refresh();
        }

        $payload = $this->buildPosInvoicePayload($transaction);

        try {
            $response = $this->client->post('/api/resource/POS Invoice', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $docname = $data['data']['name'] ?? null;

            if ($docname) {
                $this->submitDoc('POS Invoice', $docname);
            }

            $transaction->update([
                'erp_pos_invoice' => $docname,
                'erp_synced_at' => now(),
                'erp_sync_status' => 'synced',
                'erp_sync_error' => null,
            ]);

            $this->logSync('transaction', $transaction->id, $transaction->invoice_no,
                'success', $payload, $data, $docname);

            return ['success' => true, 'docname' => $docname];

        } catch (ConnectException $e) {
            Log::warning("ERPNext auto-sync: network unreachable for {$transaction->invoice_no}");

            return ['success' => false, 'error' => 'Network unreachable', 'network_error' => true];
        } catch (RequestException $e) {
            $errorBody = $this->extractError($e);

            $transaction->update([
                'erp_sync_status' => 'failed',
                'erp_sync_error' => $errorBody,
            ]);

            $this->logSync('transaction', $transaction->id, $transaction->invoice_no,
                'failed', $payload, null, null, $errorBody);

            Log::error("ERPNext sync failed for {$transaction->invoice_no}: {$errorBody}");

            return ['success' => false, 'error' => $errorBody];
        }
    }

    private function buildPosInvoicePayload(Transaction $transaction): array
    {
        $company = Setting::get('erpnext_company', env('ERPNEXT_COMPANY'));
        $posProfile = Setting::get('erpnext_pos_profile', env('ERPNEXT_POS_PROFILE'));
        $priceList = Setting::get('erpnext_price_list', '');

        $items = $transaction->items->map(function ($item) {
            // Fold diskon item ke dalam rate — kirim net rate langsung.
            // Ini menghindari konflik dengan ERPNext ketika price_list_rate = 0
            // (tidak ada price list), karena ERPNext akan menghitung diskon dari rate
            // yang kita kirim, bukan dari price list.
            $discAmt = (float) $item->discount_amount;
            $netRate = max(0, (float) $item->price - ($item->quantity > 0 ? $discAmt / $item->quantity : 0));
            $netAmount = $netRate * $item->quantity;

            return [
                'item_code' => $item->product->erp_item_code ?? $item->product_sku,
                'item_name' => $item->product_name,
                'qty' => $item->quantity,
                'rate' => $netRate,
                'amount' => $netAmount,
                'uom' => $item->product->unit ?? 'Nos',
            ];
        })->toArray();

        $payments = [];
        if ($transaction->payment_method === 'mixed' && $transaction->payment_details) {
            foreach ($transaction->payment_details as $method => $amount) {
                $payments[] = [
                    'mode_of_payment' => $this->mapPaymentMethod($method),
                    'amount' => (float) $amount,
                ];
            }
        } else {
            $payments[] = [
                'mode_of_payment' => $this->mapPaymentMethod($transaction->payment_method),
                'amount' => (float) $transaction->total,
            ];
        }

        $defaultWarehouse = Warehouse::getDefault()?->name;

        $couponDiscount = (float) ($transaction->coupon_discount ?? 0);
        $baseDiscAmt = (float) $transaction->discount_amount;
        $baseDiscPct = (float) $transaction->discount_percent;

        // Kupon di ERPNext bekerja lewat field `coupon_code` yang memicu Pricing Rule
        // terkait — ERP menghitung sendiri potongannya dari rule tsb, bukan dari angka
        // yang kita kirim manual. Kalau kita kirim coupon_code, JANGAN juga masukkan
        // coupon_discount ke discount_amount (hindari double discount).
        $erpCouponCode = null;
        if ($couponDiscount > 0 && $transaction->coupon_code) {
            $coupon = Coupon::where('code', $transaction->coupon_code)->first();
            $erpCouponCode = $coupon?->erp_coupon_name ?: null;
        }

        if ($erpCouponCode) {
            $erpDiscPct = $baseDiscPct;
            $erpDiscAmt = $baseDiscAmt;
        } else {
            // Fallback: kupon lokal tidak punya link erp_coupon_name (mis. kupon manual,
            // bukan hasil pull dari ERP) — tetap masukkan potongannya manual ke discount_amount
            // seperti sebelumnya, supaya diskon tidak hilang sama sekali.
            $erpDiscPct = $couponDiscount > 0 ? 0 : $baseDiscPct;
            $erpDiscAmt = $baseDiscAmt + $couponDiscount;
        }

        $payload = [
            'doctype' => 'POS Invoice',
            'naming_series' => Setting::get('erpnext_naming_series', 'ACC-PSINV-.YYYY.-'),
            'pos_profile' => $posProfile,
            'company' => $company,
            'set_warehouse' => $defaultWarehouse,
            'pos_class' => $transaction->pos_class,
            'posting_date' => $this->toLocal($transaction->created_at)->format('Y-m-d'),
            'posting_time' => $this->toLocal($transaction->created_at)->format('H:i:s'),
            'set_posting_time' => 1,
            'items' => $items,
            'payments' => $payments,
            'apply_discount_on' => 'Net Total',
            'additional_discount_percentage' => $erpDiscPct,
            'discount_amount' => $erpDiscAmt,
        ];

        // Customer spesifik (sudah di-push ke ERPNext) diutamakan.
        // Fallback ke walk-in customer dari POS Profile (disimpan via pullPosPaymentMethods).
        $erpCustomer = $transaction->customer?->erp_customer_name ?: null;
        $walkin = Setting::get('erpnext_walkin_customer', '');
        $payload['customer'] = $erpCustomer ?: $walkin;

        // Set `owner` dokumen ke kasir yang login (bukan user API key). Tanpa ini, ERP
        // mencatat semua invoice atas nama user API integrasi. email user lokal = email
        // ERP User (hasil sync dari POS Profile), dan Frappe mempertahankan owner yang
        // dikirim saat insert (`if not self.owner: self.owner = session.user`).
        $cashierEmail = $transaction->user?->email;
        if ($cashierEmail) {
            $payload['owner'] = $cashierEmail;
        }

        if ($priceList) {
            $payload['selling_price_list'] = $priceList;
        }

        if ($erpCouponCode) {
            $payload['coupon_code'] = $erpCouponCode;
        }

        $payload['taxes'] = $this->buildTaxesPayload($transaction, $company);

        return $payload;
    }

    private function buildTaxesPayload(Transaction $transaction, string $company): array
    {
        $taxes = [];
        $rowIndex = 1; // 1-based, used for On Previous Row references

        // ── Row 1: Product-level tax (from item tax_rate) ──────────────────
        $taxAccount = Setting::get('erpnext_tax_account', '');
        if ((float) $transaction->tax_amount > 0 && $taxAccount !== '') {
            $taxes[] = [
                'charge_type' => 'Actual',
                'account_head' => $taxAccount,
                'description' => 'Tax',
                'tax_amount' => (float) $transaction->tax_amount,
                'rate' => 0,
            ];
            $rowIndex++;
        }

        // ── Service Charge (Dine In only) ───────────────────────────────────
        if ((float) $transaction->service_charge_amount > 0) {
            $scType = Setting::get('service_charge_erp_type', 'On Net Total');
            $scAccount = Setting::get('service_charge_erp_account', '');

            if ($scAccount) {
                $row = [
                    'charge_type' => $scType,
                    'account_head' => $scAccount,
                    'description' => 'Service Charge ('.$transaction->service_charge_pct.'%)',
                ];

                if ($scType === 'Actual') {
                    $row['tax_amount'] = (float) $transaction->service_charge_amount;
                    $row['rate'] = 0;
                } elseif (in_array($scType, ['On Previous Row Total', 'On Previous Row Amount'])) {
                    $row['rate'] = (float) $transaction->service_charge_pct;
                    $row['row_id'] = (string) ($rowIndex - 1);
                } else {
                    // On Net Total / On Item Qty
                    $row['rate'] = (float) $transaction->service_charge_pct;
                }

                $taxes[] = $row;
                $rowIndex++;
            }
        }

        // ── PB1 / Pajak Daerah (Dine In only) ──────────────────────────────
        if ((float) $transaction->pb1_amount > 0) {
            $pb1Type = Setting::get('pb1_erp_type', 'On Net Total');
            $pb1Account = Setting::get('pb1_erp_account', '');

            if ($pb1Account) {
                $row = [
                    'charge_type' => $pb1Type,
                    'account_head' => $pb1Account,
                    'description' => 'PB1 ('.$transaction->pb1_pct.'%)',
                ];

                if ($pb1Type === 'Actual') {
                    $row['tax_amount'] = (float) $transaction->pb1_amount;
                    $row['rate'] = 0;
                } elseif (in_array($pb1Type, ['On Previous Row Total', 'On Previous Row Amount'])) {
                    $row['rate'] = (float) $transaction->pb1_pct;
                    $row['row_id'] = (string) ($rowIndex - 1);
                } else {
                    $row['rate'] = (float) $transaction->pb1_pct;
                }

                $taxes[] = $row;
            }
        }

        return $taxes;
    }

    private function mapPaymentMethod(string $method): string
    {
        // Jika payment_method sudah berupa nama ERPNext langsung (dari POS Profile),
        // gunakan apa adanya. Fallback ke mapping lama untuk data historis.
        $legacyMap = [
            'cash' => 'Cash',
            'card' => 'Credit Card',
            'transfer' => 'Bank Transfer',
            'qris' => 'QRIS',
        ];

        return $legacyMap[$method] ?? $method;
    }

    private function submitDoc(string $doctype, string $name): void
    {
        $this->client->put("/api/resource/{$doctype}/{$name}", [
            'json' => ['docstatus' => 1],
        ]);
    }

    /**
     * Resolve [paid_from, paid_to] accounts for a "Receive" Payment Entry.
     * paid_from = company's Default Receivable Account, paid_to = Mode of Payment's
     * default account for the company. Both can be overridden manually via Setting.
     */
    private function resolvePaymentAccounts(string $modeOfPayment, string $company): array
    {
        $paidToAccount = Setting::get('erp_pe_paid_to_account', '') ?: null;
        if (! $paidToAccount) {
            try {
                $resp = $this->client->get('/api/resource/Mode%20of%20Payment/'.rawurlencode($modeOfPayment));
                $data = json_decode($resp->getBody()->getContents(), true);
                foreach (($data['data']['accounts'] ?? []) as $acc) {
                    if (($acc['company'] ?? '') === $company && ! empty($acc['default_account'])) {
                        $paidToAccount = $acc['default_account'];
                        break;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Could not resolve Mode of Payment account', ['mode_of_payment' => $modeOfPayment, 'error' => $e->getMessage()]);
            }
        }

        $paidFromAccount = Setting::get('erp_pe_paid_from_account', '') ?: null;
        if (! $paidFromAccount) {
            try {
                $resp = $this->client->get('/api/resource/Company/'.rawurlencode($company), [
                    'query' => ['fields' => '["default_receivable_account"]'],
                ]);
                $data = json_decode($resp->getBody()->getContents(), true);
                $paidFromAccount = $data['data']['default_receivable_account'] ?? null;
            } catch (\Exception $e) {
                Log::warning('Could not resolve Company receivable account', ['company' => $company, 'error' => $e->getMessage()]);
            }
        }

        return [$paidFromAccount, $paidToAccount];
    }

    // =========================================================
    // PULL PRODUCTS FROM ERPNext
    // =========================================================
    public function pullProducts(int $limit = 100, int $start = 0): array
    {
        try {
            $fields = '["name","item_name","item_code","description","item_group","kategori","standard_rate","valuation_rate","stock_uom","is_sales_item","disabled","has_variants","image"]';
            // Tarik semua item (termasuk yang disabled) supaya item yang dinonaktifkan di
            // ERP ikut dinonaktifkan lokal. Filtering disabled ditangani di ErpSyncController.
            $filters = '[]';

            $response = $this->client->get('/api/resource/Item', [
                'timeout' => 120,
                'query' => [
                    'fields' => $fields,
                    'filters' => $filters,
                    'limit' => $limit,
                    'limit_start' => $start,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $items = $data['data'] ?? $data['message'] ?? [];

            // Overlay harga dari Price List jika dikonfigurasi
            $priceList = Setting::get('erpnext_price_list', '');
            if ($priceList && count($items) > 0) {
                $itemCodes = array_column($items, 'name');
                $priceMap = $this->fetchItemPricesMap($itemCodes, $priceList);

                foreach ($items as &$item) {
                    if (isset($priceMap[$item['name']])) {
                        $item['standard_rate'] = $priceMap[$item['name']];
                    }
                }
                unset($item);
            }

            return ['success' => true, 'data' => $items];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================
    // DOWNLOAD PRODUCT IMAGE FROM ERPNext → local public/images/products/
    // =========================================================
    public function downloadProductImage(string $erpImagePath, string $itemCode): ?string
    {
        try {
            $dir = public_path('images/products');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $ext = strtolower(pathinfo($erpImagePath, PATHINFO_EXTENSION)) ?: 'jpg';
            $filename = Str::slug($itemCode).'.'.$ext;
            $dest = $dir.DIRECTORY_SEPARATOR.$filename;

            $response = $this->client->get($erpImagePath, ['stream' => false]);
            file_put_contents($dest, $response->getBody()->getContents());

            return '/images/products/'.$filename;

        } catch (\Exception $e) {
            Log::warning("Failed to download product image '{$erpImagePath}' for item '{$itemCode}': ".$e->getMessage());

            return null;
        }
    }

    // =========================================================
    // FETCH ITEM PRICES FROM PRICE LIST
    // =========================================================
    private function fetchItemPricesMap(array $itemCodes, string $priceList): array
    {
        try {
            $filters = json_encode([
                ['price_list', '=', $priceList],
                ['item_code', 'in', $itemCodes],
                ['selling', '=', 1],
            ]);

            $response = $this->client->get('/api/resource/Item Price', [
                'query' => [
                    'fields' => json_encode(['item_code', 'price_list_rate', 'valid_from']),
                    'filters' => $filters,
                    // Satu item bisa punya beberapa baris Item Price (per valid_from),
                    // jadi limit harus lebih besar dari jumlah item.
                    'limit_page_length' => 0,
                    // Urutkan terbaru dulu supaya baris valid_from paling baru diproses lebih awal.
                    'order_by' => 'valid_from desc',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $map = [];
            $chosenValidFrom = [];
            $today = date('Y-m-d');
            foreach ($data['data'] ?? [] as $row) {
                $code = $row['item_code'];
                $validFrom = $row['valid_from'] ?? '';

                // Abaikan harga yang valid_from-nya masih di masa depan (belum berlaku).
                if ($validFrom !== '' && $validFrom > $today) {
                    continue;
                }

                // Ambil baris dengan valid_from paling baru per item.
                if (! isset($map[$code]) || $validFrom > $chosenValidFrom[$code]) {
                    $map[$code] = (float) $row['price_list_rate'];
                    $chosenValidFrom[$code] = $validFrom;
                }
            }

            return $map;

        } catch (\Exception $e) {
            Log::warning("Failed to fetch Item Prices from price list '{$priceList}': ".$e->getMessage());

            return [];
        }
    }

    // =========================================================
    // PULL CUSTOMERS FROM ERPNext
    // =========================================================
    public function pullCustomers(int $limit = 100, int $start = 0): array
    {
        try {
            $response = $this->client->get('/api/resource/Customer', [
                'query' => [
                    'fields' => json_encode([
                        'name', 'customer_name', 'customer_type',
                        'email_id', 'mobile_no', 'disabled',
                    ]),
                    'filters' => json_encode([['disabled', '=', 0]]),
                    'limit' => $limit,
                    'limit_start' => $start,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return ['success' => true, 'data' => $data['data'] ?? $data['message'] ?? []];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================
    // PUSH CUSTOMER TO ERPNext
    // =========================================================
    public function pushCustomer(Customer $customer): array
    {
        $posClass = Setting::get('pos_class', '');

        $payload = [
            'doctype' => 'Customer',
            'customer_name' => $customer->name,
            'customer_type' => 'Individual',
            'customer_group' => 'Retail',
            'territory' => 'Indonesia',
            'email_id' => $customer->email,
        ];

        // Only include mobile_no if the value looks like a real phone number
        $phone = $customer->phone;
        if ($phone && preg_match('/^[+\d][\d\s\-().]{5,}$/', $phone)) {
            $payload['mobile_no'] = $phone;
        }

        if ($posClass !== '') {
            $payload['class'] = $posClass;
        }

        try {
            $response = $this->client->post('/api/resource/Customer', ['json' => $payload]);
            $data = json_decode($response->getBody()->getContents(), true);
            $docname = $data['data']['name'] ?? null;

            $customer->update([
                'erp_customer_name' => $docname,
                'erp_last_sync' => now(),
            ]);

            $this->logSync('customer', $customer->id, $customer->code, 'success', $payload, $data, $docname);

            return ['success' => true, 'docname' => $docname];

        } catch (RequestException $e) {
            $error = $this->extractError($e);
            $this->logSync('customer', $customer->id, $customer->code, 'failed', $payload, null, null, $error);

            return ['success' => false, 'error' => $error];
        }
    }

    // =========================================================
    // BULK SYNC PENDING TRANSACTIONS
    // =========================================================
    public function syncPendingTransactions(): array
    {
        $pending = Transaction::where('erp_sync_status', 'pending')
            ->where('status', 'completed')
            ->with(['items.product', 'customer'])
            ->latest()->get();

        $results = ['total' => $pending->count(), 'success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($pending as $transaction) {
            $result = $this->syncTransaction($transaction);
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'invoice' => $transaction->invoice_no,
                    'error' => $result['error'],
                ];
            }
        }

        return $results;
    }

    // =========================================================
    // PULL STOCK FROM BIN (filtered by warehouse name)
    // =========================================================
    public function pullStockFromBin(string $warehouseName): array
    {
        try {
            $response = $this->client->get('/api/resource/Bin', [
                'timeout' => 120,
                'query' => [
                    'fields' => json_encode(['item_code', 'warehouse', 'actual_qty']),
                    'filters' => json_encode([['warehouse', '=', $warehouseName]]),
                    'limit_page_length' => 0,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return ['success' => true, 'data' => $data['data'] ?? []];

        } catch (RequestException $e) {
            return ['success' => false, 'error' => $this->extractError($e)];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================
    // STOCK OPNAME — Material Issue (actual berlebih dari sistem)
    // =========================================================
    public function createOpnameMaterialIssue(string $warehouseName, string $opnameDate, array $items): array
    {
        return $this->createOpnameStockEntry('Material Issue', $warehouseName, $opnameDate, $items, 's_warehouse');
    }

    // =========================================================
    // STOCK OPNAME — Material Receipt (actual kurang dari sistem)
    // =========================================================
    public function createOpnameMaterialReceipt(string $warehouseName, string $opnameDate, array $items): array
    {
        return $this->createOpnameStockEntry('Material Receipt', $warehouseName, $opnameDate, $items, 't_warehouse');
    }

    private function createOpnameStockEntry(string $type, string $warehouseName, string $opnameDate, array $items, string $warehouseField): array
    {
        try {
            $entryItems = array_map(fn ($item) => [
                'item_code' => $item['item_code'],
                'qty' => abs($item['qty']),
                'basic_rate' => $item['basic_rate'] ?? 0,
                $warehouseField => $warehouseName,
            ], $items);

            $response = $this->client->post('/api/resource/Stock Entry', [
                'json' => [
                    'stock_entry_type' => $type,
                    'purpose' => $type,
                    'posting_date' => $opnameDate,
                    'items' => $entryItems,
                    'remarks' => 'Stock Opname - '.$opnameDate,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $name = $data['data']['name'] ?? null;

            if (! $name) {
                return ['success' => false, 'error' => 'Stock Entry dibuat tapi name tidak ada di response'];
            }

            $this->submitDoc('Stock Entry', $name);

            return ['success' => true, 'name' => $name];

        } catch (RequestException $e) {
            return ['success' => false, 'error' => $this->extractError($e)];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================
    // GET WAREHOUSES FROM ERPNext
    // =========================================================
    public function getWarehouses(): array
    {
        try {
            $company = Setting::get('erpnext_company', env('ERPNEXT_COMPANY'));
            $filters = $company
                ? json_encode([['company', '=', $company], ['disabled', '=', 0]])
                : json_encode([['disabled', '=', 0]]);

            $response = $this->client->get('/api/resource/Warehouse', [
                'query' => [
                    'fields' => json_encode(['name', 'warehouse_name', 'warehouse_type', 'is_group', 'company', 'parent_warehouse']),
                    'filters' => $filters,
                    'limit' => 200,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return ['success' => true, 'data' => $data['data'] ?? []];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'data' => []];
        }
    }

    // =========================================================
    // GET PENDING IN-TRANSIT STOCK ENTRIES FROM ERPNext
    // =========================================================
    public function getPendingInTransitEntries(): array
    {
        try {
            $filters = json_encode([
                ['purpose', '=', 'Material Transfer'],
                ['docstatus', '=', 1],
            ]);

            $response = $this->client->get('/api/resource/Stock Entry', [
                'query' => [
                    'fields' => json_encode([
                        'name', 'posting_date', 'posting_time',
                        'from_warehouse', 'to_warehouse', 'remarks',
                    ]),
                    'filters' => $filters,
                    'limit' => 100,
                    'order_by' => 'posting_date desc',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return ['success' => true, 'data' => $data['data'] ?? []];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'data' => []];
        }
    }

    // =========================================================
    // GET STOCK ENTRY DETAIL FROM ERPNext
    // =========================================================
    public function getStockEntryDetail(string $name): array
    {
        try {
            $response = $this->client->get("/api/resource/Stock Entry/{$name}");
            $data = json_decode($response->getBody()->getContents(), true);

            return ['success' => true, 'data' => $data['data'] ?? []];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================
    // CREATE OUTGOING TRANSFER → ERPNext (Material Transfer to In-Transit)
    // =========================================================
    public function createOutgoingTransfer(StockTransfer $transfer): array
    {
        $payload = [
            'doctype' => 'Stock Entry',
            'stock_entry_type' => 'Material Transfer',
            'purpose' => 'Material Transfer',
            'company' => Setting::get('erpnext_company', env('ERPNEXT_COMPANY')),
            'posting_date' => $this->localNow()->format('Y-m-d'),
            'posting_time' => $this->localNow()->format('H:i:s'),
            'set_posting_time' => 1,
            'remarks' => $transfer->notes,
            'items' => $transfer->items->map(function ($item) use ($transfer) {
                return [
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'qty' => $item->quantity,
                    'uom' => $item->unit,
                    's_warehouse' => $transfer->from_warehouse,
                    't_warehouse' => $transfer->to_warehouse,
                ];
            })->toArray(),
        ];

        try {
            $response = $this->client->post('/api/resource/Stock Entry', ['json' => $payload]);
            $data = json_decode($response->getBody()->getContents(), true);
            $docname = $data['data']['name'] ?? null;

            if ($docname) {
                $this->submitDoc('Stock Entry', $docname);
            }

            $transfer->update([
                'erp_stock_entry' => $docname,
                'erp_sync_status' => 'synced',
                'erp_sync_error' => null,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            // Kurangi stok dari warehouse pengirim
            $fromWarehouse = Warehouse::where('name', $transfer->from_warehouse)->first();
            if ($fromWarehouse) {
                foreach ($transfer->items as $item) {
                    if ($item->product_id) {
                        ProductStock::forProductWarehouse($item->product_id, $fromWarehouse->id)
                            ->decrementQty($item->quantity);
                        $item->product->decrement('stock', $item->quantity);
                    }
                }
            }

            $this->logSync('stock_transfer', $transfer->id, $transfer->transfer_no,
                'success', $payload, $data, $docname);

            return ['success' => true, 'docname' => $docname];

        } catch (RequestException $e) {
            $error = $this->extractError($e);

            $transfer->update(['erp_sync_status' => 'failed', 'erp_sync_error' => $error]);
            $this->logSync('stock_transfer', $transfer->id, $transfer->transfer_no,
                'failed', $payload, null, null, $error);

            return ['success' => false, 'error' => $error];
        }
    }

    // =========================================================
    // CREATE INCOMING RECEIPT → ERPNext (Material Transfer for Receive)
    // =========================================================
    public function createIncomingReceipt(StockTransfer $transfer): array
    {
        $payload = [
            'doctype' => 'Stock Entry',
            'stock_entry_type' => 'Material Transfer',
            'purpose' => 'Material Transfer',
            'company' => Setting::get('erpnext_company', env('ERPNEXT_COMPANY')),
            'posting_date' => $this->localNow()->format('Y-m-d'),
            'posting_time' => $this->localNow()->format('H:i:s'),
            'set_posting_time' => 1,
            'remarks' => $transfer->notes,
            'items' => $transfer->items->map(function ($item) use ($transfer) {
                return [
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'qty' => $item->actual_quantity ?? $item->quantity,
                    'uom' => $item->unit,
                    's_warehouse' => $transfer->from_warehouse,
                    't_warehouse' => $transfer->to_warehouse,
                ];
            })->toArray(),
        ];

        try {
            $response = $this->client->post('/api/resource/Stock Entry', ['json' => $payload]);
            $data = json_decode($response->getBody()->getContents(), true);
            $docname = $data['data']['name'] ?? null;

            if ($docname) {
                $this->submitDoc('Stock Entry', $docname);
            }

            $transfer->update([
                'erp_stock_entry' => $docname,
                'erp_sync_status' => 'synced',
                'erp_sync_error' => null,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            // Update stok lokal per warehouse
            $toWarehouse = Warehouse::where('name', $transfer->to_warehouse)->first();
            foreach ($transfer->items as $item) {
                if ($item->product_id) {
                    $qty = $item->actual_quantity ?? $item->quantity;
                    $item->product->increment('stock', $qty);
                    if ($toWarehouse) {
                        ProductStock::forProductWarehouse($item->product_id, $toWarehouse->id)
                            ->incrementQty($qty);
                    }
                }
            }

            $this->logSync('stock_transfer', $transfer->id, $transfer->transfer_no,
                'success', $payload, $data, $docname);

            return ['success' => true, 'docname' => $docname];

        } catch (RequestException $e) {
            $error = $this->extractError($e);

            $transfer->update(['erp_sync_status' => 'failed', 'erp_sync_error' => $error]);
            $this->logSync('stock_transfer', $transfer->id, $transfer->transfer_no,
                'failed', $payload, null, null, $error);

            return ['success' => false, 'error' => $error];
        }
    }

    // =========================================================
    // GET SINGLE USER INFO FROM ERP HPY
    // =========================================================
    public function getUserInfo(string $email): array
    {
        try {
            $resp = $this->client->get('/api/resource/User/'.rawurlencode($email));
            $data = json_decode($resp->getBody()->getContents(), true);
            $erpUser = $data['data'] ?? null;

            if (! $erpUser) {
                return ['success' => false, 'error' => 'User tidak ditemukan di ERP HPY.'];
            }

            $roles = array_column($erpUser['roles'] ?? [], 'role');

            return [
                'success' => true,
                'full_name' => $erpUser['full_name'] ?? $email,
                'roles' => $roles,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================
    // PULL PAYMENT METHODS FROM POS PROFILE
    // =========================================================
    public function pullPosPaymentMethods(): array
    {
        $posProfile = Setting::get('erpnext_pos_profile', '');
        if (! $posProfile) {
            return ['success' => false, 'error' => 'POS Profile belum dikonfigurasi.'];
        }

        try {
            $response = $this->client->get('/api/resource/POS Profile/'.rawurlencode($posProfile));
            $data = json_decode($response->getBody()->getContents(), true);
            $payments = $data['data']['payments'] ?? [];

            $methods = collect($payments)->map(fn ($p) => [
                'mode_of_payment' => $p['mode_of_payment'],
                'type' => $p['type'] ?? 'General',
            ])->values()->toArray();

            Setting::set('pos_payment_methods', json_encode($methods), 'erpnext');

            // Simpan default customer dari POS Profile sebagai walk-in customer
            $defaultCustomer = $data['data']['customer'] ?? '';
            if ($defaultCustomer) {
                Setting::set('erpnext_walkin_customer', $defaultCustomer, 'erpnext');
            }

            return ['success' => true, 'methods' => $methods, 'customer' => $defaultCustomer];

        } catch (RequestException $e) {
            return ['success' => false, 'error' => $this->extractError($e)];
        }
    }

    // =========================================================
    // SYNC USERS FROM POS PROFILE
    // =========================================================
    public function syncUsersFromPosProfile(string $posProfile): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        try {
            // Ambil dokumen POS Profile
            $resp = $this->client->get('/api/resource/POS Profile/'.rawurlencode($posProfile));
            $data = json_decode($resp->getBody()->getContents(), true);
            $profile = $data['data'] ?? null;

            if (! $profile) {
                return ['success' => false, 'error' => "POS Profile '{$posProfile}' tidak ditemukan."];
            }

            $applicableUsers = $profile['applicable_for_users'] ?? [];

            if (empty($applicableUsers)) {
                return ['success' => false, 'error' => 'Tidak ada user yang diset di POS Profile ini. Tambahkan user di tab "Applicable for Users" pada POS Profile di ERP HPY.'];
            }

            $users = [];
            foreach ($applicableUsers as $row) {
                $email = $row['user'] ?? null;
                if (! $email) {
                    continue;
                }

                // Ambil detail user dari ERPNext
                try {
                    $userResp = $this->client->get('/api/resource/User/'.rawurlencode($email));
                    $userData = json_decode($userResp->getBody()->getContents(), true);
                    $erpUser = $userData['data'] ?? [];

                    $roles = $erpUser['roles'] ?? [];
                    $roleNames = array_column($roles, 'role');
                    $isSysAdmin = in_array('System Manager', $roleNames);
                    $isPosManager = in_array('Sales User', $roleNames);

                    $users[] = [
                        'email' => $email,
                        'full_name' => $erpUser['full_name'] ?? $email,
                        'role' => $isSysAdmin ? 'admin' : ($isPosManager ? 'manager' : 'cashier'),
                    ];
                } catch (\Exception $e) {
                    // Skip user yang tidak bisa diambil detailnya
                    $users[] = [
                        'email' => $email,
                        'full_name' => $email,
                        'role' => 'cashier',
                    ];
                }
            }

            return ['success' => true, 'users' => $users];

        } catch (RequestException $e) {
            return ['success' => false, 'error' => 'Gagal mengambil POS Profile: '.$this->extractError($e)];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================
    // VERIFY SYSTEM MANAGER (untuk Factory Reset)
    // =========================================================
    public function loginUser(string $email, string $password): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'erp_unavailable' => true, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        try {
            $client = new Client([
                'base_uri' => $this->baseUrl,
                'timeout' => 15,
                'verify' => false,
                'http_errors' => false,
            ]);

            $response = $client->post('/api/method/login', [
                'json' => ['usr' => $email, 'pwd' => $password],
            ]);

            $status = $response->getStatusCode();
            $data = json_decode($response->getBody()->getContents(), true);

            if ($status === 200 && ($data['message'] ?? '') === 'Logged In') {
                return [
                    'success' => true,
                    'full_name' => $data['full_name'] ?? $email,
                ];
            }

            return ['success' => false, 'error' => 'Email atau password ERP HPY salah.'];

        } catch (\Exception $e) {
            return ['success' => false, 'erp_unavailable' => true, 'error' => 'Tidak dapat menghubungi server ERP HPY: '.$e->getMessage()];
        }
    }

    public function verifySystemManager(string $username, string $password): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'ERP HPY URL belum dikonfigurasi.'];
        }

        try {
            // Gunakan cookie jar agar session ERPNext terbawa ke request berikutnya
            $jar = new CookieJar;
            $client = new Client([
                'base_uri' => $this->baseUrl,
                'timeout' => 15,
                'verify' => false,
                'cookies' => $jar,
            ]);

            // Step 1: Login ke ERPNext
            $loginResp = $client->post('/api/method/login', [
                'json' => ['usr' => $username, 'pwd' => $password],
            ]);

            $loginData = json_decode($loginResp->getBody()->getContents(), true);

            if (($loginData['message'] ?? '') !== 'Logged In') {
                return ['success' => false, 'error' => 'Login gagal. Username atau password salah.'];
            }

            $fullname = $loginData['full_name'] ?? $username;

            // Step 2: Ambil dokumen User (user selalu bisa baca dokumen dirinya sendiri)
            // Roles ada sebagai child table di dalam dokumen User
            $userResp = $client->get('/api/resource/User/'.rawurlencode($username));
            $userData = json_decode($userResp->getBody()->getContents(), true);
            $roles = $userData['data']['roles'] ?? [];
            $hasRole = collect($roles)->contains('role', 'System Manager');

            if (! $hasRole) {
                return [
                    'success' => false,
                    'error' => "User '{$username}' tidak memiliki role System Manager di ERP HPY.",
                ];
            }

            return ['success' => true, 'username' => $username, 'fullname' => $fullname];

        } catch (RequestException $e) {
            // ERPNext mengembalikan 401 untuk kredensial salah
            if ($e->hasResponse() && $e->getResponse()->getStatusCode() === 401) {
                return ['success' => false, 'error' => 'Login gagal. Username atau password salah.'];
            }

            return ['success' => false, 'error' => 'Koneksi ERP HPY gagal: '.$e->getMessage()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Error: '.$e->getMessage()];
        }
    }

    // =========================================================
    // GET ITEM PRICES FROM A SPECIFIC PRICE LIST
    // =========================================================
    public function getPriceListPrices(string $priceList): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        try {
            $all = [];
            $chosenValidFrom = [];
            $today = date('Y-m-d');
            $page = 0;
            $limit = 500;

            do {
                $response = $this->client->get('/api/resource/Item Price', [
                    'query' => [
                        'fields' => json_encode(['item_code', 'price_list_rate', 'valid_from']),
                        'filters' => json_encode([['price_list', '=', $priceList]]),
                        'order_by' => 'valid_from desc',
                        'limit_page_length' => $limit,
                        'limit_start' => $page * $limit,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                $batch = $data['data'] ?? [];

                foreach ($batch as $row) {
                    if (empty($row['item_code'])) {
                        continue;
                    }
                    $code = $row['item_code'];
                    $validFrom = $row['valid_from'] ?? '';

                    // Lewati harga yang belum berlaku (valid_from di masa depan).
                    if ($validFrom !== '' && $validFrom > $today) {
                        continue;
                    }

                    // Ambil harga dengan valid_from paling baru per item.
                    if (! isset($all[$code]) || $validFrom > $chosenValidFrom[$code]) {
                        $all[$code] = (float) ($row['price_list_rate'] ?? 0);
                        $chosenValidFrom[$code] = $validFrom;
                    }
                }

                $page++;
            } while (count($batch) >= $limit);

            return ['success' => true, 'prices' => $all, 'count' => count($all)];

        } catch (RequestException $e) {
            return ['success' => false, 'error' => $this->extractError($e)];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================
    // CREATE SALES ORDER → ERPNext
    // =========================================================
    public function createSalesOrder(DeliveryOrder $order): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        $customer = $order->customer->erp_customer_name ?: $order->customer->name;
        if (empty($customer)) {
            return ['success' => false, 'error' => 'Customer belum memiliki nama ERP. Push customer ke ERP HPY terlebih dahulu.'];
        }

        $company = Setting::get('erpnext_company', env('ERPNEXT_COMPANY', ''));
        $currency = Setting::get('erpnext_currency', 'IDR');
        $priceList = Setting::get('erpnext_price_list', 'Standard Selling');
        $namingSeries = Setting::get('erp_so_naming_series', 'SAL-ORD-.YYYY.-');
        $defaultWh = Warehouse::getDefault()?->name;

        $items = $order->items->map(fn ($item) => [
            'item_code' => $item->product?->erp_item_code ?? $item->product_sku ?? $item->product_name,
            'item_name' => $item->product_name,
            'description' => $item->product_name,
            'qty' => (float) $item->qty,
            'rate' => (float) $item->price,
            'uom' => $item->product?->unit ?? 'Nos',
            'delivery_date' => $order->delivery_date->format('Y-m-d'),
            'warehouse' => $defaultWh ?? '',
        ])->toArray();

        $payload = [
            'doctype' => 'Sales Order',
            'naming_series' => $namingSeries,
            'customer' => $customer,
            'transaction_date' => $this->localNow()->format('Y-m-d'),
            'delivery_date' => $order->delivery_date->format('Y-m-d'),
            'order_type' => 'Sales',
            'company' => $company,
            'currency' => $currency,
            'conversion_rate' => 1,
            'selling_price_list' => $priceList,
            'price_list_currency' => $currency,
            'plc_conversion_rate' => 1,
            'set_warehouse' => $defaultWh ?? '',
            'po_no' => $order->order_no,
            'items' => $items,
            'remarks' => implode("\n", array_filter([
                'Order: '.$order->order_no,
                $order->billing_address ? 'Billing: '.$order->billing_address : null,
                $order->notes,
            ])),
        ];

        Log::info('DeliveryOrder SO payload', ['order' => $order->order_no, 'payload' => $payload]);

        try {
            $response = $this->client->post('/api/resource/Sales%20Order', ['json' => $payload]);
            $data = json_decode($response->getBody()->getContents(), true);
            $docname = $data['data']['name'] ?? null;
        } catch (ConnectException $e) {
            Log::warning('SO auto-sync: network unreachable', ['order' => $order->order_no]);

            return ['success' => false, 'error' => 'Network unreachable', 'network_error' => true];
        } catch (RequestException $e) {
            $rawBody = $e->hasResponse() ? $this->readResponseBody($e->getResponse()) : '';
            Log::error('SO create failed', ['order' => $order->order_no, 'raw' => $rawBody]);
            $error = $this->extractError($e);
            $order->update(['erp_sync_status' => 'failed', 'erp_sync_error' => $error]);

            return ['success' => false, 'error' => $error];
        }

        if ($docname) {
            try {
                $this->submitDoc('Sales Order', $docname);
            } catch (RequestException $e) {
                $rawBody = $e->hasResponse() ? $this->readResponseBody($e->getResponse()) : '';
                Log::error('SO submit failed', ['order' => $order->order_no, 'docname' => $docname, 'raw' => $rawBody]);
                $error = 'SO '.$docname.' dibuat tapi gagal submit: '.$this->extractError($e);
                $order->update([
                    'erp_sales_order' => $docname,
                    'erp_sync_status' => 'failed',
                    'erp_sync_error' => $error,
                ]);

                return ['success' => false, 'error' => $error, 'docname' => $docname];
            }
        }

        $order->update([
            'erp_sales_order' => $docname,
            'erp_sync_status' => 'synced',
            'erp_sync_error' => null,
        ]);

        return ['success' => true, 'sales_order' => $docname, 'docname' => $docname];
    }

    // =========================================================
    // CREATE DELIVERY NOTE → ERPNext
    // =========================================================
    public function createDeliveryNote(DeliveryShipment $shipment): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        $order = $shipment->order;
        $company = Setting::get('erpnext_company', env('ERPNEXT_COMPANY', ''));
        $currency = Setting::get('erpnext_currency', 'IDR');
        $priceList = Setting::get('erpnext_price_list', 'Standard Selling');
        $namingSeries = Setting::get('erp_dn_naming_series', 'MAT-DN-.YYYY.-');
        $customer = $order->customer->erp_customer_name ?: $order->customer->name;
        $defaultWh = Warehouse::getDefault()?->name;
        $soName = $order->erp_sales_order ?: null;

        // Fetch SO from ERPNext to get item row-names (so_detail) for proper SO fulfilment linking
        $soItemMap = []; // erp_item_code => so row name
        if ($soName) {
            try {
                $soResp = $this->client->get('/api/resource/Sales%20Order/'.rawurlencode($soName));
                $soData = json_decode($soResp->getBody()->getContents(), true);
                foreach (($soData['data']['items'] ?? []) as $soItem) {
                    $code = $soItem['item_code'] ?? '';
                    if ($code && ! isset($soItemMap[$code])) {
                        $soItemMap[$code] = $soItem['name'];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Could not fetch SO for DN linking', ['so' => $soName, 'error' => $e->getMessage()]);
            }
        }

        $items = collect($shipment->items ?? [])->map(function ($item) use ($defaultWh, $soName, $soItemMap) {
            // Resolve erp_item_code: prefer product lookup so it matches what was sent to the SO
            $sku = $item['product_sku'] ?? null;
            $product = $sku ? Product::where('sku', $sku)->first() : null;
            $itemCode = $product?->erp_item_code ?? $sku ?? $item['product_name'];

            $row = [
                'item_code' => $itemCode,
                'item_name' => $item['product_name'],
                'description' => $item['product_name'],
                'qty' => (float) ($item['qty'] ?? 1),
                'rate' => (float) ($item['price'] ?? 0),
                'uom' => $product?->unit ?? 'Nos',
                'warehouse' => $defaultWh ?? '',
                'against_sales_order' => $soName ?? '',
            ];

            if ($soName && isset($soItemMap[$itemCode])) {
                $row['so_detail'] = $soItemMap[$itemCode];
            }

            return $row;
        })->filter(fn ($i) => $i['qty'] > 0)->values()->toArray();

        $payload = [
            'doctype' => 'Delivery Note',
            'naming_series' => $namingSeries,
            'customer' => $customer,
            'posting_date' => $this->localNow()->format('Y-m-d'),
            'company' => $company,
            'currency' => $currency,
            'conversion_rate' => 1,
            'selling_price_list' => $priceList,
            'price_list_currency' => $currency,
            'plc_conversion_rate' => 1,
            'ignore_pricing_rule' => 1,
            'set_warehouse' => $defaultWh ?? '',
            'shipping_address' => $shipment->shipping_address,
            'items' => $items,
            'remarks' => implode("\n", array_filter([
                'Order: '.$order->order_no,
                'Penerima: '.$shipment->recipient_name,
                $shipment->recipient_phone ? 'Telp: '.$shipment->recipient_phone : null,
                $shipment->notes,
            ])),
        ];

        Log::info('DeliveryNote payload', ['shipment' => $shipment->id, 'payload' => $payload]);

        try {
            $response = $this->client->post('/api/resource/Delivery%20Note', ['json' => $payload]);
            $data = json_decode($response->getBody()->getContents(), true);
            $docname = $data['data']['name'] ?? null;
        } catch (RequestException $e) {
            $rawBody = $e->hasResponse() ? $this->readResponseBody($e->getResponse()) : '';
            Log::error('DN create failed', ['shipment' => $shipment->id, 'raw' => $rawBody]);
            $error = $this->extractError($e);
            $shipment->update(['erp_sync_status' => 'failed', 'erp_sync_error' => $error]);

            return ['success' => false, 'error' => $error];
        }

        $shipment->update([
            'erp_delivery_note' => $docname,
            'erp_sync_status' => 'synced',
            'erp_sync_error' => null,
        ]);

        return ['success' => true, 'delivery_note' => $docname, 'docname' => $docname];
    }

    // =========================================================
    // MODE OF PAYMENT LIST
    // =========================================================
    public function getModeOfPaymentList(): array
    {
        if (empty($this->baseUrl)) {
            return [];
        }
        $response = $this->client->get('/api/resource/Mode%20of%20Payment', [
            'query' => [
                'fields' => '["name","type"]',
                'filters' => '[["enabled","=",1]]',
                'limit' => 100,
            ],
        ]);
        $data = json_decode($response->getBody(), true);

        return collect($data['data'] ?? [])->pluck('name')->filter()->values()->toArray();
    }

    // CREATE PAYMENT ENTRY → ERPNext (linked ke Sales Order)
    // =========================================================
    public function createPaymentEntry(DeliveryOrderPayment $payment): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        $order = $payment->order;
        $soName = $order->erp_sales_order;
        $customer = $order->customer->erp_customer_name ?: $order->customer->name;

        if (empty($soName)) {
            return ['success' => false, 'error' => 'Sales Order ERP belum ada. Konfirmasi order dan sync SO terlebih dahulu.'];
        }
        if (empty($customer)) {
            return ['success' => false, 'error' => 'Customer belum memiliki nama ERP.'];
        }

        $company = Setting::get('erpnext_company', env('ERPNEXT_COMPANY', ''));
        $currency = Setting::get('erpnext_currency', 'IDR');
        $namingSeries = Setting::get('erp_pe_naming_series', 'ACC-PAY-.YYYY.-');

        $modeOfPayment = $this->mapPaymentMethod($payment->payment_method);

        [$paidFromAccount, $paidToAccount] = $this->resolvePaymentAccounts($modeOfPayment, $company);

        if (empty($paidToAccount)) {
            return ['success' => false, 'error' => "Mode of Payment \"$modeOfPayment\" belum punya akun default untuk company \"$company\" di ERP HPY (Mode of Payment > Accounts), atau set manual via Setting key erp_pe_paid_to_account."];
        }
        if (empty($paidFromAccount)) {
            return ['success' => false, 'error' => "Company \"$company\" belum punya Default Receivable Account di ERP HPY, atau set manual via Setting key erp_pe_paid_from_account."];
        }

        $payload = [
            'doctype' => 'Payment Entry',
            'naming_series' => $namingSeries,
            'payment_type' => 'Receive',
            'party_type' => 'Customer',
            'party' => $customer,
            'party_name' => $order->customer->name,
            'posting_date' => $payment->payment_date->format('Y-m-d'),
            'mode_of_payment' => $modeOfPayment,
            'paid_amount' => (float) $payment->amount,
            'received_amount' => (float) $payment->amount,
            'company' => $company,
            'paid_from' => $paidFromAccount,
            'paid_to' => $paidToAccount,
            'paid_from_account_currency' => $currency,
            'paid_to_account_currency' => $currency,
            'source_exchange_rate' => 1,
            'target_exchange_rate' => 1,
            'references' => [
                [
                    'reference_doctype' => 'Sales Order',
                    'reference_name' => $soName,
                    'allocated_amount' => (float) $payment->amount,
                ],
            ],
            'remarks' => implode("\n", array_filter([
                'Payment untuk DO: '.$order->order_no,
                $payment->reference_no ? 'Ref: '.$payment->reference_no : null,
                $payment->notes,
            ])),
        ];

        Log::info('PaymentEntry payload', ['payment_id' => $payment->id, 'order' => $order->order_no]);

        try {
            $response = $this->client->post('/api/resource/Payment%20Entry', ['json' => $payload]);
            $data = json_decode($response->getBody()->getContents(), true);
            $docname = $data['data']['name'] ?? null;
        } catch (RequestException $e) {
            $rawBody = $e->hasResponse() ? $this->readResponseBody($e->getResponse()) : '';
            Log::error('PaymentEntry create failed', ['payment_id' => $payment->id, 'raw' => $rawBody]);
            $error = $this->extractError($e);
            $payment->update(['erp_sync_status' => 'failed', 'erp_sync_error' => $error]);

            return ['success' => false, 'error' => $error];
        }

        if ($docname) {
            try {
                $this->submitDoc('Payment Entry', $docname);
            } catch (RequestException $e) {
                $rawBody = $e->hasResponse() ? $this->readResponseBody($e->getResponse()) : '';
                Log::error('PaymentEntry submit failed', ['payment_id' => $payment->id, 'docname' => $docname, 'raw' => $rawBody]);
                $error = 'Payment Entry '.$docname.' dibuat tapi gagal submit: '.$this->extractError($e);
                $payment->update([
                    'erp_payment_entry' => $docname,
                    'erp_sync_status' => 'failed',
                    'erp_sync_error' => $error,
                ]);

                return ['success' => false, 'error' => $error, 'docname' => $docname];
            }
        }

        $payment->update([
            'erp_payment_entry' => $docname,
            'erp_sync_status' => 'synced',
            'erp_sync_error' => null,
        ]);

        return ['success' => true, 'payment_entry' => $docname, 'docname' => $docname];
    }

    // =========================================================
    // FETCH HISTORICAL POS INVOICES FROM ERPNext (Online Report)
    // =========================================================
    public function fetchPosInvoices(
        string $dateFrom,
        string $dateTo,
        string $posProfile = '',
        int $maxRecords = 1000
    ): array {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        try {
            $fields = json_encode([
                'name', 'posting_date', 'posting_time',
                'customer', 'customer_name',
                'grand_total', 'net_total', 'total_taxes_and_charges',
                'paid_amount', 'status', 'pos_profile', 'owner',
            ]);

            $filters = [
                ['posting_date', '>=', $dateFrom],
                ['posting_date', '<=', $dateTo],
                ['docstatus', '=', 1],
            ];

            if ($posProfile) {
                $filters[] = ['pos_profile', '=', $posProfile];
            }

            $filtersJson = json_encode($filters);
            $allData = [];
            $start = 0;
            $batchSize = 500;

            do {
                $response = $this->client->get('/api/resource/POS Invoice', [
                    'query' => [
                        'fields' => $fields,
                        'filters' => $filtersJson,
                        'limit_start' => $start,
                        'limit_page_length' => $batchSize,
                        'order_by' => 'posting_date asc, posting_time asc',
                    ],
                ]);

                $body = json_decode($response->getBody()->getContents(), true);
                $batch = $body['data'] ?? [];
                $allData = array_merge($allData, $batch);
                $start += $batchSize;

            } while (count($batch) === $batchSize && count($allData) < $maxRecords);

            $truncated = count($batch) === $batchSize && count($allData) >= $maxRecords;

            return [
                'success' => true,
                'data' => $allData,
                'count' => count($allData),
                'truncated' => $truncated,
            ];

        } catch (RequestException $e) {
            return ['success' => false, 'error' => $this->extractError($e)];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Agregasi pembayaran per mode_of_payment untuk sekumpulan POS Invoice.
     *
     * Query ke PARENT doctype "POS Invoice" sambil mengambil field child
     * "Sales Invoice Payment" lewat join (`tabSales Invoice Payment`.fieldname).
     * Child doctype TIDAK boleh diakses langsung via /api/resource — Frappe
     * menolaknya dengan PermissionError (bahkan untuk Administrator).
     *
     * @param  string[]  $invoiceNames
     * @return array{success:bool,data?:array<int,array{mode_of_payment:string,count:int,total:float}>,error?:string}
     */
    public function fetchPosPaymentSummary(array $invoiceNames): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        $names = array_values(array_filter($invoiceNames));
        if (empty($names)) {
            return ['success' => true, 'data' => []];
        }

        $fields = json_encode([
            '`tabSales Invoice Payment`.mode_of_payment',
            '`tabSales Invoice Payment`.amount',
        ]);
        $summary = []; // mode_of_payment => ['count'=>int, 'total'=>float]

        try {
            foreach (array_chunk($names, 100) as $chunk) {
                $filters = json_encode([
                    ['name', 'in', $chunk],
                    ['docstatus', '=', 1],
                ]);

                $start = 0;
                $batchSize = 500;
                do {
                    $response = $this->client->get('/api/resource/POS Invoice', [
                        'query' => [
                            'fields' => $fields,
                            'filters' => $filters,
                            'limit_start' => $start,
                            'limit_page_length' => $batchSize,
                        ],
                    ]);

                    $body = json_decode($response->getBody()->getContents(), true);
                    $rows = $body['data'] ?? [];

                    foreach ($rows as $row) {
                        // Invoice tanpa pembayaran mengembalikan child kosong lewat join — skip
                        if (empty($row['mode_of_payment'])) {
                            continue;
                        }
                        $mode = $row['mode_of_payment'] ?: 'Lainnya';
                        if (! isset($summary[$mode])) {
                            $summary[$mode] = ['count' => 0, 'total' => 0.0];
                        }
                        $summary[$mode]['count']++;
                        $summary[$mode]['total'] += (float) ($row['amount'] ?? 0);
                    }

                    $start += $batchSize;
                } while (count($rows) === $batchSize);
            }

            $data = [];
            foreach ($summary as $mode => $agg) {
                $data[] = [
                    'mode_of_payment' => $mode,
                    'count' => $agg['count'],
                    'total' => $agg['total'],
                ];
            }
            usort($data, fn ($a, $b) => $b['total'] <=> $a['total']);

            return ['success' => true, 'data' => $data];

        } catch (RequestException $e) {
            return ['success' => false, 'error' => $this->extractError($e)];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Matriks pembayaran per tanggal × mode_of_payment.
     *
     * Query ke PARENT doctype "POS Invoice" (bukan child "Sales Invoice Payment",
     * yang ditolak Frappe dengan PermissionError), sambil menarik field child
     * lewat join dan posting_date dari parent untuk mengelompokkan per tanggal.
     *
     * @param  array<string,string>  $nameToDate  [nama POS Invoice => posting_date]
     * @return array{success:bool,data?:array{modes:array<int,string>,matrix:array<string,array<string,array{count:int,total:float}>>},error?:string}
     */
    public function fetchPosPaymentMatrix(array $nameToDate): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        $names = array_keys($nameToDate);
        if (empty($names)) {
            return ['success' => true, 'data' => ['modes' => [], 'matrix' => []]];
        }

        $fields = json_encode([
            'posting_date',
            '`tabSales Invoice Payment`.mode_of_payment',
            '`tabSales Invoice Payment`.amount',
        ]);
        $matrix = []; // date => mode => ['count'=>int, 'total'=>float]
        $modeTotals = []; // mode => total (untuk sorting kolom)

        try {
            foreach (array_chunk($names, 100) as $chunk) {
                $filters = json_encode([
                    ['name', 'in', $chunk],
                    ['docstatus', '=', 1],
                ]);

                $start = 0;
                $batchSize = 500;
                do {
                    $response = $this->client->get('/api/resource/POS Invoice', [
                        'query' => [
                            'fields' => $fields,
                            'filters' => $filters,
                            'limit_start' => $start,
                            'limit_page_length' => $batchSize,
                        ],
                    ]);

                    $body = json_decode($response->getBody()->getContents(), true);
                    $rows = $body['data'] ?? [];

                    foreach ($rows as $row) {
                        // Baris tanpa pembayaran (mode kosong) di-skip agar tak mengotori matriks
                        if (empty($row['mode_of_payment'])) {
                            continue;
                        }
                        $date = $row['posting_date'] ?? null;
                        if ($date === null) {
                            continue;
                        }
                        $mode = $row['mode_of_payment'] ?: 'Lainnya';
                        $amount = (float) ($row['amount'] ?? 0);

                        if (! isset($matrix[$date][$mode])) {
                            $matrix[$date][$mode] = ['count' => 0, 'total' => 0.0];
                        }
                        $matrix[$date][$mode]['count']++;
                        $matrix[$date][$mode]['total'] += $amount;

                        $modeTotals[$mode] = ($modeTotals[$mode] ?? 0) + $amount;
                    }

                    $start += $batchSize;
                } while (count($rows) === $batchSize);
            }

            arsort($modeTotals);
            $modes = array_keys($modeTotals);
            ksort($matrix);

            return ['success' => true, 'data' => ['modes' => $modes, 'matrix' => $matrix]];

        } catch (RequestException $e) {
            return ['success' => false, 'error' => $this->extractError($e)];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function fetchPosInvoiceDetail(string $name): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        try {
            $response = $this->client->get('/api/resource/POS Invoice/'.rawurlencode($name));
            $body = json_decode($response->getBody()->getContents(), true);
            $doc = $body['data'] ?? null;

            if (! $doc) {
                return ['success' => false, 'error' => "POS Invoice '{$name}' tidak ditemukan."];
            }

            return ['success' => true, 'data' => $doc];

        } catch (RequestException $e) {
            return ['success' => false, 'error' => $this->extractError($e)];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function readResponseBody(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return (string) $stream;
    }

    private function extractError(RequestException $e): string
    {
        if (! $e->hasResponse()) {
            return $e->getMessage();
        }

        $status = $e->getResponse()->getStatusCode();
        $body = $this->readResponseBody($e->getResponse());

        if (str_starts_with(ltrim($body), '<')) {
            return "Server ERP HPY tidak tersedia (HTTP {$status}). Coba beberapa saat lagi.";
        }

        Log::debug('ERPNext raw error', ['status' => $status, 'body' => $body]);

        $decoded = json_decode($body, true);
        if ($decoded) {
            $exception = $decoded['exception'] ?? '';

            // Try to extract human-readable message from _server_messages first
            $raw = $decoded['_server_messages'] ?? null;
            if ($raw) {
                $msgs = json_decode($raw, true);
                if (is_array($msgs) && ! empty($msgs)) {
                    $texts = [];
                    foreach ($msgs as $m) {
                        $parsed = is_string($m) ? json_decode($m, true) : $m;
                        $text = is_array($parsed) ? ($parsed['message'] ?? '') : (string) $m;
                        if ($text) {
                            $texts[] = strip_tags(html_entity_decode($text));
                        }
                    }
                    if ($texts) {
                        $msg = implode(' | ', $texts);
                        if (str_contains($exception, 'PermissionError')) {
                            $msg .= ' (Hint: pastikan API user memiliki role Sales User/Manager di ERP HPY)';
                        }

                        return $msg;
                    }
                }
            }

            if (! empty($decoded['message'])) {
                return $decoded['message'];
            }

            if ($exception) {
                $short = preg_replace('/^frappe\.exceptions\./', '', $exception);

                return "ERP HPY {$short}".(str_contains($exception, 'PermissionError')
                    ? ': API user tidak punya izin buat dokumen ini'
                    : '');
            }
        }

        return $body ?: $e->getMessage();
    }

    private function logSync(
        string $type, int $refId, ?string $refNo,
        string $status, $request, $response, ?string $docname, ?string $error = null
    ): void {
        ErpSyncLog::create([
            'type' => $type,
            'reference_id' => $refId,
            'reference_no' => $refNo,
            'status' => $status,
            'request_payload' => json_encode($request),
            'response_payload' => json_encode($response),
            'erp_docname' => $docname,
            'error_message' => $error,
        ]);
    }

    // =========================================================
    // PULL COUPON CODES + PRICING RULES FROM ERPNext 13
    // =========================================================
    /**
     * Fetch Coupon Codes + linked Pricing Rules from ERPNext 13 and upsert locally.
     *
     * ERPNext 13 Coupon Code confirmed fields (from source coupon_code.json):
     *   name, coupon_code, coupon_name, pricing_rule,
     *   valid_from, valid_upto, maximum_use, used, description
     *   NOTE: NO is_active / disable field on Coupon Code itself.
     *
     * ERPNext 13 Pricing Rule confirmed fields (from source pricing_rule.json):
     *   name, disable, rate_or_discount, discount_percentage,
     *   discount_amount, min_amt, max_amt, apply_discount_on
     *   Active/inactive is controlled by Pricing Rule's `disable` field.
     */
    public function pullCoupons(): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'ERP HPY belum dikonfigurasi.'];
        }

        try {
            // 1. Fetch all Coupon Codes — no filter on is_active (field doesn't exist in v13)
            //    Use %20 URL encoding; Guzzle may not encode path segments automatically.
            $response = $this->client->get('/api/resource/Coupon%20Code', [
                'query' => [
                    'fields' => json_encode([
                        'name', 'coupon_code', 'coupon_name',
                        'pricing_rule', 'valid_from', 'valid_upto',
                        'maximum_use', 'used',
                    ]),
                    'limit_page_length' => 500,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $coupons = $data['data'] ?? $data['message'] ?? [];

            if (empty($coupons)) {
                return ['success' => true, 'imported' => 0, 'skipped' => 0,
                    'message' => 'Tidak ada Coupon Code di ERP HPY.'];
            }

            // 2. Collect Pricing Rule names (non-empty, deduplicated)
            $ruleNames = array_values(array_filter(array_unique(array_column($coupons, 'pricing_rule'))));
            $pricingRules = $this->fetchPricingRules($ruleNames);

            $imported = 0;
            $skipped = 0;

            foreach ($coupons as $c) {
                // coupon_code = code customers enter; fall back to name if field is empty
                $code = strtoupper(trim($c['coupon_code'] ?? $c['name'] ?? ''));
                $pricingName = $c['pricing_rule'] ?? null;

                if (! $code) {
                    $skipped++;

                    continue;
                }

                // Skip if no Pricing Rule linked
                if (! $pricingName) {
                    $skipped++;

                    continue;
                }

                // Active status = Pricing Rule not disabled
                $rule = $pricingRules[$pricingName] ?? null;
                $pricingDisabled = $rule ? (bool) ($rule['disable'] ?? 0) : false;

                // Resolve discount type + value from Pricing Rule
                $discountType = 'fixed';
                $discountValue = 0;
                $minPurchase = 0;

                if ($rule) {
                    $rateOrDiscount = $rule['rate_or_discount'] ?? 'Discount Percentage';

                    if ($rateOrDiscount === 'Discount Percentage') {
                        $discountType = 'percent';
                        $discountValue = (float) ($rule['discount_percentage'] ?? 0);
                    } else {
                        // 'Discount Amount' or 'Rate'
                        $discountType = 'fixed';
                        $discountValue = (float) ($rule['discount_amount'] ?? 0);
                    }

                    $minPurchase = (float) ($rule['min_amt'] ?? 0);
                }

                if ($discountValue <= 0) {
                    $skipped++;

                    continue;
                }

                Coupon::updateOrCreate(
                    ['code' => $code],
                    [
                        'erp_coupon_name' => $c['name'],
                        'erp_pricing_rule' => $pricingName,
                        'description' => $c['coupon_name'] ?: null,
                        'discount_type' => $discountType,
                        'discount_value' => $discountValue,
                        'min_purchase' => $minPurchase,
                        'max_uses' => ($c['maximum_use'] ?? 0) > 0 ? (int) $c['maximum_use'] : null,
                        'used_count' => (int) ($c['used'] ?? 0),
                        'valid_from' => $c['valid_from'] ?: null,
                        'valid_until' => $c['valid_upto'] ?: null,
                        'is_active' => ! $pricingDisabled,
                    ]
                );

                $imported++;
            }

            $msg = "Berhasil import {$imported} kupon dari ERP HPY.";
            if ($skipped) {
                $msg .= " {$skipped} dilewati (tidak ada pricing rule atau nilai diskon).";
            }

            return ['success' => true, 'imported' => $imported, 'skipped' => $skipped, 'message' => $msg];

        } catch (RequestException $e) {
            return ['success' => false, 'error' => $this->extractError($e)];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch Pricing Rules by name.
     * Returns associative array keyed by rule name.
     * Uses URL-encoded path (/api/resource/Pricing%20Rule) for ERPNext 13 compatibility.
     */
    private function fetchPricingRules(array $names): array
    {
        if (empty($names)) {
            return [];
        }

        try {
            $response = $this->client->get('/api/resource/Pricing%20Rule', [
                'query' => [
                    'fields' => json_encode([
                        'name', 'title', 'disable', 'rate_or_discount',
                        'discount_percentage', 'discount_amount',
                        'min_amt', 'max_amt', 'apply_discount_on',
                    ]),
                    'filters' => json_encode([['name', 'in', $names]]),
                    'limit_page_length' => count($names),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $map = [];
            foreach ($data['data'] ?? [] as $rule) {
                $map[$rule['name']] = $rule;
            }

            return $map;

        } catch (\Exception $e) {
            Log::warning('pullCoupons: failed to fetch Pricing Rules: '.$e->getMessage());

            return [];
        }
    }

    // =========================================================
    // CREATE MATERIAL REQUEST → ERPNext (material_request_type: Manufacture)
    // =========================================================
    public function createMaterialRequest(StockRequest $stockRequest): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        $company = Setting::get('erpnext_company', env('ERPNEXT_COMPANY', ''));
        $namingSeries = Setting::get('erp_mr_naming_series', 'MAT-MR-.YYYY.-');
        $defaultWh = Warehouse::getDefault()?->name ?? '';
        $scheduleDate = $stockRequest->needed_date
            ? $stockRequest->needed_date->format('Y-m-d')
            : $this->localNow()->format('Y-m-d');

        $items = $stockRequest->items->map(fn ($item) => [
            'item_code' => $item->item_code ?? $item->item_name,
            'item_name' => $item->item_name,
            'qty' => (float) $item->qty,
            'uom' => $item->uom ?: 'Nos',
            'schedule_date' => $scheduleDate,
            'warehouse' => $defaultWh,
            'description' => $item->notes ?: $item->item_name,
        ])->toArray();

        $payload = [
            'doctype' => 'Material Request',
            'naming_series' => $namingSeries,
            'material_request_type' => 'Manufacture',
            'transaction_date' => $this->localNow()->format('Y-m-d'),
            'schedule_date' => $scheduleDate,
            'company' => $company,
            'status_permintaan' => 'Diajukan',
            'items' => $items,
            'remarks' => implode("\n", array_filter([
                'Request: '.$stockRequest->request_no,
                $stockRequest->notes,
            ])),
        ];

        Log::info('StockRequest MR payload', ['request' => $stockRequest->request_no, 'payload' => $payload]);

        try {
            $response = $this->client->post('/api/resource/Material%20Request', ['json' => $payload]);
            $data = json_decode($response->getBody()->getContents(), true);
            $docname = $data['data']['name'] ?? null;
        } catch (ConnectException $e) {
            Log::warning('MR auto-sync: network unreachable', ['request' => $stockRequest->request_no]);

            return ['success' => false, 'error' => 'Network unreachable', 'network_error' => true];
        } catch (RequestException $e) {
            $rawBody = $e->hasResponse() ? $this->readResponseBody($e->getResponse()) : '';
            Log::error('MR create failed', ['request' => $stockRequest->request_no, 'raw' => $rawBody]);
            $error = $this->extractError($e);
            $stockRequest->update(['erp_sync_status' => 'failed', 'erp_sync_error' => $error]);

            return ['success' => false, 'error' => $error];
        }

        $stockRequest->update([
            'erp_material_request' => $docname,
            'erp_sync_status' => 'synced',
            'erp_sync_error' => null,
        ]);

        return ['success' => true, 'docname' => $docname, 'material_request' => $docname];
    }

    // =========================================================
    // SUBMIT MATERIAL REQUEST → docstatus = 1 (Selesai)
    // =========================================================
    public function submitMaterialRequest(StockRequest $stockRequest): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        $docname = $stockRequest->erp_material_request;

        if (! $docname) {
            return ['success' => false, 'error' => 'Material Request ERP belum dibuat untuk permintaan ini.'];
        }

        try {
            $this->submitDoc('Material Request', $docname);
        } catch (ConnectException $e) {
            Log::warning('MR submit: network unreachable', ['docname' => $docname]);

            return ['success' => false, 'error' => 'Network unreachable', 'network_error' => true];
        } catch (RequestException $e) {
            $rawBody = $e->hasResponse() ? $this->readResponseBody($e->getResponse()) : '';
            Log::error('MR submit failed', ['docname' => $docname, 'raw' => $rawBody]);

            return ['success' => false, 'error' => $this->extractError($e)];
        }

        Log::info('MR submitted (docstatus=1)', ['docname' => $docname, 'request' => $stockRequest->request_no]);

        return ['success' => true, 'docname' => $docname];
    }

    // =========================================================
    // CREATE REPACK ENTRY → ERPNext (Stock Entry, purpose = Repack)
    // Konversi 1 item (mis. Bolu) menjadi item lain (mis. Slice):
    // baris sumber = s_warehouse (diissue), baris hasil = t_warehouse (diterima).
    // Nilai/valuasi hasil dihitung otomatis oleh ERPNext dari sumber.
    // =========================================================
    public function createRepackEntry(Slice $slice): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        $company = Setting::get('erpnext_company', env('ERPNEXT_COMPANY', ''));
        $namingSeries = Setting::get('erp_repack_naming_series', 'MAT-STE-.YYYY.-');
        $defaultWh = Warehouse::getDefault()?->name ?? '';

        if (! $defaultWh) {
            return ['success' => false, 'error' => 'Gudang default belum diset. Set salah satu warehouse sebagai default.'];
        }

        // Baris sumber (dikeluarkan) — hanya s_warehouse
        $items = [];
        foreach ($slice->items as $item) {
            $items[] = [
                'item_code' => $item->source_item_code ?: $item->source_item_name,
                'item_name' => $item->source_item_name,
                'qty' => (float) $item->source_qty,
                'uom' => $item->source_uom ?: 'Nos',
                's_warehouse' => $defaultWh,
            ];
        }
        // Baris hasil (diterima) — hanya t_warehouse
        foreach ($slice->items as $item) {
            $items[] = [
                'item_code' => $item->target_item_code ?: $item->target_item_name,
                'item_name' => $item->target_item_name,
                'qty' => (float) $item->target_qty,
                'uom' => $item->target_uom ?: 'Nos',
                't_warehouse' => $defaultWh,
            ];
        }

        $payload = [
            'doctype' => 'Stock Entry',
            'naming_series' => $namingSeries,
            'stock_entry_type' => 'Repack',
            'purpose' => 'Repack',
            'company' => $company,
            'posting_date' => $this->localNow()->format('Y-m-d'),
            'posting_time' => $this->localNow()->format('H:i:s'),
            'set_posting_time' => 1,
            'from_warehouse' => $defaultWh,
            'to_warehouse' => $defaultWh,
            'items' => $items,
            'remarks' => implode("\n", array_filter([
                'Slice: '.$slice->slice_no,
                $slice->notes,
            ])),
        ];

        Log::info('Slice Repack payload', ['slice' => $slice->slice_no, 'payload' => $payload]);

        try {
            $response = $this->client->post('/api/resource/Stock%20Entry', ['json' => $payload]);
            $data = json_decode($response->getBody()->getContents(), true);
            $docname = $data['data']['name'] ?? null;
        } catch (ConnectException $e) {
            Log::warning('Repack auto-sync: network unreachable', ['slice' => $slice->slice_no]);

            return ['success' => false, 'error' => 'Network unreachable', 'network_error' => true];
        } catch (RequestException $e) {
            $rawBody = $e->hasResponse() ? $this->readResponseBody($e->getResponse()) : '';
            Log::error('Repack create failed', ['slice' => $slice->slice_no, 'raw' => $rawBody]);
            $error = $this->extractError($e);
            $slice->update(['erp_sync_status' => 'failed', 'erp_sync_error' => $error]);

            return ['success' => false, 'error' => $error];
        }

        if ($docname) {
            try {
                $this->submitDoc('Stock Entry', $docname);
            } catch (RequestException $e) {
                $rawBody = $e->hasResponse() ? $this->readResponseBody($e->getResponse()) : '';
                Log::error('Repack submit failed', ['slice' => $slice->slice_no, 'docname' => $docname, 'raw' => $rawBody]);
                $error = 'Stock Entry '.$docname.' dibuat tapi gagal submit: '.$this->extractError($e);
                $slice->update([
                    'erp_stock_entry' => $docname,
                    'erp_sync_status' => 'failed',
                    'erp_sync_error' => $error,
                ]);

                return ['success' => false, 'error' => $error, 'docname' => $docname];
            }
        }

        $slice->update([
            'erp_stock_entry' => $docname,
            'erp_sync_status' => 'synced',
            'erp_sync_error' => null,
        ]);

        return ['success' => true, 'docname' => $docname, 'stock_entry' => $docname];
    }

    // =========================================================
    // CANCEL REPACK ENTRY → ERPNext (docstatus = 2)
    // Membalik pergerakan stok: sumber kembali masuk, hasil keluar.
    // =========================================================
    public function cancelRepackEntry(Slice $slice): array
    {
        if (empty($this->baseUrl)) {
            return ['success' => false, 'error' => 'URL ERP HPY belum dikonfigurasi.'];
        }

        $docname = $slice->erp_stock_entry;

        if (! $docname) {
            // Tidak ada dokumen ERP — tidak ada yang perlu dibatalkan di ERP.
            return ['success' => true, 'docname' => null];
        }

        // Cek dulu status dokumen di ERP. Jika sudah dibatalkan manual di ERP
        // (docstatus = 2), lanjutkan saja pembatalan lokal.
        try {
            $resp = $this->client->get('/api/resource/Stock%20Entry/'.rawurlencode($docname), [
                'query' => ['fields' => '["docstatus"]'],
            ]);
            $docstatus = (int) (json_decode($resp->getBody()->getContents(), true)['data']['docstatus'] ?? 0);

            if ($docstatus === 2) {
                Log::info('Repack already cancelled in ERP', ['docname' => $docname, 'slice' => $slice->slice_no]);

                return ['success' => true, 'docname' => $docname, 'already_cancelled' => true];
            }
        } catch (RequestException $e) {
            // Dokumen tidak ditemukan di ERP (mis. sudah dihapus) — anggap tidak ada
            // yang perlu dibatalkan, lanjutkan pembatalan lokal.
            if ($e->hasResponse() && $e->getResponse()->getStatusCode() === 404) {
                Log::info('Repack doc not found in ERP, proceeding local cancel', ['docname' => $docname, 'slice' => $slice->slice_no]);

                return ['success' => true, 'docname' => $docname, 'not_found' => true];
            }

            // Error lain saat cek status: jangan lanjut, biar user tahu.
            return ['success' => false, 'error' => $this->extractError($e)];
        } catch (ConnectException $e) {
            return ['success' => false, 'error' => 'Network unreachable', 'network_error' => true];
        }

        try {
            $this->client->post('/api/method/frappe.client.cancel', [
                'json' => ['doctype' => 'Stock Entry', 'name' => $docname],
            ]);
        } catch (ConnectException $e) {
            Log::warning('Repack cancel: network unreachable', ['slice' => $slice->slice_no, 'docname' => $docname]);

            return ['success' => false, 'error' => 'Network unreachable', 'network_error' => true];
        } catch (RequestException $e) {
            $rawBody = $e->hasResponse() ? $this->readResponseBody($e->getResponse()) : '';
            Log::error('Repack cancel failed', ['slice' => $slice->slice_no, 'docname' => $docname, 'raw' => $rawBody]);

            return ['success' => false, 'error' => $this->extractError($e)];
        }

        Log::info('Repack cancelled (docstatus=2)', ['docname' => $docname, 'slice' => $slice->slice_no]);

        return ['success' => true, 'docname' => $docname];
    }
}
