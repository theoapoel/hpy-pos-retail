<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Poin adalah milik ERP (buku besarnya Loyalty Point Entry). Kolom di sini
            // hanya cache hasil pull terakhir supaya badge di kasir bisa tampil tanpa
            // memanggil ERP untuk setiap baris daftar pelanggan.
            $table->string('erp_loyalty_program')->nullable()->after('loyalty_points');
            $table->timestamp('loyalty_synced_at')->nullable()->after('erp_loyalty_program');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['erp_loyalty_program', 'loyalty_synced_at']);
        });
    }
};
