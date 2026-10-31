@extends('layouts.admin')
@section('title','Ready to Download Invoice')
@section('page_title','Ready to Download Invoice')

@section('content')
<form class="panel filter-bar order-filter" method="get">
    <input type="date" name="from" value="{{ request('from') }}">
    <input type="date" name="to" value="{{ request('to') }}">
    <input name="q" value="{{ request('q') }}" placeholder="Invoice / phone / customer">
    <button class="btn" type="submit">Filter</button>
    <a class="btn outline" href="{{ route('admin.fulfillment.ready-invoices') }}">Reset</a>
</form>

<form method="post" action="{{ route('admin.fulfillment.invoice-batches.store') }}" id="invoiceBatchForm">
    @csrf

    <div class="panel order-bulk-bar">
        <div class="bulk-selection-info">
            <strong>Invoice Batch</strong>
            <span><b id="selectedOrderCount">0</b>টি order selected</span>
        </div>
        <button class="btn primary" id="createInvoiceBatch" type="submit" disabled>
            Download / Create Invoice Batch
        </button>
    </div>

    <div class="panel table-wrap">
        <table class="order-table">
            <thead>
            <tr>
                <th class="select-col"><input id="selectAllOrders" type="checkbox"></th>
                <th>Date</th><th>Invoice</th><th>Customer</th><th>Phone</th><th>Total</th>
            </tr>
            </thead>
            <tbody>
            @forelse($orders as $order)
                <tr>
                    <td class="select-col"><input class="order-row-checkbox" type="checkbox" name="order_ids[]" value="{{ $order->id }}"></td>
                    <td>{{ $order->created_at->timezone('Asia/Dhaka')->format('d M Y h:i A') }}</td>
                    <td><b>{{ $order->invoice_id }}</b></td>
                    <td>{{ $order->customer_name }}</td>
                    <td>{{ $order->phone }}</td>
                    <td>৳{{ number_format((float)$order->total_amount,2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty-table-message">Confirmed কিন্তু unbatched order নেই।</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</form>

{{ $orders->links('vendor.pagination.default') }}
@endsection

@push('scripts')
<script>
(() => {
    const all = document.getElementById('selectAllOrders');
    const boxes = [...document.querySelectorAll('.order-row-checkbox')];
    const count = document.getElementById('selectedOrderCount');
    const button = document.getElementById('createInvoiceBatch');
    const sync = () => {
        const checked = boxes.filter(b => b.checked).length;
        count.textContent = checked;
        button.disabled = checked === 0;
        if (all) {
            all.checked = boxes.length > 0 && checked === boxes.length;
            all.indeterminate = checked > 0 && checked < boxes.length;
        }
    };
    all?.addEventListener('change', () => { boxes.forEach(b => b.checked = all.checked); sync(); });
    boxes.forEach(b => b.addEventListener('change', sync));
    sync();
})();
</script>
@endpush
