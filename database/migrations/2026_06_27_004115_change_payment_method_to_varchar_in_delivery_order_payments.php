<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Blueprint::change() doesn't support enum→varchar; use raw DDL.
        DB::statement("ALTER TABLE `delivery_order_payments` MODIFY COLUMN `payment_method` VARCHAR(100) NOT NULL DEFAULT 'Cash'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `delivery_order_payments` MODIFY COLUMN `payment_method` ENUM('cash','transfer','qris','card') NOT NULL DEFAULT 'cash'");
    }
};
