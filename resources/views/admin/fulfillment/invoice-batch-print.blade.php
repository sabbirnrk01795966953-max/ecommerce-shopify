<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $batch->batch_code }}</title>
<style>
@page{size:A4 portrait;margin:0}
*{box-sizing:border-box}
body{margin:0;background:#ececec;color:#000;font-family:Arial,"Noto Sans Bengali","SolaimanLipi",sans-serif}
.toolbar{position:sticky;top:0;z-index:20;background:#111;color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center}
.toolbar button,.toolbar a{background:#fff;color:#111;border:0;border-radius:6px;padding:9px 12px;text-decoration:none;cursor:pointer;font-weight:700}
.sheet{width:210mm;min-height:297mm;margin:8mm auto;background:#fff;padding:8mm 8mm 5mm}
.invoice-card{height:139mm;position:relative;border-top:1.8px solid #000;padding-top:7mm;page-break-inside:avoid}
.invoice-card + .invoice-card{margin-top:5mm}
.cut-line{height:5mm;border-top:1px dashed #888;position:relative}
.cut-line:before{content:"✂";position:absolute;left:0;top:-8px;background:#fff;padding-right:5px;font-size:10px}
.invoice-header{display:grid;grid-template-columns:1.6fr .9fr;gap:8mm;align-items:start}
.brand h1{font-size:21px;letter-spacing:1.2px;margin:0 0 3px;text-transform:uppercase}
.brand-line{font-size:10px;font-weight:700}
.invoice-badge-wrap{text-align:right}
.invoice-badge{display:inline-block;background:#000;color:#fff;font-size:13px;font-weight:800;padding:6px 18px;letter-spacing:.4px}
.invoice-date{font-size:10px;font-weight:700;margin-top:5px}
.info-barcode{display:grid;grid-template-columns:1.35fr .85fr;gap:8mm;margin-top:6mm;align-items:start}
.customer-table{display:grid;grid-template-columns:31mm 5mm 1fr;row-gap:3px;align-items:start;font-size:11px}
.customer-table b{font-weight:800}
.customer-table .invoice-code{font-size:17px;font-weight:900;letter-spacing:.3px}
.customer-table .phone{font-size:15px;font-weight:900}
.address-box{border:1.2px solid #000;padding:3px 6px;min-height:20px;line-height:1.25}
.barcode-box{border:1.2px dashed #000;padding:8px 10px 5px;text-align:center;position:relative;min-height:44mm}
.barcode-title{position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:#000;color:#fff;font-size:10px;font-weight:800;padding:5px 14px;white-space:nowrap}
.barcode-bars{height:24mm;display:flex;align-items:stretch;justify-content:center;gap:1px;margin-top:7mm;overflow:hidden}
.barcode-bars span{display:block;background:#000;min-width:1px}
.barcode-code{font-size:14px;font-weight:900;margin-top:2px}
.items{width:100%;border-collapse:collapse;margin-top:4mm;font-size:10px;table-layout:fixed}
.items th{background:#000;color:#fff;border:1px solid #000;padding:5px 4px;font-weight:800}
.items td{border:1px solid #000;padding:5px 5px;height:10mm;vertical-align:middle}
.items .sl{width:10mm;text-align:center}
.items .product{width:auto}
.items .price{width:25mm;text-align:center}
.items .qty{width:19mm;text-align:center}
.items .total-col{width:25mm;text-align:center}
.bottom-grid{display:grid;grid-template-columns:1.55fr .7fr;gap:5mm;margin-top:4mm}
.left-bottom{display:grid;gap:4mm}
.charge-boxes{display:grid;grid-template-columns:repeat(3,1fr);border:1px solid #000}
.charge-box{padding:6px 8px;border-right:1px solid #000;min-height:20mm}
.charge-box:last-child{border-right:0}
.charge-box span{display:block;font-size:9px;font-weight:800;text-transform:uppercase}
.charge-box b{display:block;margin-top:6px;font-size:14px}
.note-box{border:1px solid #000;min-height:20mm;padding:6px 8px;font-size:9px;line-height:1.35}
.summary{border:1px solid #000;font-size:10px}
.summary-row{display:flex;justify-content:space-between;gap:10px;padding:5px 7px;border-bottom:1px solid #000}
.summary-row strong{font-weight:800}
.summary-row.grand{background:#000;color:#fff;font-size:14px;font-weight:900}
.summary-footer{text-align:center;padding:5px 4px;font-size:8px;font-weight:700;word-break:break-all}
@media print{
  body{background:#fff}
  .toolbar{display:none}
  .sheet{margin:0;padding:8mm 8mm 5mm;page-break-after:always}
  .sheet:last-child{page-break-after:auto}
}
</style>
</head>
<body>
<div class="toolbar">
    <b>Batch: {{ $batch->batch_code }} — {{ $batch->orders->count() }} invoices</b>
    <button onclick="window.print()">Print / Save PDF</button>
    <a href="{{ route('admin.fulfillment.ready-dispatch') }}">Go to Ready to Dispatch</a>
</div>

@php
    $orders = $batch->orders->values();
@endphp

@foreach($orders->chunk(2) as $pageOrders)
<section class="sheet">
    @foreach($pageOrders as $pageIndex => $order)
        @if($pageIndex > 0)
            <div class="cut-line"></div>
        @endif

        <article class="invoice-card">
            <div class="invoice-header">
                <div class="brand">
                    <h1>{{ $siteSettings['site_name'] ?? 'Store' }}</h1>
                    <div class="brand-line">
                        {{ $siteSettings['address'] ?? '' }}
                        @if(!empty($siteSettings['address']) && !empty($siteSettings['phone'])) • @endif
                        {{ $siteSettings['phone'] ?? '' }}
                    </div>
                </div>

                <div class="invoice-badge-wrap">
                    <div class="invoice-badge">INVOICE</div>
                    <div class="invoice-date">Date: {{ $order->created_at->timezone('Asia/Dhaka')->format('d M Y') }}</div>
                </div>
            </div>

            <div class="info-barcode">
                <div class="customer-table">
                    <b>Invoice Code</b><span>:</span><span class="invoice-code">{{ $order->invoice_id }}</span>
                    <b>Name</b><span>:</span><span><strong>{{ $order->customer_name }}</strong></span>
                    <b>Phone</b><span>:</span><span class="phone">{{ $order->phone }}</span>
                    <b>Address</b><span>:</span><span class="address-box">{{ $order->address }}</span>
                </div>

                <div class="barcode-box">
                    <div class="barcode-title">INVOICE BARCODE</div>
                    <div class="barcode-bars" aria-label="Barcode {{ $order->invoice_id }}">
                        @php
                            $barcodeSource = strtoupper((string) $order->invoice_id);
                            $bars = [];
                            foreach (str_split($barcodeSource) as $char) {
                                $bits = str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
                                foreach (str_split($bits) as $bit) {
                                    $bars[] = $bit === '1' ? 3 : 1;
                                }
                                $bars[] = 1;
                            }
                        @endphp
                        @foreach($bars as $bar)
                            <span style="width:{{ $bar }}px"></span>
                        @endforeach
                    </div>
                    <div class="barcode-code">{{ $order->invoice_id }}</div>
                </div>
            </div>

            <table class="items">
                <thead>
                <tr>
                    <th class="sl">SL</th>
                    <th class="product">PRODUCT NAME</th>
                    <th class="price">PRICE</th>
                    <th class="qty">QTY</th>
                    <th class="total-col">TOTAL</th>
                </tr>
                </thead>
                <tbody>
                @foreach($order->items as $itemIndex => $item)
                    <tr>
                        <td class="sl">{{ $itemIndex + 1 }}</td>
                        <td class="product">{{ $item->sku }}-{{ $item->name }}</td>
                        <td class="price">{{ number_format((float)$item->price,0) }}</td>
                        <td class="qty">{{ $item->quantity }}</td>
                        <td class="total-col">{{ number_format((float)$item->total,0) }}</td>
                    </tr>
                @endforeach

                @for($blank = $order->items->count(); $blank < 3; $blank++)
                    <tr>
                        <td class="sl">&nbsp;</td>
                        <td class="product"></td>
                        <td class="price"></td>
                        <td class="qty"></td>
                        <td class="total-col"></td>
                    </tr>
                @endfor
                </tbody>
            </table>

            <div class="bottom-grid">
                <div class="left-bottom">
                    <div class="charge-boxes">
                        <div class="charge-box">
                            <span>Delivery Cost</span>
                            <b>৳ {{ number_format((float)$order->delivery_charge,0) }}</b>
                        </div>
                        <div class="charge-box">
                            <span>Discount</span>
                            <b>৳ {{ number_format((float)$order->discount,0) }}</b>
                        </div>
                        <div class="charge-box">
                            <span>Advance</span>
                            <b>৳ {{ number_format((float)$order->advance,0) }}</b>
                        </div>
                    </div>

                    <div class="note-box">
                        <b>Note:</b>
                        {{ $order->note ?: 'বিঃ দ্রঃ কোন কারণে প্রোডাক্ট ফেরত দিতে হলে ইন্ট্যাক অবস্থায় ব্যাক দিতে হবে, প্যাকেট খোলা যাবে না।' }}
                    </div>
                </div>

                <div class="summary">
                    <div class="summary-row"><span>Subtotal</span><strong>৳ {{ number_format((float)$order->subtotal,0) }}</strong></div>
                    <div class="summary-row"><span>Delivery Cost</span><strong>৳ {{ number_format((float)$order->delivery_charge,0) }}</strong></div>
                    <div class="summary-row"><span>Discount</span><strong>- ৳ {{ number_format((float)$order->discount,0) }}</strong></div>
                    <div class="summary-row"><span>Advance</span><strong>- ৳ {{ number_format((float)$order->advance,0) }}</strong></div>
                    <div class="summary-row grand"><span>GRAND TOTAL</span><strong>৳ {{ number_format((float)$order->total_amount,0) }}</strong></div>
                    <div class="summary-footer">
                        {{ $order->pathao_consignment_id ?: ('pathao-'.strtolower(str_replace(' ','-',$siteSettings['site_name'] ?? 'store')).'-pending') }}
                    </div>
                </div>
            </div>
        </article>
    @endforeach
</section>
@endforeach
</body>
</html>
