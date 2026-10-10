<?php

namespace App\Services;

use App\Jobs\DownloadOmsProductMedia;
use App\Jobs\FinalizeOmsProductMedia;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Models\Subcategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OmsCatalogService
{
    public function mode(): string
    {
        $mode = strtoupper((string) config('oms_catalog.mode', 'COLLECTION'));

        if (! in_array($mode, ['COLLECTION', 'SUBCATEGORY'], true)) {
            throw new RuntimeException('OMS_CATALOG_MODE must be COLLECTION or SUBCATEGORY.');
        }

        return $mode;
    }

    public function pingPayload(): array
    {
        return [
            'success' => true,
            'storeName' => (string) Setting::getValue('site_name', config('app.name')),
            'engine' => 'LARAVEL',
            'catalogMode' => $this->mode(),
            'version' => (string) config('oms_catalog.version', '1'),
        ];
    }

    public function taxonomyPayload(): array
    {
        $mode = $this->mode();
        $terms = [];

        if ($mode === 'COLLECTION') {
            Subcategory::query()
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (&$terms) {
                    foreach ($rows as $row) {
                        $terms[] = [
                            'externalId' => (string) $row->id,
                            'type' => 'COLLECTION',
                            'parentExternalId' => null,
                            'name' => (string) ($row->name_bn ?: $row->name_en),
                            'slug' => (string) $row->slug,
                            'active' => (bool) $row->is_active,
                        ];
                    }
                });
        } else {
            Category::query()->orderBy('id')->chunkById(200, function ($rows) use (&$terms) {
                foreach ($rows as $row) {
                    $terms[] = [
                        'externalId' => (string) $row->id,
                        'type' => 'CATEGORY',
                        'parentExternalId' => null,
                        'name' => (string) ($row->name_bn ?: $row->name_en),
                        'slug' => (string) $row->slug,
                        'active' => (bool) $row->is_active,
                    ];
                }
            });

            Subcategory::query()->orderBy('id')->chunkById(200, function ($rows) use (&$terms) {
                foreach ($rows as $row) {
                    $terms[] = [
                        'externalId' => (string) $row->id,
                        'type' => 'SUBCATEGORY',
                        'parentExternalId' => (string) $row->category_id,
                        'name' => (string) ($row->name_bn ?: $row->name_en),
                        'slug' => (string) $row->slug,
                        'active' => (bool) $row->is_active,
                    ];
                }
            });
        }

        return [
            'success' => true,
            'catalogMode' => $mode,
            'terms' => $terms,
        ];
    }

    public function exportProducts(int $limit, ?string $cursor): array
    {
        $afterId = $cursor !== null && $cursor !== '' ? (int) $cursor : 0;

        $rows = Product::query()
            ->with(['subcategory.category', 'images'])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (Product $product) => $this->productPayload($product))->values();

        return [
            'items' => $items,
            'nextCursor' => $hasMore && $items->isNotEmpty()
                ? (string) $rows->take($limit)->last()->id
                : null,
        ];
    }

    public function findExactSku(string $sku): Product
    {
        $matches = Product::query()
            ->where('sku', $sku)
            ->limit(2)
            ->get();

        if ($matches->isEmpty()) {
            abort(response()->json([
                'success' => false,
                'message' => 'Product SKU not found.',
            ], 404));
        }

        if ($matches->count() > 1) {
            abort(response()->json([
                'success' => false,
                'message' => 'Duplicate SKU conflict.',
            ], 409));
        }

        return $matches->first();
    }

    public function productPayload(Product $product): array
    {
        $product->loadMissing(['subcategory.category', 'images']);

        $media = [];

        if ($product->main_image_path || $product->main_image_url) {
            $media[] = $this->exportMediaRow(
                kind: 'IMAGE',
                isPrimary: true,
                sortOrder: 0,
                localPath: $product->main_image_path,
                externalUrl: $product->main_image_url,
                checksum: $product->oms_main_media_checksum
            );
        }

        foreach ($product->images as $image) {
            $media[] = $this->exportMediaRow(
                kind: 'IMAGE',
                isPrimary: false,
                sortOrder: (int) $image->sort_order,
                localPath: $image->path,
                externalUrl: $image->source_url,
                checksum: $image->oms_media_checksum
            );
        }

        if ($product->video_path) {
            $media[] = $this->exportMediaRow(
                kind: 'VIDEO',
                isPrimary: false,
                sortOrder: 0,
                localPath: $product->video_path,
                externalUrl: null,
                checksum: $product->oms_video_media_checksum
            );
        }

        return [
            'externalProductId' => (string) $product->id,
            'sku' => (string) $product->sku,
            'banglaName' => (string) $product->name_bn,
            'englishName' => (string) ($product->name_en ?? ''),
            'productSlug' => (string) $product->slug,
            'sellingPrice' => (float) $product->price,
            'oldPrice' => $product->compare_price !== null ? (float) $product->compare_price : null,
            'stockQty' => (int) $product->stock_qty,
            'active' => (bool) $product->is_active,
            'featured' => (bool) $product->is_featured,
            'sortOrder' => (int) $product->sort_order,
            'shortDescriptionHtml' => (string) ($product->short_description ?? ''),
            'fullDescriptionHtml' => (string) ($product->description_html ?? ''),
            'metaTitle' => (string) ($product->meta_title ?? ''),
            'metaDescription' => (string) ($product->meta_description ?? ''),
            'externalVideoUrl' => (string) ($product->video_url ?? ''),
            'taxonomy' => $this->productTaxonomy($product),
            'media' => $media,
        ];
    }

    public function upsert(string $sku, array $data): array
    {
        if ($sku !== (string) $data['sku']) {
            throw ValidationException::withMessages([
                'sku' => 'URL SKU and body SKU must match exactly.',
            ]);
        }

        $matches = Product::query()->where('sku', $sku)->limit(2)->get();
        if ($matches->count() > 1) {
            abort(response()->json(['success' => false, 'message' => 'Duplicate SKU conflict.'], 409));
        }

        $product = $matches->first();
        $created = ! $product;

        if (! empty($data['omsProductId'])) {
            $omsConflict = Product::query()
                ->where('oms_product_id', $data['omsProductId'])
                ->when($product, fn ($q) => $q->whereKeyNot($product->id))
                ->exists();

            if ($omsConflict) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'OMS product ID is already linked to another SKU.',
                ], 409));
            }
        }

        $slug = (string) $data['productSlug'];
        $slugConflict = Product::query()
            ->where('slug', $slug)
            ->when($product, fn ($q) => $q->whereKeyNot($product->id))
            ->exists();

        if ($slugConflict) {
            abort(response()->json([
                'success' => false,
                'message' => 'Product slug is already used by another product.',
            ], 409));
        }

        $subcategoryId = $this->resolveTaxonomy($data['taxonomy'] ?? []);

        $product = DB::transaction(function () use ($product, $created, $data, $subcategoryId) {
            $payload = [
                'oms_product_id' => $data['omsProductId'] ?? null,
                'subcategory_id' => $subcategoryId,
                'name_bn' => $data['banglaName'],
                'name_en' => $data['englishName'] ?? null,
                'slug' => $data['productSlug'],
                'sku' => $data['sku'],
                'price' => $data['sellingPrice'],
                'compare_price' => $data['oldPrice'] ?? null,
                'stock_qty' => $data['stockQty'],
                'is_active' => $data['active'],
                'is_featured' => $data['featured'] ?? false,
                'sort_order' => $data['sortOrder'] ?? 0,
                'short_description' => $data['shortDescriptionHtml'] ?? '',
                'description_html' => $data['fullDescriptionHtml'] ?? '',
                'meta_title' => $data['metaTitle'] ?? null,
                'meta_description' => $data['metaDescription'] ?? null,
                'video_url' => filled($data['externalVideoUrl'] ?? null) ? $data['externalVideoUrl'] : null,
            ];

            if ($created) {
                $product = Product::create($payload);
            } else {
                $product->update($payload);
            }

            return $product->fresh();
        });

        $mediaStatus = $this->queueMediaSync($product, $data['media'] ?? []);

        return [
            'product' => $product,
            'created' => $created,
            'mediaStatus' => $mediaStatus,
        ];
    }

    public function queueMediaSync(Product $product, array $media): string
    {
        if ($media === []) {
            return 'none';
        }

        $primaryCount = collect($media)->where('kind', 'IMAGE')->where('isPrimary', true)->count();
        $videoCount = collect($media)->where('kind', 'VIDEO')->count();

        if ($primaryCount > 1 || $videoCount > 1) {
            throw ValidationException::withMessages([
                'media' => 'Only one primary image and one uploaded video are supported by this storefront.',
            ]);
        }

        $expectedGallery = [];
        $queued = false;

        foreach ($media as $row) {
            $kind = strtoupper((string) $row['kind']);
            $isPrimary = (bool) ($row['isPrimary'] ?? false);
            $mediaId = (string) $row['id'];
            $checksum = (string) ($row['checksum'] ?? '');

            if ($kind === 'IMAGE' && ! $isPrimary) {
                $expectedGallery[$mediaId] = $checksum;
            }

            if ($this->mediaAlreadyCurrent($product, $row)) {
                continue;
            }

            $cacheKey = 'oms-signed-url:'.Str::uuid();
            Cache::put(
                $cacheKey,
                Crypt::encryptString((string) $row['downloadUrl']),
                now()->addMinutes((int) config('oms_catalog.signed_url_cache_minutes', 20))
            );

            DownloadOmsProductMedia::dispatch(
                $product->id,
                collect($row)->except('downloadUrl')->all(),
                $cacheKey
            );

            $queued = true;
        }

        FinalizeOmsProductMedia::dispatch($product->id, $expectedGallery);

        return $queued ? 'queued' : 'unchanged';
    }

    private function mediaAlreadyCurrent(Product $product, array $row): bool
    {
        $kind = strtoupper((string) $row['kind']);
        $mediaId = (string) $row['id'];
        $checksum = (string) ($row['checksum'] ?? '');
        $isPrimary = (bool) ($row['isPrimary'] ?? false);

        if ($kind === 'IMAGE' && $isPrimary) {
            return $product->oms_main_media_id === $mediaId
                && (string) $product->oms_main_media_checksum === $checksum
                && $product->main_image_path
                && Storage::disk('public')->exists($product->main_image_path);
        }

        if ($kind === 'VIDEO') {
            return $product->oms_video_media_id === $mediaId
                && (string) $product->oms_video_media_checksum === $checksum
                && $product->video_path
                && Storage::disk('public')->exists($product->video_path);
        }

        return ProductImage::query()
            ->where('product_id', $product->id)
            ->where('oms_media_id', $mediaId)
            ->where('oms_media_checksum', $checksum)
            ->whereNotNull('path')
            ->get()
            ->contains(fn (ProductImage $image) => $image->path && Storage::disk('public')->exists($image->path));
    }

    private function resolveTaxonomy(array $taxonomy): ?int
    {
        if ($taxonomy === []) {
            return null;
        }

        $mode = $this->mode();

        if ($mode === 'COLLECTION') {
            $terms = collect($taxonomy)->where('type', 'COLLECTION')->values();

            if ($terms->count() !== 1) {
                throw ValidationException::withMessages([
                    'taxonomy' => 'COLLECTION mode requires exactly one COLLECTION term.',
                ]);
            }

            $id = (int) $terms[0]['externalId'];
            $subcategory = Subcategory::query()->find($id);

            if (! $subcategory) {
                throw ValidationException::withMessages([
                    'taxonomy' => 'Invalid collection externalId: '.$terms[0]['externalId'],
                ]);
            }

            return $subcategory->id;
        }

        $subTerms = collect($taxonomy)->where('type', 'SUBCATEGORY')->values();
        if ($subTerms->count() !== 1) {
            throw ValidationException::withMessages([
                'taxonomy' => 'SUBCATEGORY mode requires exactly one SUBCATEGORY term.',
            ]);
        }

        $sub = Subcategory::query()->with('category')->find((int) $subTerms[0]['externalId']);
        if (! $sub) {
            throw ValidationException::withMessages([
                'taxonomy' => 'Invalid subcategory externalId: '.$subTerms[0]['externalId'],
            ]);
        }

        $categoryTerms = collect($taxonomy)->where('type', 'CATEGORY')->values();
        if ($categoryTerms->isNotEmpty()) {
            $categoryId = (int) $categoryTerms[0]['externalId'];
            if ($categoryId !== (int) $sub->category_id) {
                throw ValidationException::withMessages([
                    'taxonomy' => 'Category does not match the supplied subcategory.',
                ]);
            }
        }

        return $sub->id;
    }

    private function productTaxonomy(Product $product): array
    {
        $subcategory = $product->subcategory;
        if (! $subcategory) {
            return [];
        }

        if ($this->mode() === 'COLLECTION') {
            return [[
                'externalId' => (string) $subcategory->id,
                'type' => 'COLLECTION',
                'parentExternalId' => null,
                'name' => (string) ($subcategory->name_bn ?: $subcategory->name_en),
                'slug' => (string) $subcategory->slug,
            ]];
        }

        $terms = [];
        if ($subcategory->category) {
            $terms[] = [
                'externalId' => (string) $subcategory->category->id,
                'type' => 'CATEGORY',
                'parentExternalId' => null,
                'name' => (string) ($subcategory->category->name_bn ?: $subcategory->category->name_en),
                'slug' => (string) $subcategory->category->slug,
            ];
        }

        $terms[] = [
            'externalId' => (string) $subcategory->id,
            'type' => 'SUBCATEGORY',
            'parentExternalId' => (string) $subcategory->category_id,
            'name' => (string) ($subcategory->name_bn ?: $subcategory->name_en),
            'slug' => (string) $subcategory->slug,
        ];

        return $terms;
    }

    private function exportMediaRow(
        string $kind,
        bool $isPrimary,
        int $sortOrder,
        ?string $localPath,
        ?string $externalUrl,
        ?string $checksum
    ): array {
        $url = $externalUrl;
        $fileName = null;
        $mime = null;
        $size = null;

        if ($localPath && Storage::disk('public')->exists($localPath)) {
            $url = url(Storage::disk('public')->url($localPath));
            $fileName = basename($localPath);
            $absolute = Storage::disk('public')->path($localPath);
            $size = @filesize($absolute) ?: null;

            if (is_file($absolute) && class_exists(\finfo::class)) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($absolute) ?: null;
            }
        } elseif ($externalUrl) {
            $path = parse_url($externalUrl, PHP_URL_PATH);
            $fileName = $path ? basename($path) : null;
        }

        return [
            'kind' => $kind,
            'isPrimary' => $isPrimary,
            'sortOrder' => $sortOrder,
            'originalFileName' => $fileName,
            'mimeType' => $mime,
            'sizeBytes' => $size,
            'checksum' => $checksum ?: null,
            'url' => $url,
        ];
    }
}
