<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ErpNextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Penukaran poin di kasir harus lolos validasi ERP SEBELUM transaksi tercatat.
 *
 * Saldo Loyalty Point baru divalidasi ERP saat POS Invoice di-submit. Kalau
 * checkout hanya menandai transaksi `pending` dan sync-nya belakangan, poin yang
 * ditolak ERP baru ketahuan setelah struk tercetak — transaksi nyangkut dengan
 * erp_sync_status=failed dan stok sudah terpotong.
 */
class LoyaltyCheckoutGuardTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('erpnext_url', 'https://erp.test');
        Setting::set('erp_auto_sync', '1');

        $this->actingAs(User::create([
            'name' => 'Kasir Uji',
            'email' => 'kasir.guard@larapos.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]));

        $this->product = Product::create([
            'name' => 'Roti Tawar',
            'sku' => 'RT-001',
            'price' => 20000,
            'unit' => 'Nos',
            'stock' => 10,
            'track_stock' => true,
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'code' => 'CUST00001',
            'name' => 'Budi',
            'is_active' => true,
            'loyalty_points' => 500,
            'erp_customer_name' => 'CUST-0001',
            'erp_loyalty_program' => 'Program A',
        ]);
    }

    private function erpMock()
    {
        $mock = Mockery::mock(ErpNextService::class);
        $this->instance(ErpNextService::class, $mock);
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('isReachable')->andReturn(true);

        return $mock;
    }

    private function checkout(int $points)
    {
        return $this->postJson(route('pos.checkout'), [
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'price' => 20000,
            ]],
            'customer_id' => $this->customer->id,
            'payment_method' => 'cash',
            'paid_amount' => 20000,
            'loyalty_points' => $points,
        ]);
    }

    public function test_poin_melebihi_saldo_erp_ditolak_sebelum_transaksi_dibuat(): void
    {
        $erp = $this->erpMock();
        $erp->shouldReceive('getLoyaltyDetails')->andReturn([
            'success' => true, 'has_program' => true, 'points' => 5.0,
            'conversion_factor' => 1000.0, 'loyalty_program' => 'Program A',
        ]);
        // Tidak boleh sampai menyentuh ERP: penolakan terjadi di POS.
        $erp->shouldNotReceive('syncTransaction');

        $this->checkout(50)->assertStatus(422);

        $this->assertSame(0, Transaction::count());
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_submit_invoice_ditolak_erp_membatalkan_seluruh_transaksi(): void
    {
        $erp = $this->erpMock();
        $erp->shouldReceive('getLoyaltyDetails')->andReturn([
            'success' => true, 'has_program' => true, 'points' => 500.0,
            'conversion_factor' => 1000.0, 'loyalty_program' => 'Program A',
        ]);
        // Saldo lolos di POS, tapi validator ERP menolak saat submit — persis
        // skenario yang dulu meninggalkan transaksi gagal sync.
        $erp->shouldReceive('syncTransaction')->once()->andReturn([
            'success' => false, 'error' => "You don't have enought Loyalty Points to redeem",
        ]);

        $res = $this->checkout(10)->assertStatus(422);
        $this->assertStringContainsString('Loyalty Points', $res->json('error'));

        // Tidak ada sisa apa pun: struk tidak terbit, stok utuh.
        $this->assertSame(0, Transaction::count());
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_penukaran_poin_yang_diterima_erp_tersimpan_sebagai_synced(): void
    {
        $erp = $this->erpMock();
        $erp->shouldReceive('getLoyaltyDetails')->andReturn([
            'success' => true, 'has_program' => true, 'points' => 500.0,
            'conversion_factor' => 1000.0, 'loyalty_program' => 'Program A',
        ]);
        $erp->shouldReceive('syncTransaction')->once()
            ->andReturnUsing(function (Transaction $trx) {
                $trx->update(['erp_sync_status' => 'synced', 'erp_pos_invoice' => 'ACC-PSINV-0001']);

                return ['success' => true, 'docname' => 'ACC-PSINV-0001'];
            });

        $this->checkout(10)->assertOk();

        $trx = Transaction::firstOrFail();
        $this->assertEquals(10, $trx->loyalty_points_redeemed);
        $this->assertEquals(10000, $trx->loyalty_amount);
        $this->assertSame('synced', $trx->erp_sync_status);
    }

    public function test_auto_sync_mati_melewati_poin_tanpa_menggagalkan_transaksi(): void
    {
        Setting::set('erp_auto_sync', '0');

        $erp = $this->erpMock();
        $erp->shouldNotReceive('syncTransaction');

        $res = $this->checkout(10)->assertOk();
        $this->assertNotNull($res->json('warning'));

        $trx = Transaction::firstOrFail();
        // Poin tidak terpotong — saldo pelanggan tetap utuh untuk dipakai nanti.
        $this->assertEquals(0, $trx->loyalty_points_redeemed);
        $this->assertSame('pending', $trx->erp_sync_status);
    }
}
