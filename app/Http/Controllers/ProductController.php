<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;

class ProductController extends Controller
{
    public function show(string $slug)
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['images', 'subcategory.category'])
            ->firstOrFail();

        $related = Product::query()
            ->where('is_active', true)
            ->where('id', '!=', $product->id)
            ->when($product->subcategory_id, fn ($q) => $q->where('subcategory_id', $product->subcategory_id))
            ->latest()
            ->limit(4)
            ->get();

        return view('store.product', compact('product', 'related'));
    }

    public function collection(string $slug)
    {
        $subcategory = Subcategory::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with('category')
            ->first();

        if ($subcategory) {
            $products = Product::query()
                ->where('subcategory_id', $subcategory->id)
                ->where('is_active', true)
                ->latest()
                ->paginate(20);

            return view('store.subcategory', compact('subcategory', 'products'));
        }

        $category = Category::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['subcategories' => fn ($q) => $q->where('is_active', true)])
            ->firstOrFail();

        $subcategoryIds = $category->subcategories->pluck('id');

        $products = Product::query()
            ->whereIn('subcategory_id', $subcategoryIds)
            ->where('is_active', true)
            ->latest()
            ->paginate(20);

        // Reuse the existing collection page template for top-level categories too.
        $subcategory = $category;

        return view('store.subcategory', compact('subcategory', 'products'));
    }
}
