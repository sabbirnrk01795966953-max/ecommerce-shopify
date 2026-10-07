<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\MetaCapiService;
use App\Services\OmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CheckoutController extends Controller
{
    public function show()
    {
        $cart = array_values(session('cart', []));
        if (! count($cart)) return redirect()->route('shop')->with('error','আপনার কার্ট খালি।');
        return view('store.checkout', compact('cart'));
    }

    public function quick(Request $request)
    {
        $cart = array_values(session('cart', []));

        if (! count($cart)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'আপনার কার্ট খালি।'], 422);
            }

            return response('আপনার কার্ট খালি।', 422);
        }

        return view('store.partials.checkout-popup', compact('cart'));
    }

    public function store(Request $request, OmsService $oms, MetaCapiService $meta)
    {
        $validated = $request->validate([
            'customer_name'=>'required|string|max:120','phone'=>'required|string|max:30','address'=>'required|string|max:1000',
            'shipping_area'=>'required|in:inside,outside','email'=>'nullable|email|max:190','note'=>'nullable|string|max:1000','event_id'=>'nullable|string|max:120',
        ]);
        $cart = array_values(session('cart', []));
        if (! count($cart)) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['cart' => 'কার্ট খালি।']);
            }
            return back()->withErrors(['cart'=>'কার্ট খালি।']);
        }

        $normalizedPhone = $this->normalizePhone($validated['phone']);
        $ipHash = hash('sha256', (string) $request->ip());

        $ipBlocked = Order::query()
            ->where('order_ip_hash', $ipHash)
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();

        if ($ipBlocked) {
            return $this->blockedOrderResponse(
                $request,
                'এই ইন্টারনেট সংযোগ/IP থেকে গত ২৪ ঘণ্টার মধ্যে একটি অর্ডার করা হয়েছে। নতুন অর্ডারের জন্য আমাদের সাথে WhatsApp বা ফোনে যোগাযোগ করুন।'
            );
        }

        $inside = (float) Setting::getValue('shipping_inside_dhaka', '60');
        $outside = (float) Setting::getValue('shipping_outside_dhaka', '120');
        $delivery = $validated['shipping_area'] === 'inside' ? $inside : $outside;
        $products = Product::query()->whereIn('id', collect($cart)->pluck('product_id'))->get()->keyBy('id');
        $subtotal = 0;
        foreach ($cart as $line) {
            $p = $products->get($line['product_id']);
            if (! $p || ! $p->is_active) {
                if ($request->expectsJson()) {
                    throw ValidationException::withMessages(['cart' => 'কার্টের একটি পণ্য বর্তমানে পাওয়া যাচ্ছে না।']);
                }
                return back()->withErrors(['cart'=>'কার্টের একটি পণ্য বর্তমানে পাওয়া যাচ্ছে না।']);
            }
            $subtotal += (float)$p->price * (int)$line['quantity'];
        }
        $recentSameProduct = Order::query()
            ->where('phone_normalized', $normalizedPhone)
            ->where('created_at', '>=', now()->subHours(48))
            ->whereHas('items', function ($query) use ($cart) {
                $query->whereIn('product_id', collect($cart)->pluck('product_id')->filter()->all());
            })
            ->with(['items' => function ($query) use ($cart) {
                $query->whereIn('product_id', collect($cart)->pluck('product_id')->filter()->all());
            }])
            ->latest('id')
            ->first();

        if ($recentSameProduct) {
            $matchedNames = $recentSameProduct->items
                ->pluck('name')
                ->filter()
                ->unique()
                ->take(3)
                ->implode(', ');

            return $this->blockedOrderResponse(
                $request,
                'এই ফোন নম্বর দিয়ে গত ৪৮ ঘণ্টার মধ্যে একই পণ্যের অর্ডার করা হয়েছে'
                .($matchedNames !== '' ? ': '.$matchedNames : '')
                .'। পুনরায় অর্ডারের জন্য আমাদের সাথে WhatsApp বা ফোনে যোগাযোগ করুন।'
            );
        }

        $purchaseEventId = $validated['event_id'] ?: 'purchase_'.Str::uuid();

        $order = DB::transaction(function () use ($validated,$cart,$products,$subtotal,$delivery,$purchaseEventId,$normalizedPhone,$ipHash) {
            $invoice = $this->nextInvoiceCode();

            $order = Order::create([
                'invoice_id'=>$invoice,
                'external_order_id'=>$invoice,
                'customer_name'=>$validated['customer_name'],'phone'=>$validated['phone'],'phone_normalized'=>$normalizedPhone,
                'address'=>$validated['address'],'shipping_phone'=>$validated['phone'],'shipping_customer_name'=>$validated['customer_name'],
                'shipping_address1'=>$validated['address'],'shipping_address2'=>'','shipping_city'=>'','shipping_province'=>'','shipping_zip'=>'',
                'shipping_country'=>'Bangladesh','email'=>$validated['email']??null,'delivery_charge'=>$delivery,'discount'=>0,'advance'=>0,
                'subtotal'=>$subtotal,'total_amount'=>$subtotal+$delivery,'note'=>$validated['note']??null,'status'=>'PENDING','oms_status'=>'PENDING','purchase_event_id'=>$purchaseEventId,
                'order_ip_hash'=>$ipHash,
            ]);

            foreach ($cart as $line) {
                $p=$products->get($line['product_id']); $qty=(int)$line['quantity'];
                $order->items()->create(['product_id'=>$p->id,'sku'=>$p->sku,'name'=>$p->name_bn ?: $p->name_en,'quantity'=>$qty,'price'=>$p->price,'total'=>(float)$p->price*$qty]);
            }
            return $order;
        });

        $oms->send($order);
        $meta->send('Purchase', $purchaseEventId, [
            'currency'=>'BDT','value'=>(float)$order->total_amount,'order_id'=>$order->invoice_id,
            'content_type'=>'product','contents'=>$order->items()->get()->map(fn($i)=>['id'=>$i->sku,'quantity'=>(int)$i->quantity,'item_price'=>(float)$i->price])->all(),
        ], $request, route('order.success',$order->invoice_id));

        session()->forget('cart');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'invoice_id' => $order->invoice_id,
                'redirect' => route('order.success', $order->invoice_id),
            ]);
        }

        return redirect()->route('order.success',$order->invoice_id);
    }

    private function nextInvoiceCode(): string
    {
        $prefix = strtoupper(trim((string) Setting::getValue('invoice_prefix', 'TWN')));
        $prefix = preg_replace('/[^A-Z0-9-]+/', '', $prefix) ?: 'TWN';

        // Keep an independent counter for every prefix. If a store changes its
        // prefix and later changes back, the old sequence continues safely.
        $sequenceKey = 'invoice_sequence_'.strtolower(str_replace('-', '_', $prefix));

        Setting::query()->firstOrCreate(
            ['key' => $sequenceKey],
            ['value' => '0']
        );

        $sequenceSetting = Setting::query()
            ->where('key', $sequenceKey)
            ->lockForUpdate()
            ->firstOrFail();

        $next = ((int) $sequenceSetting->value) + 1;

        if ($next > 99999999) {
            throw new RuntimeException('Invoice sequence range is exhausted for prefix '.$prefix.'.');
        }

        $sequenceSetting->value = (string) $next;
        $sequenceSetting->save();

        $digits = max(4, strlen((string) $next));

        return $prefix.'-'.str_pad((string) $next, $digits, '0', STR_PAD_LEFT);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '880') && strlen($digits) >= 13) {
            $digits = '0'.substr($digits, 3);
        } elseif (str_starts_with($digits, '88') && strlen($digits) >= 13) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    private function blockedOrderResponse(Request $request, string $message)
    {
        $contact = trim((string) Setting::getValue('phone', ''));
        if ($contact === '') {
            $contact = trim((string) Setting::getValue('help_line', ''));
        }

        $whatsappDigits = preg_replace('/\D+/', '', $contact) ?? '';
        if (str_starts_with($whatsappDigits, '0')) {
            $whatsappDigits = '88'.$whatsappDigits;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'order_blocked' => true,
                'message' => $message,
                'contact_phone' => $contact,
                'whatsapp_url' => $whatsappDigits !== ''
                    ? 'https://wa.me/'.$whatsappDigits.'?text='.rawurlencode('আমি ওয়েবসাইটে অর্ডার করতে চাচ্ছি। সাহায্য করুন।')
                    : null,
                'call_url' => $contact !== ''
                    ? 'tel:'.preg_replace('/[^0-9+]/', '', $contact)
                    : null,
            ], 429);
        }

        return back()
            ->withInput()
            ->withErrors(['checkout' => $message]);
    }

    public function success(string $invoiceId)
    {
        $order = Order::query()->where('invoice_id',$invoiceId)->with('items')->firstOrFail();
        return view('store.success', compact('order'));
    }
}
