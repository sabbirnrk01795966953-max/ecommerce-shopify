<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Subcategory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ShopifyService
{
    public function connection(): array
    {
        $domain = $this->normalizeDomain((string) Setting::getValue('shopify_store_domain', ''));
        $version = trim((string) Setting::getValue('shopify_api_version', '2026-10')) ?: '2026-10';
        $clientId = trim((string) Setting::getValue('shopify_client_id', ''));
        $encrypted = trim((string) Setting::getValue('shopify_client_secret_encrypted', ''));

        $clientSecret = '';
        if ($encrypted !== '') {
            try {
                $clientSecret = Crypt::decryptString($encrypted);
            } catch (\Throwable) {
                $clientSecret = '';
            }
        }

        return compact('domain', 'version', 'clientId', 'clientSecret');
    }

    public function testConnection(?string $domain = null, ?string $clientId = null, ?string $clientSecret = null, ?string $version = null): array
    {
        $saved = $this->connection();
        $domain = $this->normalizeDomain($domain ?: $saved['domain']);
        $clientId = trim((string) ($clientId ?: $saved['clientId']));
        $clientSecret = trim((string) ($clientSecret ?: $saved['clientSecret']));
        $version = trim((string) ($version ?: $saved['version'])) ?: '2026-10';

        if ($domain === '' || $clientId === '' || $clientSecret === '') {
            return ['ok' => false, 'message' => 'Shopify store domain, Client ID and Client secret are required.'];
        }

        try {
            $token = $this->accessToken($domain, $clientId, $clientSecret);
            $data = $this->graphql($domain, $token, $version, <<<'GQL'
query ShopifyConnectionTest {
  shop {
    name
    myshopifyDomain
    currencyCode
  }
}
GQL);

            return [
                'ok' => true,
                'message' => 'Shopify connection successful.',
                'shop' => $data['shop'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncBatch(?string $cursor = null): array
    {
        $connection = $this->connection();

        // Upgrade legacy imported collection slugs such as
        // "shopify-code-1242" to Shopify's real handle "code-1242".
        $this->cleanupLegacyShopifyCollectionSlugs();

        if ($connection['domain'] === '' || $connection['clientId'] === '' || $connection['clientSecret'] === '') {
            throw new RuntimeException('Shopify connection is not configured.');
        }

        $token = $this->accessToken(
            $connection['domain'],
            $connection['clientId'],
            $connection['clientSecret']
        );

        $query = <<<'GQL'
query ShopifyProducts($cursor: String) {
  products(first: 25, after: $cursor, sortKey: ID) {
    nodes {
      id
      title
      handle
      descriptionHtml
      vendor
      productType
      status
      tags
      seo {
        title
        description
      }
      options {
        name
        values
      }
      featuredMedia {
        ... on MediaImage {
          id
          image {
            url
            altText
          }
        }
      }
      media(first: 20) {
        nodes {
          ... on MediaImage {
            id
            image {
              url
              altText
            }
          }
        }
      }
      collections(first: 20) {
        nodes {
          id
          title
          handle
        }
      }
      variants(first: 100) {
        nodes {
          id
          title
          sku
          barcode
          price
          compareAtPrice
          inventoryQuantity
          availableForSale
          selectedOptions {
            name
            value
          }
          image {
            url
            altText
          }
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
GQL;

        $data = $this->graphql(
            $connection['domain'],
            $token,
            $connection['version'],
            $query,
            ['cursor' => $cursor]
        );

        $connectionData = $data['products'] ?? [];
        $nodes = $connectionData['nodes'] ?? [];

        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0];
        $failures = [];

        foreach ($nodes as $node) {
            try {
                $created = $this->syncProduct($node);
                $stats['processed']++;
                $stats[$created ? 'created' : 'updated']++;
            } catch (\Throwable $e) {
                $stats['processed']++;
                $stats['failed']++;

                $failures[] = [
                    'shopify_product_id' => (string) ($node['id'] ?? ''),
                    'title' => (string) ($node['title'] ?? 'Unknown product'),
                    'handle' => (string) ($node['handle'] ?? ''),
                    'error' => $e->getMessage(),
                ];

                report($e);
            }
        }

        Setting::setValue('shopify_last_sync_at', now()->toDateTimeString());

        return [
            'ok' => true,
            'stats' => $stats,
            'has_next_page' => (bool) data_get($connectionData, 'pageInfo.hasNextPage', false),
            'next_cursor' => data_get($connectionData, 'pageInfo.endCursor'),
            'last_sync_at' => Setting::getValue('shopify_last_sync_at'),
            'failures' => $failures,
        ];
    }

    private function syncProduct(array $node): bool
    {
        return DB::transaction(function () use ($node) {
            $shopifyId = (string) ($node['id'] ?? '');
            if ($shopifyId === '') {
                throw new RuntimeException('Shopify product ID missing.');
            }

            $existing = Product::query()->where('shopify_product_id', $shopifyId)->first();
            $created = ! $existing;

            $variants = $node['variants']['nodes'] ?? [];
            $firstVariant = $variants[0] ?? [];

            $stock = collect($variants)->sum(fn ($v) => max(0, (int) ($v['inventoryQuantity'] ?? 0)));
            $title = Str::limit(trim((string) ($node['title'] ?? 'Shopify Product')), 255, '');
            $handle = Str::limit(trim((string) ($node['handle'] ?? '')), 255, '');
            $slugBase = $handle !== '' ? $handle : Str::slug($title);

            $collections = $node['collections']['nodes'] ?? [];
            $subcategoryId = $existing?->subcategory_id;

            // Always reconcile the Shopify primary collection. Older imports
            // already have a subcategory_id, so only checking empty IDs would
            // permanently keep legacy "shopify-*" slugs.
            if (! empty($collections)) {
                $subcategoryId = $this->syncPrimaryCollection($collections[0]);
            }

            $featuredUrl = data_get($node, 'featuredMedia.image.url');
            $descriptionHtml = (string) ($node['descriptionHtml'] ?? '');

            $payload = [
                'shopify_product_id' => $shopifyId,
                'shopify_handle' => $handle ?: null,
                'shopify_vendor' => filled($node['vendor'] ?? null) ? Str::limit((string) $node['vendor'], 255, '') : null,
                'shopify_product_type' => filled($node['productType'] ?? null) ? Str::limit((string) $node['productType'], 255, '') : null,
                'shopify_status' => $node['status'] ?? null,
                'shopify_tags' => json_encode($node['tags'] ?? [], JSON_UNESCAPED_UNICODE),
                'shopify_options' => json_encode($node['options'] ?? [], JSON_UNESCAPED_UNICODE),
                'shopify_collections' => json_encode($collections, JSON_UNESCAPED_UNICODE),
                'subcategory_id' => $subcategoryId,
                'name_bn' => $title,
                'name_en' => $title,
                'price' => (float) ($firstVariant['price'] ?? 0),
                'compare_price' => filled($firstVariant['compareAtPrice'] ?? null) ? (float) $firstVariant['compareAtPrice'] : null,
                'short_description' => Str::limit(trim(strip_tags($descriptionHtml)), 1000, ''),
                'description_html' => $descriptionHtml,
                'stock_qty' => $stock,
                'is_active' => strtoupper((string) ($node['status'] ?? 'ACTIVE')) === 'ACTIVE',
                'meta_title' => Str::limit((string) (data_get($node, 'seo.title') ?: $title), 255, ''),
                'meta_description' => data_get($node, 'seo.description') ?: Str::limit(trim(strip_tags($descriptionHtml)), 500, ''),
                'main_image_url' => $featuredUrl ?: null,
                'shopify_synced_at' => now(),
            ];

            if ($created) {
                $payload['slug'] = SlugService::unique($slugBase ?: 'shopify-product', Product::class);
                $payload['sku'] = $this->uniqueParentSku($firstVariant['sku'] ?? null, $shopifyId);
                $product = Product::create($payload);
            } else {
                $existing->update($payload);
                $product = $existing;
            }

            $seenVariantIds = [];
            foreach ($variants as $variant) {
                $variantId = (string) ($variant['id'] ?? '');
                if ($variantId === '') {
                    continue;
                }

                $seenVariantIds[] = $variantId;
                ProductVariant::query()->updateOrCreate(
                    ['shopify_variant_id' => $variantId],
                    [
                        'product_id' => $product->id,
                        'title' => Str::limit((string) ($variant['title'] ?? ''), 255, ''),
                        'sku' => ($sku = trim((string) ($variant['sku'] ?? ''))) !== '' ? Str::limit($sku, 255, '') : null,
                        'barcode' => ($barcode = trim((string) ($variant['barcode'] ?? ''))) !== '' ? Str::limit($barcode, 255, '') : null,
                        'price' => (float) ($variant['price'] ?? 0),
                        'compare_price' => filled($variant['compareAtPrice'] ?? null) ? (float) $variant['compareAtPrice'] : null,
                        'inventory_qty' => (int) ($variant['inventoryQuantity'] ?? 0),
                        'option_values' => $variant['selectedOptions'] ?? [],
                        'image_url' => data_get($variant, 'image.url'),
                        'is_available' => (bool) ($variant['availableForSale'] ?? true),
                    ]
                );
            }

            if ($seenVariantIds) {
                $product->variants()->whereNotIn('shopify_variant_id', $seenVariantIds)->delete();
            }

            $seenMediaIds = [];
            foreach (($node['media']['nodes'] ?? []) as $index => $media) {
                $mediaId = (string) ($media['id'] ?? '');
                $url = data_get($media, 'image.url');
                if ($mediaId === '' || ! $url) {
                    continue;
                }

                $seenMediaIds[] = $mediaId;
                ProductImage::query()->updateOrCreate(
                    ['shopify_media_id' => $mediaId],
                    [
                        'product_id' => $product->id,
                        'path' => '',
                        'source_url' => $url,
                        'alt_text' => Str::limit((string) (data_get($media, 'image.altText') ?: $title), 255, ''),
                        'sort_order' => $index,
                    ]
                );
            }

            if ($seenMediaIds) {
                $product->images()
                    ->whereNotNull('shopify_media_id')
                    ->whereNotIn('shopify_media_id', $seenMediaIds)
                    ->delete();
            }

            return $created;
        });
    }

    private function syncPrimaryCollection(array $collection): ?int
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'shopify-collections'],
            [
                'name_bn' => 'Shopify Collections',
                'name_en' => 'Shopify Collections',
                'icon' => '🛍️',
                'is_active' => true,
                'sort_order' => 900,
            ]
        );

        $title = Str::limit(trim((string) ($collection['title'] ?? 'Shopify Collection')), 255, '');
        $handle = trim((string) ($collection['handle'] ?? ''));

        $slug = $handle !== ''
            ? SlugService::normalizeUnicodeOrGenerate($handle, $title, 'collection')
            : SlugService::normalizeUnicodeOrGenerate(null, $title, 'collection');

        // Historical imports used Str::slug(), which transliterated Bangla/Unicode
        // Shopify handles into Latin. Keep that value only for locating old rows.
        $transliteratedSlug = Str::slug($handle !== '' ? $handle : $title) ?: 'collection';
        $legacySlug = 'shopify-'.$transliteratedSlug;

        // Prefer Shopify's exact handle. If an older imported row exists under
        // either "shopify-..." or the transliterated slug, reuse that same row
        // and upgrade its slug instead of creating a duplicate subcategory.
        $subcategory = Subcategory::query()
            ->where('category_id', $category->id)
            ->where('slug', $slug)
            ->first();

        if (! $subcategory) {
            $subcategory = Subcategory::query()
                ->where('category_id', $category->id)
                ->whereIn('slug', [$legacySlug, $transliteratedSlug])
                ->first();
        }

        // Some older imports damaged Bangla handles by stripping Unicode
        // combining marks. Those broken slugs cannot reliably be reconstructed,
        // so fall back to the Shopify collection title to identify the same row.
        if (! $subcategory) {
            $subcategory = Subcategory::query()
                ->where('category_id', $category->id)
                ->where(function ($query) use ($title) {
                    $query->where('name_bn', $title)
                        ->orWhere('name_en', $title);
                })
                ->first();
        }

        if ($subcategory) {
            $slugTakenByAnother = Subcategory::query()
                ->where('slug', $slug)
                ->where('id', '!=', $subcategory->id)
                ->exists();

            if (! $slugTakenByAnother) {
                $subcategory->slug = $slug;
            }
        }

        if (! $subcategory) {
            $subcategory = new Subcategory();
            $subcategory->category_id = $category->id;
            $subcategory->slug = $slug;
        }

        $subcategory->name_bn = $title;
        $subcategory->name_en = $title;
        $subcategory->is_active = true;
        $subcategory->sort_order = 900;
        $subcategory->save();

        return $subcategory->id;
    }

    private function cleanupLegacyShopifyCollectionSlugs(): void
    {
        $category = Category::query()->where('slug', 'shopify-collections')->first();

        if (! $category) {
            return;
        }

        Subcategory::query()
            ->where('category_id', $category->id)
            ->where('slug', 'like', 'shopify-%')
            ->orderBy('id')
            ->chunkById(100, function ($subcategories) {
                foreach ($subcategories as $subcategory) {
                    $current = (string) $subcategory->slug;

                    if (! str_starts_with($current, 'shopify-')) {
                        continue;
                    }

                    $target = SlugService::normalizeUnicodeOrGenerate(
                        substr($current, strlen('shopify-')),
                        (string) $subcategory->name_en ?: (string) $subcategory->name_bn,
                        'collection'
                    );

                    if ($target === '') {
                        continue;
                    }

                    $conflict = Subcategory::query()
                        ->where('slug', $target)
                        ->where('id', '!=', $subcategory->id)
                        ->exists();

                    if (! $conflict) {
                        $subcategory->slug = $target;
                        $subcategory->save();
                    }
                }
            });
    }

    private function uniqueParentSku(?string $sku, string $shopifyId): string
    {
        $base = trim((string) $sku);
        if ($base !== '' && ! Product::query()->where('sku', $base)->exists()) {
            return $base;
        }

        $numeric = preg_replace('/\D+/', '', $shopifyId);
        $base = 'SHOPIFY-'.($numeric ?: Str::upper(Str::random(8)));
        $candidate = $base;
        $i = 2;

        while (Product::query()->where('sku', $candidate)->exists()) {
            $candidate = $base.'-'.$i++;
        }

        return $candidate;
    }

    private function accessToken(string $domain, string $clientId, string $clientSecret): string
    {
        $response = Http::asForm()
            ->timeout(30)
            ->post("https://{$domain}/admin/oauth/access_token", [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Shopify authentication failed with HTTP '.$response->status().'.');
        }

        $token = trim((string) $response->json('access_token'));

        if ($token === '') {
            throw new RuntimeException('Shopify did not return an access token.');
        }

        return $token;
    }

    private function graphql(string $domain, string $token, string $version, string $query, array $variables = []): array
    {
        $payload = ['query' => $query];

        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        $response = Http::timeout(45)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])
            ->post("https://{$domain}/admin/api/{$version}/graphql.json", $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Shopify API returned HTTP '.$response->status().'.');
        }

        $json = $response->json();

        if (! empty($json['errors'])) {
            $message = collect($json['errors'])->pluck('message')->filter()->implode(' | ');
            throw new RuntimeException($message ?: 'Shopify GraphQL request failed.');
        }

        return $json['data'] ?? [];
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        return rtrim((string) $domain, '/');
    }
}
