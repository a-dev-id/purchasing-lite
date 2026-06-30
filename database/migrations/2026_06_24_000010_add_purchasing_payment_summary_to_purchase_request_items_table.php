<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_request_items', 'purchasing_payment_method')) {
                $table->string('purchasing_payment_method', 30)->nullable()->after('purchasing_remarks');
            }

            if (! Schema::hasColumn('purchase_request_items', 'purchasing_payment_note')) {
                $table->text('purchasing_payment_note')->nullable()->after('purchasing_payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_request_items', 'purchasing_payment_note')) {
                $table->dropColumn('purchasing_payment_note');
            }

            if (Schema::hasColumn('purchase_request_items', 'purchasing_payment_method')) {
                $table->dropColumn('purchasing_payment_method');
            }
        });
    }
};
