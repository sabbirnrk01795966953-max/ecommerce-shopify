<?php

namespace Tests\Feature;

use App\Jobs\DownloadOmsProductMedia;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OmsCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-oms-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oms_catalog.token' => $this->token,
            'oms_catalog.mode' => 'COLLECTION',
            'queue.default' => 'sync',
        ]);

        Setting::setValue('site_name', 'Test Store');
    }

    public function test_missing_or_bad_token_returns_401(): void
    {
        $this->getJson('/api/oms/v1/ping')->assertStatus(401);
        $this->withHeader('X-OMS-Token', 'wrong')->getJson('/api/oms/v1/ping')->assertStatus(401);
    }

    public function test_ping_works(): void
    {
        $this->omsGet('/api/oms/v1/ping')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('engine', 'LARAVEL')
            ->assertJsonPath('catalogMode', 'COLLECTION');
    }

    public function test_taxonomy_export_works(): void
    {
        [$category, $subcategory] = $this->taxonomy();

        $this->omsGet('/api/oms/v1/taxonomy')
            ->assertOk()
            ->assertJsonFragment([
                'externalId' => (string) $subcategory->id,
                'type' => 'COLLECTION',
                'slug' => $subcategory->slug,
            ]);
    }

    public function test_unicode_bangla_emoji_slug_round_trips_exactly(): void
    {
        [, $subcategory] = $this->taxonomy();
        $slug = '🌟একটি-সুন্দর-ঘড়ি-শুধু-সময়-দেখার-জন্য-নয়-luxury-watch';

        $payload = $this->payload('SKU-UNICODE', $subcategory->id);
        $payload['productSlug'] = $slug;

        $this->omsPut('/api/oms/v1/products/SKU-UNICODE', $payload)
            ->assertCreated()
            ->assertJsonPath('productSlug', $slug);

        $this->omsGet('/api/oms/v1/products/SKU-UNICODE')
            ->assertOk()
            ->assertJsonPath('productSlug', $slug);

        $this->assertDatabaseHas('products', ['sku' => 'SKU-UNICODE', 'slug' => $slug]);
    }

    public function test_existing_sku_updates_instead_of_duplicating(): void
    {
        [, $subcategory] = $this->taxonomy();
        $product = $this->product('SKU-1', $subcategory->id);

        $payload = $this->payload('SKU-1', $subcategory->id);
        $payload['sellingPrice'] = 999;

        $this->omsPut('/api/oms/v1/products/SKU-1', $payload)
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertSame(1, Product::query()->where('sku', 'SKU-1')->count());
        $this->assertSame('999.00', Product::query()->findOrFail($product->id)->price);
    }

    public function test_missing_sku_creates_product(): void
    {
        [, $subcategory] = $this->taxonomy();

        $this->omsPut('/api/oms/v1/products/NEW-SKU', $this->payload('NEW-SKU', $subcategory->id))
            ->assertCreated()
            ->assertJsonPath('created', true);

        $this->assertDatabaseHas('products', ['sku' => 'NEW-SKU']);
    }

    public function test_conflicting_oms_product_link_returns_409(): void
    {
        [, $subcategory] = $this->taxonomy();
        $a = $this->product('SKU-A', $subcategory->id);
        $a->update(['oms_product_id' => 'oms-same-id']);

        $payload = $this->payload('SKU-B', $subcategory->id);
        $payload['omsProductId'] = 'oms-same-id';

        $this->omsPut('/api/oms/v1/products/SKU-B', $payload)->assertStatus(409);
    }

    public function test_collection_assignment_works(): void
    {
        [, $subcategory] = $this->taxonomy();

        $this->omsPut('/api/oms/v1/products/COLL-1', $this->payload('COLL-1', $subcategory->id))
            ->assertCreated();

        $this->assertDatabaseHas('products', [
            'sku' => 'COLL-1',
            'subcategory_id' => $subcategory->id,
        ]);
    }

    public function test_subcategory_assignment_works(): void
    {
        config(['oms_catalog.mode' => 'SUBCATEGORY']);
        [$category, $subcategory] = $this->taxonomy();

        $payload = $this->payload('SUB-1', $subcategory->id);
        $payload['taxonomy'] = [
            [
                'externalId' => (string) $category->id,
                'type' => 'CATEGORY',
                'parentExternalId' => null,
                'name' => $category->name_bn,
                'slug' => $category->slug,
            ],
            [
                'externalId' => (string) $subcategory->id,
                'type' => 'SUBCATEGORY',
                'parentExternalId' => (string) $category->id,
                'name' => $subcategory->name_bn,
                'slug' => $subcategory->slug,
            ],
        ];

        $this->omsPut('/api/oms/v1/products/SUB-1', $payload)->assertCreated();

        $this->assertDatabaseHas('products', [
            'sku' => 'SUB-1',
            'subcategory_id' => $subcategory->id,
        ]);
    }

    public function test_invalid_taxonomy_returns_422(): void
    {
        $payload = $this->payload('BAD-TAX', 999999);

        $this->omsPut('/api/oms/v1/products/BAD-TAX', $payload)->assertStatus(422);
    }

    public function test_stock_only_update_changes_only_stock(): void
    {
        [, $subcategory] = $this->taxonomy();
        $product = $this->product('STOCK-1', $subcategory->id);
        $before = $product->only(['name_bn', 'slug', 'price', 'subcategory_id']);

        $this->withHeader('X-OMS-Token', $this->token)
            ->patchJson('/api/oms/v1/products/STOCK-1/stock', [
                'sku' => 'STOCK-1',
                'stockQty' => 37,
            ])
            ->assertOk()
            ->assertJsonPath('stockQty', 37);

        $product->refresh();
        $this->assertSame(37, $product->stock_qty);
        $this->assertSame($before, $product->only(['name_bn', 'slug', 'price', 'subcategory_id']));
    }

    public function test_unpublish_hides_without_deleting(): void
    {
        [, $subcategory] = $this->taxonomy();
        $product = $this->product('HIDE-1', $subcategory->id);

        $this->withHeader('X-OMS-Token', $this->token)
            ->postJson('/api/oms/v1/products/HIDE-1/unpublish', [
                'sku' => 'HIDE-1',
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('active', false);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'sku' => 'HIDE-1',
            'is_active' => false,
        ]);
    }

    public function test_media_checksum_prevents_duplicate_download_queueing(): void
    {
        Storage::fake('public');
        Queue::fake();

        [, $subcategory] = $this->taxonomy();
        $product = $this->product('MEDIA-1', $subcategory->id);
        Storage::disk('public')->put('products/current.webp', 'existing-image');

        $product->update([
            'main_image_path' => 'products/current.webp',
            'oms_main_media_id' => 'media-1',
            'oms_main_media_checksum' => 'same-checksum',
        ]);

        $payload = $this->payload('MEDIA-1', $subcategory->id);
        $payload['media'] = [[
            'id' => 'media-1',
            'kind' => 'IMAGE',
            'isPrimary' => true,
            'sortOrder' => 0,
            'originalFileName' => 'main.webp',
            'mimeType' => 'image/webp',
            'sizeBytes' => 100,
            'checksum' => 'same-checksum',
            'downloadUrl' => 'https://media.example/main.webp?secret=do-not-store',
        ]];

        $this->omsPut('/api/oms/v1/products/MEDIA-1', $payload)
            ->assertOk()
            ->assertJsonPath('mediaStatus', 'unchanged');

        Queue::assertNotPushed(DownloadOmsProductMedia::class);
    }

    public function test_primary_and_gallery_order_metadata_is_accepted(): void
    {
        Queue::fake();

        [, $subcategory] = $this->taxonomy();
        $payload = $this->payload('MEDIA-ORDER', $subcategory->id);
        $payload['media'] = [
            [
                'id' => 'primary-1',
                'kind' => 'IMAGE',
                'isPrimary' => true,
                'sortOrder' => 0,
                'checksum' => 'c1',
                'downloadUrl' => 'https://media.example/main.webp',
            ],
            [
                'id' => 'gallery-2',
                'kind' => 'IMAGE',
                'isPrimary' => false,
                'sortOrder' => 2,
                'checksum' => 'c2',
                'downloadUrl' => 'https://media.example/2.webp',
            ],
            [
                'id' => 'gallery-1',
                'kind' => 'IMAGE',
                'isPrimary' => false,
                'sortOrder' => 1,
                'checksum' => 'c3',
                'downloadUrl' => 'https://media.example/1.webp',
            ],
        ];

        $this->omsPut('/api/oms/v1/products/MEDIA-ORDER', $payload)
            ->assertCreated()
            ->assertJsonPath('mediaStatus', 'queued');

        Queue::assertPushed(DownloadOmsProductMedia::class, 3);
    }

    public function test_signed_download_url_is_not_persisted_in_product_or_job_payload(): void
    {
        Queue::fake();

        [, $subcategory] = $this->taxonomy();
        $signed = 'https://media.example/private.webp?X-Amz-Signature=very-secret';
        $payload = $this->payload('NO-SIGNED-URL', $subcategory->id);
        $payload['media'] = [[
            'id' => 'private-1',
            'kind' => 'IMAGE',
            'isPrimary' => true,
            'sortOrder' => 0,
            'checksum' => 'checksum-1',
            'downloadUrl' => $signed,
        ]];

        $this->omsPut('/api/oms/v1/products/NO-SIGNED-URL', $payload)->assertCreated();

        $product = Product::query()->where('sku', 'NO-SIGNED-URL')->firstOrFail();
        $this->assertFalse(str_contains(json_encode($product->toArray()), $signed));

        Queue::assertPushed(DownloadOmsProductMedia::class, function (DownloadOmsProductMedia $job) use ($signed) {
            return ! str_contains(json_encode($job), $signed);
        });
    }

    public function test_product_export_uses_cursor_pagination_and_exact_sku_lookup(): void
    {
        [, $subcategory] = $this->taxonomy();
        $this->product('PAGE-1', $subcategory->id);
        $this->product('PAGE-2', $subcategory->id);
        $this->product('PAGE-3', $subcategory->id);

        $first = $this->omsGet('/api/oms/v1/products?limit=2')
            ->assertOk()
            ->json();

        $this->assertCount(2, $first['items']);
        $this->assertNotNull($first['nextCursor']);

        $this->omsGet('/api/oms/v1/products/PAGE-2')
            ->assertOk()
            ->assertJsonPath('sku', 'PAGE-2');
    }

    private function taxonomy(): array
    {
        $category = Category::query()->create([
            'name_bn' => 'ক্যাটাগরি',
            'name_en' => 'Category',
            'slug' => 'category',
            'icon' => '⭐',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $subcategory = Subcategory::query()->create([
            'category_id' => $category->id,
            'name_bn' => 'কালেকশন',
            'name_en' => 'Collection',
            'slug' => 'collection',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$category, $subcategory];
    }

    private function product(string $sku, int $subcategoryId): Product
    {
        return Product::query()->create([
            'subcategory_id' => $subcategoryId,
            'name_bn' => 'পণ্য '.$sku,
            'name_en' => 'Product '.$sku,
            'slug' => strtolower(str_replace('_', '-', $sku)).'-slug',
            'sku' => $sku,
            'price' => 500,
            'compare_price' => 600,
            'stock_qty' => 10,
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 0,
        ]);
    }

    private function payload(string $sku, int $subcategoryId): array
    {
        return [
            'omsProductId' => 'oms-'.$sku,
            'parentSku' => $sku,
            'sku' => $sku,
            'inventoryKind' => 'PHYSICAL',
            'stockQty' => 25,
            'banglaName' => 'বাংলা নাম '.$sku,
            'englishName' => 'Product '.$sku,
            'productSlug' => 'product-'.strtolower($sku),
            'sellingPrice' => 1200,
            'oldPrice' => 1500,
            'active' => true,
            'featured' => false,
            'sortOrder' => 0,
            'shortDescriptionHtml' => '<p>Short</p>',
            'fullDescriptionHtml' => '<p>Full</p>',
            'metaTitle' => 'Meta',
            'metaDescription' => 'Description',
            'externalVideoUrl' => '',
            'taxonomy' => [[
                'externalId' => (string) $subcategoryId,
                'type' => 'COLLECTION',
                'parentExternalId' => null,
                'name' => 'Collection',
                'slug' => 'collection',
            ]],
            'media' => [],
        ];
    }

    private function omsGet(string $url)
    {
        return $this->withHeader('X-OMS-Token', $this->token)->getJson($url);
    }

    private function omsPut(string $url, array $payload)
    {
        return $this->withHeader('X-OMS-Token', $this->token)->putJson($url, $payload);
    }
}
