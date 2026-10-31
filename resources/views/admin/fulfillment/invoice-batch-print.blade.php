<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $batch->batch_code }}</title>
<style>
body{font-family:Arial,sans-serif;margin:0;background:#eee;color:#111}.toolbar{position:sticky;top:0;background:#111;color:#fff;padding:12px 18px;display:flex;gap:10px;align-items:center;z-index:5}.toolbar button,.toolbar a{background:#fff;color:#111;border:0;border-radius:6px;padding:9px 12px;text-decoration:none;cursor:pointer}.invoice{width:148mm;min-height:200mm;margin:10mm auto;background:#fff;padding:10mm;box-sizing:border-box;page-break-after:always}.invoice:last-child{page-break-after:auto}.head{display:flex;justify-content:space-between;border-bottom:2px solid #111;padding-bottom:8px}.meta{display:grid;grid-template-columns:110px 1fr;gap:5px;margin:12px 0}.items{width:100%;border-collapse:collapse}.items th,.items td{border:1px solid #ccc;padding:7px;text-align:left}.totals{margin-top:12px;margin-left:auto;width:250px}.totals div{display:flex;justify-content:space-between;padding:4px 0}.total{font-size:18px;font-weight:700;border-top:1px solid #111;margin-top:5px;padding-top:8px!important}@media print{body{background:#fff}.toolbar{display:none}.invoice{margin:0;width:auto;min-height:auto;box-shadow:none}}
</style>
</head>
<body>
<div class="toolbar">
    <b>Batch: {{ $batch->batch_code }} — {{ $batch->orders->count() }} invoices</b>
    <button onclick="window.print()">Print / Save PDF</button>
    <a href="{{ route('admin.fulfillment.ready-dispatch') }}">Go to Ready to Dispatch</a>
</div>

@foreach($batch->orders as $order)
<section class="invoice">
    <div class="head">
        <div>
            <h2 style="margin:0">{{ $siteSettings['site_name'] ?? 'Store' }}</h2>
            <small>{{ $siteSettings['phone'] ?? '' }}</small>
        </div>
        <div style="text-align:right">
            <b>INVOICE</b><br>
            <strong>{{ $order->invoice_id }}</strong>
        </div>
    </div>

    <div class="meta">
        <b>Customer</b><span>{{ $order->customer_name }}</span>
        <b>Phone</b><span>{{ $order->phone }}</span>
        <b>Address</b><span>{{ $order->address }}</span>
        <b>Date</b><span>{{ $order->created_at->timezone('Asia/Dhaka')->format('d M Y h:i A') }}</span>
    </div>

    <table class="items">
        <thead><tr><th>SKU</th><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
        <tbody>
        @foreach($order->items as $item)
            <tr>
                <td>{{ $item->sku }}</td>
                <td>{{ $item->name }}</td>
                <td>{{ $item->quantity }}</td>
                <td>৳{{ number_format((float)$item->price,2) }}</td>
                <td>৳{{ number_format((float)$item->total,2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><b>৳{{ number_format((float)$order->subtotal,2) }}</b></div>
        <div><span>Delivery</span><b>৳{{ number_format((float)$order->delivery_charge,2) }}</b></div>
        <div class="total"><span>Total COD</span><b>৳{{ number_format((float)$order->total_amount,2) }}</b></div>
    </div>
</section>
@endforeach
</body>
</html>
