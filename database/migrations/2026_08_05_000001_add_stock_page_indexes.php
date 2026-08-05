<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Halaman stok menyaring produk aktif + track_stock lalu mengurutkan by name.
            $table->index(['is_active', 'track_stock'], 'products_active_track_index');
            $table->index('name', 'products_name_index');
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->index(['warehouse_id', 'quantity'], 'product_stocks_wh_qty_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_active_track_index');
            $table->dropIndex('products_name_index');
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropIndex('product_stocks_wh_qty_index');
        });
    }
};
