<?php

namespace Tests\Feature;

use App\Models\ErpSyncLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ErpNextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pengaman pembatalan POS Invoice.
 *
 * Ini menyentuh dokumen akuntansi yang sudah terbit, jadi setiap penolakan harus
 * benar-benar menolak — bukan diteruskan dengan harapan ERP yang menahan.
 */
class ErpResyncGuardTest extends TestCase
{
    use RefreshDatabase;

    private function transaksi(?string $docname = 'ACC-PSINV-2026-0001'): Transaction
    {
        $user = User::create([
            'name' => 'Kasir',
            'email' => 'kasir.uji@larapos.test',
            'password' => bcrypt('password'),
            'role' => 'cashier',
        ]);

        return Transaction::create([
            'invoice_no' => 'INV-20260804-0001',
            'user_id' => $user->id,
            'subtotal' => 24900,
            'tax_amount' => 0,
            'total' => 24900,
            'paid_amount' => 24900,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'status' => 'completed',
            'erp_sync_status' => 'synced',
            'erp_pos_invoice' => $docname,
        ]);
    }

    /** Partial mock: hanya panggilan HTTP yang distub, logika guard tetap asli. */
    private function service(array $doc)
    {
        $erp = Mockery::mock(ErpNextService::class)->makePartial();
        $erp->shouldReceive('fetchPosInvoice')->andReturn(['success' => true, 'data' => $doc]);

        return $erp;
    }

    public function test_menolak_membatalkan_invoice_yang_sudah_consolidated(): void
    {
        $erp = $this->service([
            'docstatus' => 1,
            'consolidated_invoice' => 'POS-CLO-2026-0007',
        ]);

        $result = $erp->cancelPosInvoice('ACC-PSINV-2026-0001');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['consolidated']);
        $this->assertStringContainsString('POS-CLO-2026-0007', $result['error']);
    }

    public function test_menolak_membatalkan_invoice_yang_sudah_batal(): void
    {
        $erp = $this->service(['docstatus' => 2, 'consolidated_invoice' => null]);

        $result = $erp->cancelPosInvoice('ACC-PSINV-2026-0001');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('sudah dibatalkan', $result['error']);
    }

    public function test_resync_gagal_tidak_menghapus_nomor_invoice_lokal(): void
    {
        $trx = $this->transaksi();

        $erp = Mockery::mock(ErpNextService::class)->makePartial();
        $erp->shouldReceive('cancelPosInvoice')->andReturn([
            'success' => false,
            'error' => 'sudah consolidated',
            'consolidated' => true,
        ]);
        $erp->shouldNotReceive('syncTransaction');

        $result = $erp->resyncTransaction($trx);

        $this->assertFalse($result['success']);

        $trx->refresh();
        $this->assertSame('ACC-PSINV-2026-0001', $trx->erp_pos_invoice);
        $this->assertSame('synced', $trx->erp_sync_status);
    }

    public function test_resync_berhasil_mencatat_nomor_lama_sebelum_ditimpa(): void
    {
        $trx = $this->transaksi();

        $erp = Mockery::mock(ErpNextService::class)->makePartial();
        $erp->shouldReceive('cancelPosInvoice')->once()->andReturn(['success' => true]);
        $erp->shouldReceive('syncTransaction')->once()->andReturnUsing(function ($t) {
            // Saat sync dipanggil, nomor lama sudah dilepas supaya transaksi tidak
            // menunjuk dokumen yang sudah batal seolah masih sah.
            $this->assertNull($t->erp_pos_invoice);
            $t->update(['erp_pos_invoice' => 'ACC-PSINV-2026-0099', 'erp_sync_status' => 'synced']);

            return ['success' => true, 'docname' => 'ACC-PSINV-2026-0099'];
        });

        $result = $erp->resyncTransaction($trx);

        $this->assertTrue($result['success']);
        $this->assertSame('ACC-PSINV-2026-0001', $result['cancelled']);

        // Jejak nomor lama harus tetap ada meski kolomnya sudah ditimpa.
        $log = ErpSyncLog::where('type', 'transaction_resync')->where('status', 'success')->first();
        $this->assertNotNull($log);
        $this->assertSame('ACC-PSINV-2026-0001', $log->erp_docname);
    }

    public function test_resync_menolak_transaksi_yang_belum_pernah_sync(): void
    {
        $trx = $this->transaksi(null);

        $erp = Mockery::mock(ErpNextService::class)->makePartial();
        $erp->shouldNotReceive('cancelPosInvoice');

        $result = $erp->resyncTransaction($trx);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('belum punya POS Invoice', $result['error']);
    }
}
