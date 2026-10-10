<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OmsCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OmsCatalogController extends Controller
{
    public function __construct(private readonly OmsCatalogService $catalog)
    {
    }

    public function ping(): JsonResponse
    {
        return response()->json($this->catalog->pingPayload());
    }

    public function taxonomy(): JsonResponse
    {
        return response()->json($this->catalog->taxonomyPayload());
    }

    public function products(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(
            $this->catalog->exportProducts((int) ($validated['limit'] ?? 100), isset($validated['cursor']) ? (string) $validated['cursor'] : null)
        );
    }

    public function show(string $sku): JsonResponse
    {
        $product = $this->catalog->findExactSku($sku);

        return response()->json($this->catalog->productPayload($product));
    }

    public function upsert(Request $request, string $sku): JsonResponse
    {
        $validated = $request->validate([
            'omsProductId' => ['nullable', 'string', 'max:255'],
            'parentSku' => ['nullable', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255'],
            'inventoryKind' => ['nullable', 'string', 'max:60'],
            'stockQty' => ['required', 'integer', 'min:0'],
            'banglaName' => ['required', 'string', 'max:255'],
            'englishName' => ['nullable', 'string', 'max:255'],
            'productSlug' => ['required', 'string', 'max:500'],
            'sellingPrice' => ['required', 'numeric', 'min:0'],
            'oldPrice' => ['nullable', 'numeric', 'min:0'],
            'active' => ['required', 'boolean'],
            'featured' => ['nullable', 'boolean'],
            'sortOrder' => ['nullable', 'integer', 'min:0'],
            'shortDescriptionHtml' => ['nullable', 'string'],
            'fullDescriptionHtml' => ['nullable', 'string'],
            'metaTitle' => ['nullable', 'string', 'max:255'],
            'metaDescription' => ['nullable', 'string', 'max:5000'],
            'externalVideoUrl' => ['nullable', 'string', 'max:4000'],

            'taxonomy' => ['nullable', 'array'],
            'taxonomy.*.externalId' => ['required_with:taxonomy', 'string', 'max:100'],
            'taxonomy.*.type' => ['required_with:taxonomy', Rule::in(['CATEGORY', 'SUBCATEGORY', 'COLLECTION'])],
            'taxonomy.*.parentExternalId' => ['nullable', 'string', 'max:100'],
            'taxonomy.*.name' => ['nullable', 'string', 'max:255'],
            'taxonomy.*.slug' => ['nullable', 'string', 'max:500'],

            'media' => ['nullable', 'array'],
            'media.*.id' => ['required_with:media', 'string', 'max:255'],
            'media.*.kind' => ['required_with:media', Rule::in(['IMAGE', 'VIDEO'])],
            'media.*.isPrimary' => ['nullable', 'boolean'],
            'media.*.sortOrder' => ['nullable', 'integer', 'min:0'],
            'media.*.originalFileName' => ['nullable', 'string', 'max:255'],
            'media.*.mimeType' => ['nullable', 'string', 'max:255'],
            'media.*.sizeBytes' => ['nullable', 'integer', 'min:0'],
            'media.*.checksum' => ['nullable', 'string', 'max:255'],
            'media.*.downloadUrl' => ['required_with:media', 'url:http,https', 'max:8000'],
        ]);

        $result = $this->catalog->upsert($sku, $validated);
        $product = $result['product'];

        return response()->json([
            'success' => true,
            'created' => (bool) $result['created'],
            'externalProductId' => (string) $product->id,
            'sku' => (string) $product->sku,
            'productSlug' => (string) $product->slug,
            'publicUrl' => route('product.show', $product->slug),
            'mediaStatus' => $result['mediaStatus'],
            'updatedAt' => optional($product->updated_at)->utc()?->toIso8601String(),
        ], $result['created'] ? 201 : 200);
    }

    public function stock(Request $request, string $sku): JsonResponse
    {
        $validated = $request->validate([
            'omsProductId' => ['nullable', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255'],
            'stockQty' => ['required', 'integer', 'min:0'],
        ]);

        if ($sku !== $validated['sku']) {
            return response()->json([
                'success' => false,
                'message' => 'URL SKU and body SKU must match exactly.',
            ], 422);
        }

        $product = $this->catalog->findExactSku($sku);

        if (! empty($validated['omsProductId']) && $product->oms_product_id && $product->oms_product_id !== $validated['omsProductId']) {
            return response()->json([
                'success' => false,
                'message' => 'OMS product ID does not match the linked Laravel product.',
            ], 409);
        }

        $product->update(['stock_qty' => $validated['stockQty']]);

        return response()->json([
            'success' => true,
            'externalProductId' => (string) $product->id,
            'sku' => (string) $product->sku,
            'stockQty' => (int) $product->stock_qty,
        ]);
    }

    public function unpublish(Request $request, string $sku): JsonResponse
    {
        $validated = $request->validate([
            'omsProductId' => ['nullable', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
        ]);

        if ($sku !== $validated['sku']) {
            return response()->json([
                'success' => false,
                'message' => 'URL SKU and body SKU must match exactly.',
            ], 422);
        }

        $product = $this->catalog->findExactSku($sku);

        if (! empty($validated['omsProductId']) && $product->oms_product_id && $product->oms_product_id !== $validated['omsProductId']) {
            return response()->json([
                'success' => false,
                'message' => 'OMS product ID does not match the linked Laravel product.',
            ], 409);
        }

        $product->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'externalProductId' => (string) $product->id,
            'sku' => (string) $product->sku,
            'active' => false,
        ]);
    }
}
