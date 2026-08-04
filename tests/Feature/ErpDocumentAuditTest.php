<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\ErpNextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Audit dokumen ERP + koreksi batalkan-dan-terbitkan-ulang.
 */
class ErpDocumentAuditTest extends TestCase
{
    use RefreshDatabase;

    private function erpMock()
    {
        $mock = Mockery::mock(ErpNextService::class);
        $this->instance(ErpNextService::class, $mock);

        return $mock;
    }

    private function actingAsAdmin(): void
    {
        Setting::set('erpnext_url', 'https://erp.test');

        $this->actingAs(User::create([
            'name' => 'Admin Uji',
            'email' => 'admin.uji@larapos.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]));
    }

    /** Nota: 1 x 25.000 diskon 100 -> harga bersih 24.900. */
    private function transaksi(string $erpDocname = 'ACC-PSINV-2026-0001'): Transaction
    {
        $product = Product::create([
            'name' => 'Kopi Susu',
            'sku' => 'KOPI-01',
            'price' => 25000,
            'unit' => 'Nos',
            'erp_item_code' => 'KOPI-01',
            'is_active' => true,
        ]);

        $trx = Transaction::create([
            'invoice_no' => 'INV-20260804-0001',
            'user_id' => User::first()->id,
            'subtotal' => 24900,
            'discount_amount' => 0,
            'discount_percent' => 0,
            'tax_amount' => 0,
            'total' => 24900,
            'paid_amount' => 24900,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'status' => 'completed',
            'erp_sync_status' => 'synced',
            'erp_pos_invoice' => $erpDocname,
        ]);

        TransactionItem::create([
            'transaction_id' => $trx->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'price' => 25000,
            'quantity' => 1,
            'discount_amount' => 100,
            'subtotal' => 24900,
        ]);

        return $trx;
    }

    private function docErp(float $rate, ?string $consolidated = null): array
    {
        return ['success' => true, 'data' => [
            'name' => 'ACC-PSINV-2026-0001',
            'docstatus' => 1,
            'grand_total' => $rate,
            'consolidated_invoice' => $consolidated,
            'items' => [['item_code' => 'KOPI-01', 'qty' => 1, 'rate' => $rate, 'amount' => $rate]],
        ]];
    }

    // -- Audit ---------------------------------------------------------------

    public function test_audit_menandai_nota_yang_harganya_beda_dengan_erp(): void
    {
        $this->actingAsAdmin();
        $trx = $this->transaksi();

        $erp = $this->erpMock();
        $erp->shouldReceive('isConfigured')->andReturn(true);
        // ERP menyimpan harga penuh — diskon 100 hilang.
        $erp->shouldReceive('fetchPosInvoice')->andReturn($this->docErp(25000));

        $this->get(route('sync.audit', ['run' => 1, 'date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))
            ->assertOk()
            ->assertSee($trx->invoice_no)
            ->assertSee('Batalkan');
    }

    public function test_audit_menyembunyikan_nota_yang_sudah_cocok(): void
    {
        $this->actingAsAdmin();
        $this->transaksi();

        $erp = $this->erpMock();
        $erp->shouldReceive('isConfigured')->andReturn(true);
        $erp->shouldReceive('fetchPosInvoice')->andReturn($this->docErp(24900));

        $this->get(route('sync.audit', ['run' => 1, 'date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('Tidak ada selisih');
    }

    // -- Koreksi -------------------------------------------------------------

    public function test_resync_mengganti_nomor_invoice_erp_di_lokal(): void
    {
        $this->actingAsAdmin();
        $trx = $this->transaksi();

        $erp = $this->erpMock();
        $erp->shouldReceive('resyncTransaction')->once()->andReturnUsing(function ($t) {
            $t->update(['erp_pos_invoice' => 'ACC-PSINV-2026-0099', 'erp_sync_status' => 'synced']);

            return ['success' => true, 'docname' => 'ACC-PSINV-2026-0099', 'cancelled' => 'ACC-PSINV-2026-0001'];
        });

        $this->postJson(route('sync.audit.resync', $trx))
            ->assertOk()
            ->assertJsonPath('invoice_lama', 'ACC-PSINV-2026-0001')
            ->assertJsonPath('invoice_baru', 'ACC-PSINV-2026-0099');

        $trx->refresh();
        $this->assertSame('ACC-PSINV-2026-0099', $trx->erp_pos_invoice);
        // Data kasir tidak ikut diubah — yang salah cuma dokumen ERP.
        $this->assertEquals(24900, (float) $trx->total);
        $this->assertEquals(100, (float) $trx->items()->first()->discount_amount);
    }

    public function test_resync_ditolak_saat_invoice_sudah_consolidated(): void
    {
        $this->actingAsAdmin();
        $trx = $this->transaksi();

        $erp = $this->erpMock();
        $erp->shouldReceive('resyncTransaction')->andReturn([
            'success' => false,
            'error' => 'POS Invoice sudah masuk POS Closing Entry (POS-CLO-0001).',
            'consolidated' => true,
        ]);

        $this->postJson(route('sync.audit.resync', $trx))
            ->assertStatus(422)
            ->assertJsonPath('consolidated', true);

        $trx->refresh();
        $this->assertSame('ACC-PSINV-2026-0001', $trx->erp_pos_invoice);
    }

    public function test_kasir_tidak_boleh_menerbitkan_ulang(): void
    {
        Setting::set('erpnext_url', 'https://erp.test');
        $this->actingAs(User::create([
            'name' => 'Kasir',
            'email' => 'kasir.uji@larapos.test',
            'password' => bcrypt('password'),
            'role' => 'cashier',
        ]));

        $trx = $this->transaksi();

        $this->postJson(route('sync.audit.resync', $trx))->assertForbidden();
    }
}
