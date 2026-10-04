@extends('layouts.store')

@section('title', ($search ?? '') !== '' ? 'Search: '.$search.' - '.($siteSettings['site_name'] ?? 'Trendy Deal BD') : 'শপ - '.($siteSettings['site_name'] ?? 'Trendy Deal BD'))

@section('content')
<div class="breadcrumb">
    <div class="container">
        <a href="{{ route('home') }}">হোম</a> <span>›</span> শপ
    </div>
</div>

<div class="container shop-layout">
    <aside class="category-sidebar">
        <h3>ক্যাটাগরি</h3>
        @foreach($categories as $cat)
            <b><a href="{{ route('collection.show',$cat->slug) }}">{{ $cat->name_bn }}</a></b>
            @foreach($cat->subcategories as $sub)
                <a href="{{ route('collection.show',$sub->slug) }}">{{ $sub->name_bn }}</a>
            @endforeach
        @endforeach
    </aside>

    <section class="shop-results">
        <div class="shop-toolbar">
            <div class="shop-result-count">
                <strong>{{ $products->total() }}</strong> টি পণ্য
                @if(($search ?? '') !== '')
                    <span>— “{{ $search }}” এর ফলাফল</span>
                @endif
            </div>

            <form action="{{ route('shop') }}" method="get" class="storefront-search-form shop-search-form" role="search">
                <div class="storefront-search-box">
                    <span class="storefront-search-icon" aria-hidden="true">⌕</span>
                    <input
                        type="search"
                        name="q"
                        value="{{ $search ?? '' }}"
                        placeholder="পণ্যের নাম বা SKU দিয়ে খুঁজুন"
                        aria-label="পণ্য খুঁজুন"
                    >
                    @if(($search ?? '') !== '')
                        <a href="{{ route('shop') }}" class="storefront-search-clear" aria-label="Search clear">×</a>
                    @endif
                    <button type="submit" class="btn primary">খুঁজুন</button>
                </div>
            </form>
        </div>

        @if($products->count())
            <div class="product-grid">
                @foreach($products as $product)
                    @include('store.partials.product-card',['product'=>$product])
                @endforeach
            </div>

            {{ $products->links('vendor.pagination.default') }}
        @else
            <div class="empty-state shop-search-empty">
                <h2>কোনো পণ্য পাওয়া যায়নি</h2>
                <p>অন্য নাম অথবা SKU দিয়ে আবার খুঁজুন।</p>
                <a class="btn primary" href="{{ route('shop') }}">সব পণ্য দেখুন</a>
            </div>
        @endif
    </section>
</div>
@endsection
