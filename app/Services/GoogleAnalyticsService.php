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
}
