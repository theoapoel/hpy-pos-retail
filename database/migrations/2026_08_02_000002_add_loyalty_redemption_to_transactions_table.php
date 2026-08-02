<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('loyalty_points_redeemed', 10, 2)->default(0)->after('coupon_discount');
            $table->decimal('loyalty_amount', 15, 2)->default(0)->after('loyalty_points_redeemed');
            $table->string('loyalty_program')->nullable()->after('loyalty_amount');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['loyalty_points_redeemed', 'loyalty_amount', 'loyalty_program']);
        });
    }
};
