<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceBatch extends Model
{
    protected $fillable = ['batch_code', 'order_count'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->orderBy('id');
    }
}
