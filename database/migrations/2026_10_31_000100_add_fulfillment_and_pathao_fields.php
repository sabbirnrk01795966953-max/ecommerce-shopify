<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code')->unique();
            $table->unsignedInteger('order_count')->default(0);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('invoice_batch_id')->nullable()->after('purchase_event_id')
                ->constrained('invoice_batches')->nullOnDelete();
            $table->string('pathao_consignment_id')->nullable()->index()->after('invoice_batch_id');
            $table->string('pathao_status')->nullable()->after('pathao_consignment_id');
            $table->longText('pathao_response')->nullable()->after('pathao_status');
            $table->timestamp('dispatched_at')->nullable()->after('pathao_response');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_batch_id');
            $table->dropColumn([
                'pathao_consignment_id',
                'pathao_status',
                'pathao_response',
                'dispatched_at',
            ]);
        });

        Schema::dropIfExists('invoice_batches');
    }
};
