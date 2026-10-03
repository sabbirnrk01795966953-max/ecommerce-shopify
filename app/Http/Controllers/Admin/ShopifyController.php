<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ShopifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class ShopifyController extends Controller
{
    public function index()
    {
        return view('admin.shopify.index', [
            'shopify' => [
                'domain' => Setting::getValue('shopify_store_domain', ''),
                'api_version' => Setting::getValue('shopify_api_version', '2026-10'),
                'token_saved' => trim((string) Setting::getValue('shopify_access_token_encrypted', '')) !== '',
                'last_sync_at' => Setting::getValue('shopify_last_sync_at', ''),
                'imported_products' => Product::query()->whereNotNull('shopify_product_id')->count(),
            ],
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'api_version' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'token' => ['nullable', 'string', 'max:5000'],
        ]);

        $domain = trim(strtolower((string) $data['domain']));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim((string) $domain, '/');

        Setting::setValue('shopify_store_domain', $domain);
        Setting::setValue('shopify_api_version', $data['api_version']);

        if (! empty($data['token'])) {
            Setting::setValue('shopify_access_token_encrypted', Crypt::encryptString(trim($data['token'])));
        }

        return response()->json([
            'ok' => true,
            'message' => 'Shopify connection settings saved.',
            'token_saved' => trim((string) Setting::getValue('shopify_access_token_encrypted', '')) !== '',
        ]);
    }

    public function test(Request $request, ShopifyService $shopify): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:255'],
            'api_version' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'token' => ['nullable', 'string', 'max:5000'],
        ]);

        $result = $shopify->testConnection(
            $data['domain'] ?? null,
            $data['token'] ?? null,
            $data['api_version'] ?? null
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function syncBatch(Request $request, ShopifyService $shopify): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            return response()->json($shopify->syncBatch($data['cursor'] ?? null));
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
