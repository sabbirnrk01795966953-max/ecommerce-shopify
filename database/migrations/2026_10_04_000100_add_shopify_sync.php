<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('shopify_product_id')->nullable()->unique()->after('id');
            $table->string('shopify_handle')->nullable()->after('slug');
            $table->string('shopify_vendor')->nullable()->after('shopify_handle');
            $table->string('shopify_product_type')->nullable()->after('shopify_vendor');
            $table->string('shopify_status', 40)->nullable()->after('shopify_product_type');
            $table->longText('shopify_tags')->nullable()->after('shopify_status');
            $table->longText('shopify_options')->nullable()->after('shopify_tags');
            $table->longText('shopify_collections')->nullable()->after('shopify_options');
            $table->text('main_image_url')->nullable()->after('main_image_path');
            $table->timestamp('shopify_synced_at')->nullable()->after('video_url');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->string('shopify_media_id')->nullable()->unique()->after('product_id');
            $table->text('source_url')->nullable()->after('path');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_variant_id')->nullable()->unique();
            $table->string('title')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('barcode')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('compare_price', 12, 2)->nullable();
            $table->integer('inventory_qty')->default(0);
            $table->longText('option_values')->nullable();
            $table->text('image_url')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropUnique(['shopify_media_id']);
            $table->dropColumn(['shopify_media_id', 'source_url']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['shopify_product_id']);
            $table->dropColumn([
                'shopify_product_id',
                'shopify_handle',
                'shopify_vendor',
                'shopify_product_type',
                'shopify_status',
                'shopify_tags',
                'shopify_options',
                'shopify_collections',
                'main_image_url',
                'shopify_synced_at',
            ]);
        });
    }
};
