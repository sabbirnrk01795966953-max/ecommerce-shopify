@extends('layouts.store')

@section('title', ($siteSettings['site_name'] ?? 'Trendy Deal BD').' - '.($siteSettings['site_subtitle'] ?? ''))

@section('content')

<section class="hero-wrap container">
    <div
        class="hero"
        @if(!empty($siteSettings['hero_image_path']))
            style="
                background-image:url('{{ asset('storage/'.$siteSettings['hero_image_path']) }}');
                background-size:cover;
                background-position:center;
                background-repeat:no-repeat;
            "
        @endif
    >
        <div class="hero-copy">
            <span class="eyebrow">
                {{ $siteSettings['site_subtitle'] ?? 'স্মার্ট শপিং' }}
            </span>

            <h1>
                {{ $siteSettings['hero_title'] ?? 'সেরা ডিল, দ্রুত ডেলিভারি' }}
            </h1>

            <p>
                {{ $siteSettings['hero_subtitle'] ?? '' }}
            </p>

            <a
                class="btn primary"
                href="{{ $siteSettings['hero_button_url'] ?? '/shop' }}"
            >
                {{ $siteSettings['hero_button_text'] ?? 'এখনই শপ করুন' }}
            </a>
        </div>
    </div>
</section>


<section class="container storefront-search-section">
    <form action="{{ route('shop') }}" method="get" class="storefront-search-form modern-search-form" role="search">
        <div class="storefront-search-box modern-search-box">
            <span class="storefront-search-icon modern-search-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="21" height="21" fill="none" aria-hidden="true">
                    <path d="M21 21l-4.35-4.35m1.1-5.15a6.25 6.25 0 1 1-12.5 0 6.25 6.25 0 0 1 12.5 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </span>
            <input
                type="search"
                name="q"
                value=""
                placeholder="পণ্যের নাম বা SKU দিয়ে খুঁজুন"
                aria-label="পণ্য খুঁজুন"
                class="modern-search-input"
            >
            <button type="submit" class="modern-search-btn">খুঁজুন</button>
        </div>
        <small class="modern-search-help">Product name অথবা SKU লিখে search করুন</small>
    </form>
</section>

<section class="container section">
    <h2 class="section-title">ক্যাটাগরি অনুযায়ী শপ করুন</h2>

    <div class="category-grid">
        @foreach($categories as $category)
            <div class="category-card">
                <div class="category-icon">
                    {{ $category->icon ?: '🛍️' }}
                </div>

                <b><a href="{{ route('collection.show', $category->slug) }}">{{ $category->name_bn }}</a></b>

                <div class="category-links">
                    @foreach($category->subcategories->take(3) as $sub)
                        <a href="{{ route('collection.show', $sub->slug) }}">
                            {{ $sub->name_bn }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</section>

<section class="container section">
    <div class="section-row">
        <h2 class="section-title">আমাদের পণ্য</h2>

        <a href="{{ route('shop') }}">
            সব দেখুন →
        </a>
    </div>

    <div class="product-grid">
        @forelse($products as $product)
            @include('store.partials.product-card', ['product' => $product])
        @empty
            <p>এখনও কোনো পণ্য যোগ করা হয়নি।</p>
        @endforelse
    </div>
</section>

@endsection

