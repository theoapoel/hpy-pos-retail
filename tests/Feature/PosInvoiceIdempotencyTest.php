<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\ErpNextService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Satu transaksi POS = paling banyak satu POS Invoice di ERP.
 *
 * Jawaban ERP yang hilang di jaringan padahal dokumennya sudah sah terbit dulu
 * membuat POS mengira gagal, lalu percobaan berikutnya menerbitkan invoice kedua —
 * poin pelanggan terpotong dua kali dan stok ERP ikut ganda. po_no berisi nomor
 * invoice lokal dipakai sebagai kunci pengenalnya.
 */
class PosInvoiceIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function service(array $responses): ErpNextService
    {
        Setting::set('erpnext_url', 'https://erp.test');

        $erp = new ErpNextService;

        $prop = new ReflectionProperty(ErpNextService::class, 'client');
        $prop->setAccessible(true);
        $prop->setValue($erp, new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));

        return $erp;
    }

    private function transaction(float $redeemPoints = 0): Transaction
    {
        $user = User::create([
            'name' => 'Kasir',
            'email' => 'kasir.idem@larapos.test',
            'password' => bcrypt('password'),
            'role' => 'cashier',
        ]);

        $customer = Customer::create([
            'code' => 'CUST00001',
            'name' => 'Budi',
            'is_active' => true,
            'erp_customer_name' => 'CUST-0001',
            'erp_loyalty_program' => 'Program A',
        ]);

        $product = Product::create([
            'name' => 'Roti Tawar',
            'sku' => 'RT-001',
            'price' => 20000,
            'unit' => 'Nos',
            'erp_item_code' => 'RT-001',
            'is_active' => true,
        ]);

        $trx = Transaction::create([
            'invoice_no' => 'INV-20260807-0001',
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'subtotal' => 20000,
            'tax_amount' => 0,
            'total' => 20000,
            'paid_amount' => 20000,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'status' => 'completed',
            'loyalty_points_redeemed' => $redeemPoints,
            'loyalty_amount' => $redeemPoints * 1000,
            'loyalty_program' => $redeemPoints > 0 ? 'Program A' : null,
            'erp_sync_status' => 'pending',
        ]);

        TransactionItem::create([
            'transaction_id' => $trx->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'price' => 20000,
            'quantity' => 1,
            'subtotal' => 20000,
        ]);

        return $trx->load('items.product', 'customer', 'user');
    }

    public function test_invoice_yang_sudah_terbit_diadopsi_bukan_dibuat_ulang(): void
    {
        // MockHandler hanya menyediakan satu balasan: kalau kode sampai mencoba POST
        // pembuatan invoice, tesnya gagal karena antrean balasannya habis.
        $erp = $this->service([
            new Response(200, [], json_encode(['data' => [
                ['name' => 'ACC-PSINV-0009', 'docstatus' => 1],
            ]])),
        ]);

        $trx = $this->transaction();
        $result = $erp->syncTransaction($trx);

        $this->assertTrue($result['success']);
        $this->assertSame('ACC-PSINV-0009', $result['docname']);
        $this->assertSame('ACC-PSINV-0009', $trx->fresh()->erp_pos_invoice);
        $this->assertSame('synced', $trx->fresh()->erp_sync_status);
    }

    public function test_submit_yang_jawabannya_hilang_tidak_membatalkan_invoice_yang_sah(): void
    {
        $erp = $this->service([
            new Response(200, [], json_encode(['data' => []])),                        // tidak ada invoice lama
            new Response(200, [], json_encode(['data' => ['name' => 'ACC-PSINV-0010']])), // draft dibuat
            new Response(500, [], json_encode(['exc_type' => 'TimeoutError'])),         // submit: jawaban hilang
            new Response(200, [], json_encode(['data' => ['docstatus' => 1]])),         // ternyata sudah submitted
        ]);

        $trx = $this->transaction();
        $result = $erp->syncTransaction($trx);

        // Dokumennya sah — harus diadopsi, bukan dihapus lalu dilaporkan gagal.
        $this->assertTrue($result['success']);
        $this->assertSame('ACC-PSINV-0010', $trx->fresh()->erp_pos_invoice);
    }

    public function test_submit_yang_benar_benar_ditolak_tetap_dilaporkan_gagal(): void
    {
        $erp = $this->service([
            new Response(200, [], json_encode(['data' => []])),
            new Response(200, [], json_encode(['data' => ['name' => 'ACC-PSINV-0011']])),
            new Response(417, [], json_encode(['_server_messages' => '["You don\'t have enought Loyalty Points to redeem"]'])),
            new Response(200, [], json_encode(['data' => ['docstatus' => 0]])), // masih draft
            new Response(202, [], json_encode(['message' => 'ok'])),            // draft dihapus
        ]);

        $trx = $this->transaction();
        $result = $erp->syncTransaction($trx, false);

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $trx->fresh()->erp_sync_status);
        $this->assertNull($trx->fresh()->erp_pos_invoice);
    }

    public function test_resync_transaksi_berpoin_ditolak_saat_saldo_sekarang_tidak_cukup(): void
    {
        Setting::set('erpnext_url', 'https://erp.test');

        // Saldo hari ini tinggal 3 poin, transaksinya menukar 10.
        $erp = Mockery::mock(ErpNextService::class)->makePartial();
        $erp->shouldAllowMockingProtectedMethods();
        $erp->shouldReceive('getLoyaltyDetails')->once()->andReturn([
            'success' => true, 'has_program' => true, 'points' => 3.0,
            'conversion_factor' => 1000.0, 'loyalty_program' => 'Program A',
        ]);

        // Tanpa balasan HTTP sama sekali: pemeriksaan harus berhenti sebelum
        // menyentuh ERP, jadi tidak ada draft yang tertinggal di sana.
        $prop = new ReflectionProperty(ErpNextService::class, 'client');
        $prop->setAccessible(true);
        $prop->setValue($erp, new Client([
            'handler' => HandlerStack::create(new MockHandler([])),
        ]));

        $trx = $this->transaction(10);
        $result = $erp->syncTransaction($trx);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['loyalty_error']);
        $this->assertStringContainsString('tinggal 3', $result['error']);
        $this->assertSame('failed', $trx->fresh()->erp_sync_status);
    }
}
