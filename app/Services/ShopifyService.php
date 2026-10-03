<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
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

        foreach ($nodes as $node) {
            try {
                $created = $this->syncProduct($node);
                $stats['processed']++;
                $stats[$created ? 'created' : 'updated']++;
            } catch (\Throwable) {
                $stats['processed']++;
                $stats['failed']++;
            }
        }

        Setting::setValue('shopify_last_sync_at', now()->toDateTimeString());

        return [
            'ok' => true,
            'stats' => $stats,
            'has_next_page' => (bool) data_get($connectionData, 'pageInfo.hasNextPage', false),
            'next_cursor' => data_get($connectionData, 'pageInfo.endCursor'),
            'last_sync_at' => Setting::getValue('shopify_last_sync_at'),
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
            $title = trim((string) ($node['title'] ?? 'Shopify Product'));
            $handle = trim((string) ($node['handle'] ?? ''));
            $slugBase = $handle !== '' ? $handle : Str::slug($title);

            $collections = $node['collections']['nodes'] ?? [];
            $subcategoryId = $existing?->subcategory_id;

            if (! $subcategoryId && ! empty($collections)) {
                $subcategoryId = $this->syncPrimaryCollection($collections[0]);
            }

            $featuredUrl = data_get($node, 'featuredMedia.image.url');
            $descriptionHtml = (string) ($node['descriptionHtml'] ?? '');

            $payload = [
                'shopify_product_id' => $shopifyId,
                'shopify_handle' => $handle ?: null,
                'shopify_vendor' => $node['vendor'] ?? null,
                'shopify_product_type' => $node['productType'] ?? null,
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
                'meta_title' => data_get($node, 'seo.title') ?: $title,
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
                $product->variants()->updateOrCreate(
                    ['shopify_variant_id' => $variantId],
                    [
                        'title' => $variant['title'] ?? null,
                        'sku' => trim((string) ($variant['sku'] ?? '')) ?: null,
                        'barcode' => trim((string) ($variant['barcode'] ?? '')) ?: null,
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
                $product->images()->updateOrCreate(
                    ['shopify_media_id' => $mediaId],
                    [
                        'path' => '',
                        'source_url' => $url,
                        'alt_text' => data_get($media, 'image.altText') ?: $title,
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

        $title = trim((string) ($collection['title'] ?? 'Shopify Collection'));
        $handle = trim((string) ($collection['handle'] ?? '')) ?: Str::slug($title);
        $slug = 'shopify-'.(Str::slug($handle) ?: 'collection');

        $subcategory = Subcategory::query()->updateOrCreate(
            ['category_id' => $category->id, 'slug' => $slug],
            [
                'name_bn' => $title,
                'name_en' => $title,
                'is_active' => true,
                'sort_order' => 900,
            ]
        );

        return $subcategory->id;
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
