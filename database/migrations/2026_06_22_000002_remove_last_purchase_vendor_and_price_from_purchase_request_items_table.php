<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_request_items', 'last_purchase_unit_price')) {
                $table->dropColumn('last_purchase_unit_price');
            }

            if (Schema::hasColumn('purchase_request_items', 'last_purchase_vendor_name')) {
                $table->dropColumn('last_purchase_vendor_name');
            }
        });
    }

    public function down(): void
    {
        //
    }
};
