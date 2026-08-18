<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // 'sale' = penjualan biasa; 'return' = retur (qty & nominal negatif),
            // dibuat lewat alur Tukar Barang dan disinkron sebagai POS Invoice
            // ber-is_return di ERP HPY.
            $table->string('type', 10)->default('sale')->after('status');
            $table->foreignId('return_against_id')->nullable()->after('type')
                ->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_against_id');
            $table->dropColumn('type');
        });
    }
};
