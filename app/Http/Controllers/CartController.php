<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class CartController extends Controller
{
    private function analyticsEvent(array $line, int $delta): ?array
    {
        if (!$delta) return null;
        $item = ['item_id'=>(string)($line['sku'] ?: $line['product_id']),'item_name'=>$line['name'],'price'=>(float)$line['price'],'quantity'=>abs($delta)];
        return ['name'=>$delta>0?'add_to_cart':'remove_from_cart','params'=>['currency'=>'BDT','value'=>$item['price']*$item['quantity'],'items'=>[$item]]];
    }

    public function index() { return view('store.cart', ['cart'=>$this->cart()]); }

    public function add(Request $request, Product $product)
    {
        abort_unless($product->is_active, 404);
        $qty = max(1, min(99, (int) $request->input('quantity',1)));
        $cart = session('cart', []);
        $existing = $cart[$product->id]['quantity'] ?? 0;
        $cart[$product->id] = [
            'product_id'=>$product->id,'name'=>$product->name_bn ?: $product->name_en,'slug'=>$product->slug,'sku'=>$product->sku,
            'price'=>(float)$product->price,'quantity'=>min(99,$existing+$qty),'image'=>$product->main_image_path,
        ];
        session(['cart'=>$cart]);

        $analytics = $this->analyticsEvent($cart[$product->id], $cart[$product->id]['quantity']-$existing);
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'পণ্যটি কার্টে যোগ হয়েছে।',
                'cart_count' => collect($cart)->sum('quantity'),
                'ga_event' => $analytics,
            ]);
        }

        if ($analytics) session()->flash('ga_cart_event',$analytics);
        return back()->with('success','পণ্যটি কার্টে যোগ হয়েছে।');
    }

    public function update(Request $request, Product $product)
    {
        $cart = session('cart', []);
        if (isset($cart[$product->id])) {
            $oldQuantity = $cart[$product->id]['quantity'];
            $cart[$product->id]['quantity'] = max(1, min(99, (int)$request->input('quantity',1)));
            session(['cart'=>$cart]);
            $event = $this->analyticsEvent($cart[$product->id], $cart[$product->id]['quantity']-$oldQuantity);
            if ($event) session()->flash('ga_cart_event',$event);
        }
        return back();
    }

    public function remove(Product $product)
    {
        $cart = session('cart', []);
        if (isset($cart[$product->id])) {
            $event = $this->analyticsEvent($cart[$product->id], -$cart[$product->id]['quantity']);
            if ($event) session()->flash('ga_cart_event',$event);
        }
        unset($cart[$product->id]); session(['cart'=>$cart]);
        return back();
    }

    private function cart(): array { return array_values(session('cart', [])); }
}
