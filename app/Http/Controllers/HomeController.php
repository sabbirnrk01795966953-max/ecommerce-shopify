<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->with(['subcategories' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get();

        $products = Product::query()
            ->where('is_active', true)
            ->with('subcategory')
            ->orderByDesc('is_featured')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('store.home', compact('categories', 'products'));
    }

    public function shop(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->where('is_active', true)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(function ($productQuery) use ($like) {
                    $productQuery
                        ->where('sku', 'like', $like)
                        ->orWhere('name_bn', 'like', $like)
                        ->orWhere('name_en', 'like', $like);
                });
            })
            ->with('subcategory')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $categories = Category::query()
            ->where('is_active', true)
            ->with(['subcategories' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get();

        return view('store.shop', compact('products', 'categories', 'search'));
    }
}
