<?php
namespace App\Services;

use App\Models\Order;

class GoogleAnalyticsService
{
    public static function purchase(Order $order): array
    {
        return [
            'transaction_id' => (string) $order->invoice_id,
            'currency' => 'BDT',
            'value' => (float) $order->subtotal,
            'shipping' => (float) $order->delivery_charge,
            'items' => $order->items()->get()->map(fn ($item) => [
                'item_id' => (string) ($item->sku ?: $item->product_id),
                'item_name' => (string) $item->name,
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
            if (!preg_match('/^G-[A-Z0-9]+$/D', $id) || !preg_match('/^\d{1,20}\.\d{1,20}$/D', $client)) return false;
            $secret = \Illuminate\Support\Facades\Crypt::decryptString((string) \App\Models\Setting::getValue('ga_api_secret'));
            $params = self::purchase($order) + ['engagement_time_msec'=>1];
            $session = (string) $request->input('ga_session_id');
            if (preg_match('/^\d{1,16}$/D', $session)) $params['session_id'] = (int) $session;
            if (\App\Models\Setting::isEnabled('ga_debug')) $params['debug_mode']=1;
            return \Illuminate\Support\Facades\Http::connectTimeout(2)->timeout(4)->asJson()->post('https://www.google-analytics.com/mp/collect?'.http_build_query(['measurement_id'=>$id,'api_secret'=>$secret]), ['client_id'=>$client,'events'=>[['name'=>'purchase','params'=>$params]]])->successful();
        } catch (\Throwable $e) {
            logger()->warning('GA4 purchase delivery failed', ['order_id'=>$order->id]);
            return false;
        }
    }
}
