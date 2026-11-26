<?php
namespace App\Services;

use App\Models\Order;

class GoogleAnalyticsService
{
    // Match browser normalization without modifying the catalog.
    private static function analyticsText(string $value): string
    {
        return mb_substr($value, 0, 100, 'UTF-8');
    }

    private static function analyticsId(string $value): string
    {
        if (mb_strlen($value, 'UTF-8') <= 100) return $value;
        return mb_substr($value, 0, 64, 'UTF-8').'-'.substr(hash('sha256', $value), 0, 32);
    }

    public static function purchase(Order $order): array
    {
        return [
            'transaction_id' => (string) $order->invoice_id,
            'currency' => 'BDT',
            'value' => (float) $order->subtotal,
            'shipping' => (float) $order->delivery_charge,
            'items' => $order->items()->get()->map(fn ($item) => [
                'item_id' => self::analyticsId((string) ($item->sku ?: $item->product_id)),
                'item_name' => self::analyticsText((string) $item->name),
                'price' => (float) $item->price,
                'quantity' => (int) $item->quantity,
            ])->all(),
        ];
    }
    public static function sendPurchase(Order $order, \Illuminate\Http\Request $request): bool
    {
        try {
            if (!\App\Models\Setting::isEnabled('ga_enabled') || !\App\Models\Setting::isEnabled('ga_server_enabled') || $request->input('ga_consent') !== 'yes') return false;
            $id = (string) \App\Models\Setting::getValue('ga_measurement_id');
            $client = (string) $request->input('ga_client_id');
            if (!preg_match('/^G-[A-Z0-9]+$/D', $id) || !preg_match('/^\d{1,20}\.\d{1,20}$/D', $client)) {
                logger()->info('GA4 server purchase skipped: missing or invalid identifiers', ['order_id'=>$order->id]);
                return false;
            }
            $secret = \Illuminate\Support\Facades\Crypt::decryptString((string) \App\Models\Setting::getValue('ga_api_secret'));
            $params = self::purchase($order) + ['engagement_time_msec'=>1];
            $session = (string) $request->input('ga_session_id');
            if (preg_match('/^\d{1,16}$/D', $session)) $params['session_id'] = (int) $session;
            if (\App\Models\Setting::isEnabled('ga_debug')) $params['debug_mode']=1;
            $query = http_build_query(['measurement_id'=>$id,'api_secret'=>$secret]);
            $payload = ['client_id'=>$client,'events'=>[['name'=>'purchase','params'=>$params]]];
            if (\App\Models\Setting::isEnabled('ga_debug')) {
                $validation = \Illuminate\Support\Facades\Http::connectTimeout(2)->timeout(4)->asJson()->post('https://www.google-analytics.com/debug/mp/collect?'.$query, $payload + ['validation_behavior'=>'ENFORCE_RECOMMENDATIONS']);
                $messages = $validation->json('validationMessages', []);
                logger()->info('GA4 purchase validation', ['order_id'=>$order->id,'http_status'=>$validation->status(),'validation_codes'=>array_column(is_array($messages)?$messages:[], 'validationCode'),'field_paths'=>array_map(fn($m)=>preg_replace('/[^a-zA-Z0-9_.\[\]]/', '', substr((string)($m['fieldPath'] ?? ''),0,200)), is_array($messages)?$messages:[])]);
                if (!$validation->successful() || !is_array($messages) || count($messages)) return false;
            }
            $response = \Illuminate\Support\Facades\Http::connectTimeout(2)->timeout(4)->asJson()->post('https://www.google-analytics.com/mp/collect?'.$query, $payload);
            logger()->info('GA4 purchase HTTP response', ['order_id'=>$order->id,'http_status'=>$response->status()]);
            return $response->successful();
        } catch (\Throwable $e) {
            logger()->warning('GA4 purchase delivery failed', ['order_id'=>$order->id,'exception'=>get_class($e)]);
            return false;
        }
    }
}
