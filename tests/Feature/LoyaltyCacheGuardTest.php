<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Services\ErpNextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Regresi saldo poin loyalty.
 *
 * customers.loyalty_points hanya cache dari ERP, dan tiap jalur yang menulisnya
 * pernah menulis angka yang bukan saldo sebenarnya. Tes ini mengunci ketiganya.
 */
class LoyaltyCacheGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // SetupRequired middleware memblokir semua route sampai ERP dikonfigurasi.
        Setting::set('erpnext_url', 'https://erp.test');

        $this->actingAs(User::create([
            'name' => 'Admin Uji',
            'email' => 'admin.uji@larapos.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]));
    }

    private function erpMock()
    {
        $mock = Mockery::mock(ErpNextService::class);
        $this->instance(ErpNextService::class, $mock);

        return $mock;
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'CUST00001',
            'name' => 'Budi',
            'is_active' => true,
            'loyalty_points' => 500,
            'erp_customer_name' => 'CUST-0001',
            'erp_loyalty_program' => 'Program A',
        ]);
    }

    // -- PosController::loyaltyDetails --------------------------------------

    public function test_loyalty_details_tidak_menghapus_cache_saat_program_gagal_dibaca(): void
    {
        $customer = $this->customer();

        // Bentuk balasan getLoyaltyDetails() ketika nama program gagal diselesaikan.
        $erp = $this->erpMock();
        $erp->shouldReceive('isConfigured')->andReturn(true);
        $erp->shouldReceive('getLoyaltyDetails')
            ->andReturn(['success' => true, 'has_program' => false, 'points' => 0.0]);

        $this->getJson(route('pos.loyalty', $customer))->assertOk();

        $customer->refresh();
        $this->assertEquals(500, (float) $customer->loyalty_points);
        $this->assertSame('Program A', $customer->erp_loyalty_program);
    }

    public function test_loyalty_details_memperbarui_cache_saat_program_terbaca(): void
    {
        $customer = $this->customer();

        $erp = $this->erpMock();
        $erp->shouldReceive('isConfigured')->andReturn(true);
        $erp->shouldReceive('getLoyaltyDetails')->andReturn([
            'success' => true,
            'has_program' => true,
            'loyalty_program' => 'Program B',
            'points' => 720.0,
            'conversion_factor' => 1.0,
        ]);

        $this->getJson(route('pos.loyalty', $customer))->assertOk()->assertJsonPath('points', 720);

        $customer->refresh();
        $this->assertEquals(720, (float) $customer->loyalty_points);
        $this->assertSame('Program B', $customer->erp_loyalty_program);
    }

    // -- ErpSyncController::pullCustomers -----------------------------------

    private function stubPullCustomers($erp): void
    {
        $erp->shouldReceive('isConfigured')->andReturn(true);
        $erp->shouldReceive('pullCustomers')->andReturn(['success' => true, 'data' => [
            ['name' => 'CUST-0001', 'customer_name' => 'Budi', 'loyalty_program' => 'Program A'],
        ]]);
    }

    public function test_pull_customers_tidak_menulis_saldo_saat_ledger_gagal_terbaca(): void
    {
        $customer = $this->customer();

        $erp = $this->erpMock();
        $this->stubPullCustomers($erp);
        // Ledger putus di tengah: saldo parsial (jauh lebih kecil) + success=false.
        $erp->shouldReceive('fetchLoyaltyBalances')->andReturn([
            'success' => false,
            'balances' => ['CUST-0001' => 40.0],
            'error' => 'timeout',
        ]);

        $response = $this->postJson(route('sync.pull-customers'))->assertOk();
        $this->assertSame(0, $response->json('loyalty_updated'));
        $this->assertNotNull($response->json('loyalty_error'));

        $customer->refresh();
        $this->assertEquals(500, (float) $customer->loyalty_points, 'saldo parsial tidak boleh ditulis');
    }

    public function test_pull_customers_menolkan_customer_tanpa_baris_ledger(): void
    {
        $customer = $this->customer();

        $erp = $this->erpMock();
        $this->stubPullCustomers($erp);
        // Poin sudah habis terpakai di ERP -> tidak ada baris untuk customer ini.
        $erp->shouldReceive('fetchLoyaltyBalances')->andReturn([
            'success' => true,
            'balances' => ['CUST-9999' => 10.0],
        ]);

        $this->postJson(route('sync.pull-customers'))->assertOk()->assertJsonPath('loyalty_updated', 1);

        $customer->refresh();
        $this->assertEquals(0, (float) $customer->loyalty_points);
    }

    public function test_pull_customers_menulis_saldo_saat_ledger_utuh(): void
    {
        $customer = $this->customer();

        $erp = $this->erpMock();
        $this->stubPullCustomers($erp);
        $erp->shouldReceive('fetchLoyaltyBalances')->andReturn([
            'success' => true,
            'balances' => ['CUST-0001' => 875.0],
        ]);

        $this->postJson(route('sync.pull-customers'))->assertOk();

        $customer->refresh();
        $this->assertEquals(875, (float) $customer->loyalty_points);
        $this->assertNotNull($customer->loyalty_synced_at);
    }
}
