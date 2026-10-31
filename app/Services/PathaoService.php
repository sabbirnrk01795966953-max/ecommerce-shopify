<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PathaoService
{
    public function configured(): bool
    {
        return trim((string) Setting::getValue('pathao_client_id', '')) !== ''
            && trim((string) Setting::getValue('pathao_client_secret', '')) !== ''
            && trim((string) Setting::getValue('pathao_username', '')) !== ''
            && trim((string) Setting::getValue('pathao_password', '')) !== ''
            && trim((string) Setting::getValue('pathao_store_id', '')) !== '';
    }

    public function testConnection(): array
    {
        try {
            $token = $this->accessToken();
            $response = $this->client($token)->get($this->baseUrl().'/aladdin/api/v1/stores');

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'message' => $response->successful()
                    ? 'Pathao connection successful.'
                    : 'Pathao connection failed.',
                'response' => mb_substr($response->body(), 0, 3000),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'message' => $e->getMessage(),
                'response' => '',
            ];
        }
    }

    public function dispatch(Order $order): array
    {
        if ($order->pathao_consignment_id) {
            return [
                'ok' => true,
                'existing' => true,
                'consignment_id' => $order->pathao_consignment_id,
                'status' => $order->pathao_status,
            ];
        }

        $order->loadMissing('items');

        $payload = [
            'store_id' => (int) Setting::getValue('pathao_store_id'),
            'merchant_order_id' => $order->invoice_id,
            'recipient_name' => $order->shipping_customer_name ?: $order->customer_name,
            'recipient_phone' => $order->shipping_phone ?: $order->phone,
            'recipient_address' => $order->shipping_address1 ?: $order->address,
            'delivery_type' => (int) Setting::getValue('pathao_delivery_type', '48'),
            'item_type' => (int) Setting::getValue('pathao_item_type', '2'),
            'special_instruction' => $order->note ?: '',
            'item_quantity' => max(1, (int) $order->items->sum('quantity')),
            'item_weight' => (float) Setting::getValue('pathao_item_weight', '0.5'),
            'amount_to_collect' => (int) round((float) $order->total_amount),
            'item_description' => $order->items
                ->map(fn ($item) => $item->sku.' x'.$item->quantity)
                ->implode(', '),
        ];

        $token = $this->accessToken();
        $response = $this->client($token)
            ->post($this->baseUrl().'/aladdin/api/v1/orders', $payload);

        $json = $response->json();
        $consignmentId = data_get($json, 'data.consignment_id');
        $orderStatus = data_get($json, 'data.order_status');

        if (! $response->successful() || ! $consignmentId) {
            throw new RuntimeException(
                'Pathao rejected '.$order->invoice_id.': '.mb_substr($response->body(), 0, 1200)
            );
        }

        $order->update([
            'pathao_consignment_id' => (string) $consignmentId,
            'pathao_status' => (string) ($orderStatus ?: 'Pending'),
            'pathao_response' => mb_substr($response->body(), 0, 5000),
            'dispatched_at' => now(),
            'status' => 'SHIPPED',
        ]);

        return [
            'ok' => true,
            'existing' => false,
            'consignment_id' => (string) $consignmentId,
            'status' => (string) ($orderStatus ?: 'Pending'),
        ];
    }

    public function info(Order $order): array
    {
        if (! $order->pathao_consignment_id) {
            throw new RuntimeException('No Pathao consignment ID is saved for this order.');
        }

        $token = $this->accessToken();
        $response = $this->client($token)
            ->get($this->baseUrl().'/aladdin/api/v1/orders/'.$order->pathao_consignment_id.'/info');

        if (! $response->successful()) {
            throw new RuntimeException('Could not fetch Pathao order information.');
        }

        $status = (string) (data_get($response->json(), 'data.order_status_slug')
            ?: data_get($response->json(), 'data.order_status')
            ?: $order->pathao_status);

        $order->update([
            'pathao_status' => $status,
            'pathao_response' => mb_substr($response->body(), 0, 5000),
        ]);

        return $response->json() ?: [];
    }

    public function cancel(Order $order): array
    {
        if (! $order->pathao_consignment_id) {
            throw new RuntimeException('No Pathao consignment ID is saved for this order.');
        }

        $token = $this->accessToken();
        $response = $this->client($token)
            ->post($this->baseUrl().'/aladdin/api/v1/orders/'.$order->pathao_consignment_id.'/cancel');

        if (! $response->successful()) {
            throw new RuntimeException('Pathao cancel failed: '.mb_substr($response->body(), 0, 1000));
        }

        $order->update([
            'pathao_status' => 'Cancelled',
            'pathao_response' => mb_substr($response->body(), 0, 5000),
        ]);

        return $response->json() ?: [];
    }

    private function accessToken(): string
    {
        $clientId = trim((string) Setting::getValue('pathao_client_id', ''));
        $clientSecret = trim((string) Setting::getValue('pathao_client_secret', ''));
        $username = trim((string) Setting::getValue('pathao_username', ''));
        $password = trim((string) Setting::getValue('pathao_password', ''));

        if ($clientId === '' || $clientSecret === '' || $username === '' || $password === '') {
            throw new RuntimeException('Pathao credentials are incomplete in Website Settings.');
        }

        $key = 'pathao-token:'.hash('sha256', $this->baseUrl().'|'.$clientId.'|'.$username);

        return Cache::remember($key, now()->addHours(100), function () use ($clientId, $clientSecret, $username, $password) {
            $response = Http::asJson()
                ->acceptJson()
                ->connectTimeout(15)
                ->timeout(30)
                ->post($this->baseUrl().'/aladdin/api/v1/issue-token', [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'password',
                    'username' => $username,
                    'password' => $password,
                ]);

            $token = data_get($response->json(), 'access_token');

            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new RuntimeException('Pathao authentication failed: '.mb_substr($response->body(), 0, 1000));
            }

            return $token;
        });
    }

    private function client(string $token)
    {
        return Http::asJson()
            ->acceptJson()
            ->withToken($token)
            ->connectTimeout(15)
            ->timeout(45)
            ->retry(2, 500);
    }

    private function baseUrl(): string
    {
        $configured = trim((string) Setting::getValue('pathao_base_url', ''));

        return rtrim($configured !== '' ? $configured : 'https://api-hermes.pathao.com', '/');
    }
}
