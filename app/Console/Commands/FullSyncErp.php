<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\ErpNextService;
use App\Services\ErpPullService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FullSyncErp extends Command
{
    protected $signature = 'erp:full-sync
        {--force : Jalankan walau setting full_auto_sync mati}
        {--since-days=30 : Rentang hari perubahan produk yang ditarik}';

    protected $description = 'Sync rutin lengkap dengan ERP HPY: pull produk, customer, harga jual, lalu push transaksi pending';

    public function handle(ErpNextService $erp, ErpPullService $pull): int
    {
        // Gate: hormati toggle setting kecuali dipaksa
        if (! $this->option('force') && Setting::get('full_auto_sync', '0') !== '1') {
            $this->info('full_auto_sync nonaktif — dilewati. Pakai --force untuk paksa jalan.');

            return self::SUCCESS;
        }

        if (! $erp->isConfigured()) {
            $this->warn('ERP HPY belum dikonfigurasi — sync dilewati.');

            return self::SUCCESS;
        }

        // Internet mati / server tidak terjangkau → langsung lewati tanpa
        // menunggu timeout per request. Dicoba lagi di jadwal berikutnya.
        if (! $erp->quickPing()) {
            $this->warn('Server ERP HPY tidak terjangkau — sync dilewati, dicoba lagi nanti.');

            return self::SUCCESS;
        }

        $result = $pull->syncEverything((int) $this->option('since-days'));

        foreach ($result['steps'] as $step => $r) {
            // `success` di langkah transactions adalah jumlah terkirim (int) —
            // hanya false yang berarti gagal.
            $ok = ($r['success'] ?? null) !== false;
            $line = match ($step) {
                'products' => sprintf('Produk: %s baru, %s diupdate', $r['imported'] ?? 0, $r['updated'] ?? 0),
                'customers' => sprintf('Customer: %s baru, %s diupdate', $r['imported'] ?? 0, $r['updated'] ?? 0),
                'prices' => sprintf('Harga jual: %s berubah', $r['updated'] ?? 0),
                'transactions' => sprintf('Transaksi: %s terkirim, %s gagal', $r['success'] ?? 0, $r['failed'] ?? 0),
                default => $step,
            };

            if ($ok) {
                $this->line($line);
            } else {
                $this->error("{$step}: ".($r['error'] ?? 'gagal'));
            }
        }

        if ($result['failed_steps']) {
            Log::warning('Auto full-sync ERP HPY sebagian gagal', ['failed_steps' => $result['failed_steps']]);
        } else {
            Log::info('Auto full-sync ERP HPY selesai');
        }

        return self::SUCCESS;
    }
}
