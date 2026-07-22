<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Delivery Note yang gagal submit karena stok tidak cukup tetap tersimpan sebagai
     * draft di HPY. Nilai enum 'draft' membedakannya dari 'failed' (gagal dibuat total)
     * dan 'synced' (sudah submit), sekaligus tetap mengizinkan sync ulang.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE delivery_shipments MODIFY COLUMN erp_sync_status ENUM('none','synced','failed','draft') NOT NULL DEFAULT 'none'");
    }

    public function down(): void
    {
        DB::statement("UPDATE delivery_shipments SET erp_sync_status = 'failed' WHERE erp_sync_status = 'draft'");
        DB::statement("ALTER TABLE delivery_shipments MODIFY COLUMN erp_sync_status ENUM('none','synced','failed') NOT NULL DEFAULT 'none'");
    }
};
