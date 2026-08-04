<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\ErpNextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Item gratis (diskon 100%).
 *
 * Kasus paling rawan: rate bersihnya 0, dan ERPNext memperlakukan rate 0 sebagai
 * "belum diisi" lalu mengambil harga dari Price List -- item gratis jadi tertagih
 * penuh. Payload harus menyatakan discount_percentage = 100 supaya ERP menolkan
 * rate-nya sendiri.
 */
class FreeItemDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_gratis_dikirim_sebagai_diskon_seratus_persen(): void
    {
        Setting::set('erpnext_url', 'https://erp.test');

        $user = User::create([
            'name' => 'Kasir',
            'email' => 'kasir.uji@larapos.test',
            'password' => bcrypt('password'),
            'role' => 'cashier',
        ]);

        $product = Product::create([
            'name' => 'TAS BELANJA KCL TERPAL LOGO',
            'sku' => 'CSU-ITEM-2025-02356',
            'price' => 79000,
            'unit' => 'Nos',
            'erp_item_code' => 'CSU-ITEM-2025-02356',
            'is_active' => true,
        ]);

        $trx = Transaction::create([
            'invoice_no' => 'INV-20260804-0023',
            'user_id' => $user->id,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'status' => 'completed',
            'erp_sync_status' => 'pending',
        ]);

        TransactionItem::create([
            'transaction_id' => $trx->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'price' => 79000,
            'quantity' => 1,
            'discount_amount' => 79000,
            'subtotal' => 0,
        ]);

        $method = new ReflectionMethod(ErpNextService::class, 'buildPosInvoicePayload');
        $method->setAccessible(true);
        $row = $method->invoke(new ErpNextService, $trx->load('items.product', 'customer'))['items'][0];

        // Harga penuh dinyatakan sendiri -- inilah yang mencegah ERP mengambil dari
        // Price List dan menagih 79.000 untuk barang yang digratiskan.
        $this->assertEquals(79000, $row['price_list_rate']);
        $this->assertEquals(79000, $row['discount_amount']);
        // Harus PERSIS 100: ERPNext punya cabang khusus discount_percentage == 100
        // yang menolkan rate, dan 99,999999 tidak masuk cabang itu.
        $this->assertSame(100.0, $row['discount_percentage']);
        $this->assertEquals(0, $row['rate']);
        $this->assertEquals(0, $row['amount']);
    }
}
