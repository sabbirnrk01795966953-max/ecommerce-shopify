<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvoiceBatch;
use App\Models\Order;
use App\Services\PathaoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FulfillmentController extends Controller
{
    public function readyInvoices(Request $request)
    {
        $query = Order::query()
            ->where('status', 'CONFIRMED')
            ->whereNull('invoice_batch_id');

        $this->applyDateRange($query, $request);

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($q) use ($term) {
                $q->where('invoice_id', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%");
            });
        }

        return view('admin.fulfillment.ready-invoices', [
            'orders' => $query->latest()->paginate(50)->withQueryString(),
        ]);
    }

    public function createInvoiceBatch(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
        ], [
            'order_ids.required' => 'কমপক্ষে একটি অর্ডার সিলেক্ট করুন।',
        ]);

        $batch = DB::transaction(function () use ($validated) {
            $orders = Order::query()
                ->whereIn('id', $validated['order_ids'])
                ->where('status', 'CONFIRMED')
                ->whereNull('invoice_batch_id')
                ->lockForUpdate()
                ->get();

            abort_if($orders->isEmpty(), 422, 'কোনো eligible confirmed order পাওয়া যায়নি।');

            $batch = InvoiceBatch::query()->create([
                'batch_code' => 'IB-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(4)),
                'order_count' => $orders->count(),
            ]);

            Order::query()
                ->whereIn('id', $orders->pluck('id'))
                ->update([
                    'invoice_batch_id' => $batch->id,
                    'updated_at' => now(),
                ]);

            return $batch;
        });

        return redirect()->route('admin.fulfillment.invoice-batches.print', $batch);
    }

    public function printInvoiceBatch(InvoiceBatch $batch)
    {
        $batch->load(['orders.items']);

        return view('admin.fulfillment.invoice-batch-print', compact('batch'));
    }

    public function readyDispatch(Request $request)
    {
        $query = Order::query()
            ->with('invoiceBatch')
            ->whereNotNull('invoice_batch_id')
            ->whereNull('pathao_consignment_id')
            ->whereNotIn('status', ['CANCELLED', 'STOCKOUT', 'DELIVERED', 'RETURNED']);

        $this->applyDateRange($query, $request);

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($q) use ($term) {
                $q->where('invoice_id', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%");
            });
        }

        return view('admin.fulfillment.ready-dispatch', [
            'orders' => $query->latest()->paginate(50)->withQueryString(),
        ]);
    }

    public function markException(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', 'in:CANCELLED,STOCKOUT'],
            'invoice_codes' => ['nullable', 'string', 'max:20000'],
            'csv' => ['nullable', 'file', 'mimes:csv,txt', 'max:4096'],
        ]);

        $codes = collect();

        if (! empty($validated['invoice_codes'])) {
            $codes = $codes->merge(
                preg_split('/[\s,;]+/u', trim($validated['invoice_codes'])) ?: []
            );
        }

        if ($request->hasFile('csv')) {
            $handle = fopen($request->file('csv')->getRealPath(), 'rb');

            if ($handle) {
                while (($row = fgetcsv($handle)) !== false) {
                    if (isset($row[0]) && trim((string) $row[0]) !== '') {
                        $codes->push(trim((string) $row[0]));
                    }
                }
                fclose($handle);
            }
        }

        $codes = $codes
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return back()->withErrors(['invoice_codes' => 'Invoice code অথবা CSV দিন।']);
        }

        $orders = Order::query()
            ->whereIn('invoice_id', $codes)
            ->whereNull('pathao_consignment_id')
            ->get();

        foreach ($orders as $order) {
            $order->update(['status' => $validated['action']]);
        }

        $missing = $codes->diff($orders->pluck('invoice_id'));

        $message = $orders->count().'টি order '.$validated['action'].' করা হয়েছে।';
        if ($missing->isNotEmpty()) {
            $message .= ' পাওয়া যায়নি/আগেই dispatch হয়েছে: '.$missing->take(10)->implode(', ');
        }

        return back()->with('success', $message);
    }

    public function dispatch(Request $request, PathaoService $pathao)
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
        ]);

        $orders = Order::query()
            ->with('items')
            ->whereIn('id', $validated['order_ids'])
            ->whereNotNull('invoice_batch_id')
            ->whereNull('pathao_consignment_id')
            ->whereNotIn('status', ['CANCELLED', 'STOCKOUT', 'DELIVERED', 'RETURNED'])
            ->orderBy('id')
            ->get();

        $success = 0;
        $failures = [];

        foreach ($orders as $order) {
            try {
                $pathao->dispatch($order);
                $success++;
            } catch (\Throwable $e) {
                $order->update([
                    'pathao_status' => 'FAILED',
                    'pathao_response' => mb_substr($e->getMessage(), 0, 5000),
                ]);
                $failures[] = $order->invoice_id.': '.$e->getMessage();
            }
        }

        $message = $success.'টি order Pathao-তে dispatch হয়েছে।';

        if ($failures !== []) {
            return back()
                ->with('success', $message)
                ->withErrors(['pathao' => implode(' | ', array_slice($failures, 0, 5))]);
        }

        return back()->with('success', $message);
    }

    private function applyDateRange($query, Request $request): void
    {
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }
    }
}
