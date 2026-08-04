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
 * Diskon per item harus terbaca di baris item POS Invoice.
 *
 * Mengirim `rate` bersih saja membuat ERPNext mengisi price_list_rate sendiri dari
 * Price List dan menghitung ulang baris itu ke harga penuh — diskon kasir hilang.
 */
class PosInvoiceItemDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function payloadFor(Transaction $trx): array
    {
        $method = new ReflectionMethod(ErpNextService::class, 'buildPosInvoicePayload');
        $method->setAccessible(true);

        return $method->invoke(new ErpNextService, $trx->load('items.product', 'customer'));
    }

    private function transactionWithItem(float $price, int $qty, float $discount): Transaction
    {
        Setting::set('erpnext_url', 'https://erp.test');

        $user = User::create([
            'name' => 'Kasir Uji',
            'email' => 'kasir.uji@larapos.test',
            'password' => bcrypt('password'),
            'role' => 'kasir',
        ]);

        $product = Product::create([
            'name' => 'Kopi Susu',
            'sku' => 'KOPI-01',
            'price' => $price,
            'unit' => 'Nos',
            'erp_item_code' => 'KOPI-01',
            'is_active' => true,
        ]);

        $subtotal = ($price * $qty) - $discount;

        $trx = Transaction::create([
            'invoice_no' => 'INV-20260804-0001',
            'user_id' => $user->id,
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'discount_percent' => 0,
            'tax_amount' => 0,
            'total' => $subtotal,
            'paid_amount' => $subtotal,
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
            'price' => $price,
            'quantity' => $qty,
            'discount_amount' => $discount,
            'subtotal' => $subtotal,
        ]);

        return $trx;
    }

    public function test_diskon_item_dikirim_eksplisit_ke_baris_item_erp(): void
    {
        $trx = $this->transactionWithItem(25000, 1, 100);

        $row = $this->payloadFor($trx)['items'][0];

        // Harga penuh dinyatakan sendiri supaya ERP tidak mengambilnya dari Price List.
        $this->assertEquals(25000, $row['price_list_rate']);
        $this->assertEquals(100, $row['discount_amount']);
        $this->assertEqualsWithDelta(0.4, $row['discount_percentage'], 0.000001);

        // Nilai uangnya tidak berubah, dan konsisten dengan hitungan ERP.
        $this->assertEquals(24900, $row['rate']);
        $this->assertEquals(24900, $row['amount']);
        $this->assertEquals($row['price_list_rate'] - $row['discount_amount'], $row['rate']);
    }

    public function test_diskon_item_dibagi_per_unit_saat_qty_lebih_dari_satu(): void
    {
        // Diskon 100 untuk 4 batang -> 25 per unit. ERP menyimpan diskon per unit.
        $trx = $this->transactionWithItem(25000, 4, 100);

        $row = $this->payloadFor($trx)['items'][0];

        $this->assertEquals(25000, $row['price_list_rate']);
        $this->assertEquals(25, $row['discount_amount']);
        $this->assertEquals(24975, $row['rate']);
        $this->assertEquals(99900, $row['amount']);
    }

    public function test_baris_tanpa_diskon_tidak_membawa_field_diskon(): void
    {
        $trx = $this->transactionWithItem(25000, 2, 0);

        $row = $this->payloadFor($trx)['items'][0];

        $this->assertArrayNotHasKey('price_list_rate', $row);
        $this->assertArrayNotHasKey('discount_amount', $row);
        $this->assertArrayNotHasKey('discount_percentage', $row);
        $this->assertEquals(25000, $row['rate']);
        $this->assertEquals(50000, $row['amount']);
    }
}
