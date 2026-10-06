<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_ip_hash', 64)->nullable()->after('purchase_event_id')->index();
            $table->string('phone_normalized', 32)->nullable()->after('phone')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['order_ip_hash']);
            $table->dropIndex(['phone_normalized']);
            $table->dropColumn(['order_ip_hash', 'phone_normalized']);
        });
    }
};
