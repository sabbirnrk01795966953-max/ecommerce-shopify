<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'subcategory_id','name_bn','name_en','slug','sku','price','compare_price','short_description','description_html',
        'stock_qty','is_active','is_featured','sort_order','meta_title','meta_description','main_image_path','main_image_url',
        'video_path','video_url','shopify_product_id','shopify_handle','shopify_vendor','shopify_product_type','shopify_status',
        'shopify_tags','shopify_options','shopify_collections','shopify_synced_at'
    ];

    protected function casts(): array
    {
        return [
            'price'=>'decimal:2',
            'compare_price'=>'decimal:2',
            'stock_qty'=>'integer',
            'is_active'=>'boolean',
            'is_featured'=>'boolean',
            'sort_order'=>'integer',
            'shopify_tags'=>'array',
            'shopify_options'=>'array',
            'shopify_collections'=>'array',
            'shopify_synced_at'=>'datetime',
        ];
    }

    public function subcategory(): BelongsTo { return $this->belongsTo(Subcategory::class); }
    public function images(): HasMany { return $this->hasMany(ProductImage::class)->orderBy('sort_order'); }
    public function variants(): HasMany { return $this->hasMany(ProductVariant::class)->orderBy('id'); }

    public function getMainImageSrcAttribute(): ?string
    {
        if ($this->main_image_url) {
            return $this->main_image_url;
        }

        return $this->main_image_path ? asset('storage/'.$this->main_image_path) : null;
    }
}
