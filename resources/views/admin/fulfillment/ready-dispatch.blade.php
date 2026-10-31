@extends('layouts.admin')
@section('title','Ready to Dispatch')
@section('page_title','Ready to Dispatch')

@section('content')
<form class="panel filter-bar order-filter" method="get">
    <input type="date" name="from" value="{{ request('from') }}">
    <input type="date" name="to" value="{{ request('to') }}">
    <input name="q" value="{{ request('q') }}" placeholder="Invoice / phone / customer">
    <button class="btn" type="submit">Filter</button>
    <a class="btn outline" href="{{ route('admin.fulfillment.ready-dispatch') }}">Reset</a>
</form>

<div class="panel two-col">
    <form method="post" action="{{ route('admin.fulfillment.mark-exception') }}">
        @csrf
        <h3>Cancel / Stockout by Invoice</h3>
        <label>Action
            <select name="action" required>
                <option value="CANCELLED">Cancel</option>
                <option value="STOCKOUT">Stockout</option>
            </select>
        </label>
        <label>Invoice code
            <textarea name="invoice_codes" rows="5" placeholder="GS26-2422&#10;GS26-2423"></textarea>
        </label>
        <button class="btn danger" type="submit">Apply</button>
    </form>

    <form method="post" action="{{ route('admin.fulfillment.mark-exception') }}" enctype="multipart/form-data">
        @csrf
        <h3>Cancel / Stockout by CSV</h3>
        <label>Action
            <select name="action" required>
                <option value="CANCELLED">Cancel</option>
                <option value="STOCKOUT">Stockout</option>
            </select>
        </label>
        <label>CSV — Column A = Invoice Code
            <input type="file" name="csv" accept=".csv,.txt" required>
        </label>
        <button class="btn danger" type="submit">Upload CSV</button>
    </form>
</div>

<form method="post" action="{{ route('admin.fulfillment.dispatch') }}" id="dispatchForm">
    @csrf
    <div class="panel order-bulk-bar">
        <div class="bulk-selection-info">
            <strong>Pathao Dispatch</strong>
            <span><b id="selectedOrderCount">0</b>টি order selected</span>
        </div>
        <button class="btn primary" id="dispatchSelected" type="submit" disabled>Dispatch Selected to Pathao</button>
    </div>

    <div class="panel table-wrap">
        <table class="order-table">
            <thead>
            <tr>
                <th class="select-col"><input id="selectAllOrders" type="checkbox"></th>
                <th>Batch</th><th>Invoice</th><th>Customer</th><th>Phone</th><th>Total</th><th>Status</th>
            </tr>
            </thead>
            <tbody>
            @forelse($orders as $order)
                <tr>
                    <td class="select-col"><input class="order-row-checkbox" type="checkbox" name="order_ids[]" value="{{ $order->id }}"></td>
                    <td>{{ $order->invoiceBatch?->batch_code }}</td>
                    <td><b>{{ $order->invoice_id }}</b></td>
                    <td>{{ $order->customer_name }}</td>
                    <td>{{ $order->phone }}</td>
                    <td>৳{{ number_format((float)$order->total_amount,2) }}</td>
                    <td>{{ $order->status }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty-table-message">Dispatch-এর জন্য কোনো order অপেক্ষায় নেই।</td></tr>
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
    const button = document.getElementById('dispatchSelected');
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
    document.getElementById('dispatchForm')?.addEventListener('submit', e => {
        const checked = boxes.filter(b => b.checked).length;
        if (!checked || !confirm(checked + 'টি order Pathao-তে dispatch করবেন?')) e.preventDefault();
    });
    sync();
})();
</script>
@endpush
