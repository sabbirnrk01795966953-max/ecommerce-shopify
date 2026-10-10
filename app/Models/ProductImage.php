<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = ['product_id','shopify_media_id','oms_media_id','oms_media_checksum','path','source_url','alt_text','sort_order'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getSrcAttribute(): ?string
    {
        if ($this->source_url) {
            return $this->source_url;
        }

        return $this->path ? asset('storage/'.$this->path) : null;
    }
}
