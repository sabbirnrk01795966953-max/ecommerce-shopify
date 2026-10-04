@extends('layouts.admin')

@section('title','পণ্য')
@section('page_title','পণ্য')

@section('content')
<div class="page-actions">
    <form class="searchbar" method="get" action="{{ route('admin.products.index') }}">
        <input
            type="search"
            name="q"
            value="{{ request('q') }}"
            placeholder="পণ্যের নাম, SKU, slug বা Shopify handle"
            aria-label="পণ্য খুঁজুন"
        >
        <button class="btn" type="submit">Search</button>
        @if(request('q'))
            <a class="btn outline" href="{{ route('admin.products.index') }}">Clear</a>
        @endif
    </form>

    <a class="btn primary" href="{{ route('admin.products.create') }}">+ নতুন পণ্য</a>
</div>

@if(request('q'))
    <div class="panel" style="padding:12px 16px;margin-bottom:14px">
        <strong>{{ $products->total() }}</strong> টি result পাওয়া গেছে:
        <code>{{ request('q') }}</code>
    </div>
@endif

<div class="panel table-wrap">
    <table>
        <thead>
            <tr>
                <th>ছবি</th>
                <th>পণ্য</th>
                <th>SKU</th>
                <th>দাম</th>
                <th>Last URL</th>
                <th>স্ট্যাটাস</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse($products as $p)
            <tr>
                <td>
                    @if($p->main_image_src)
                        <img class="table-img" src="{{ $p->main_image_src }}" alt="">
                    @endif
                </td>
                <td>{{ $p->name_bn ?: $p->name_en }}</td>
                <td>{{ $p->sku }}</td>
                <td>৳{{ number_format((float)$p->price,2) }}</td>
                <td>
                    <a target="_blank" href="{{ route('product.show',$p->slug) }}">
                        <code>{{ $p->slug }}</code> ↗
                    </a>
                </td>
                <td>{{ $p->is_active?'Active':'Inactive' }}</td>
                <td class="actions">
                    <a href="{{ route('admin.products.edit',$p) }}">Edit</a>
                    <form method="post" action="{{ route('admin.products.destroy',$p) }}" onsubmit="return confirm('Delete product?')">
                        @csrf
                        @method('DELETE')
                        <button>Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" style="padding:30px;text-align:center">
                    কোনো matching product পাওয়া যায়নি।
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

{{ $products->links('vendor.pagination.default') }}
@endsection
