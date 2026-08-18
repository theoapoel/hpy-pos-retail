<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Nomor POS Invoice ERP HPY yang diretur, untuk retur yang berangkat
            // dari struk ERP langsung (invoice asalnya tidak ada di tabel lokal).
            // Pada retur atas transaksi lokal, kolom ini kosong dan yang dipakai
            // adalah relasi return_against_id.
            $table->string('return_against_erp', 140)->nullable()->after('return_against_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('return_against_erp');
        });
    }
};
