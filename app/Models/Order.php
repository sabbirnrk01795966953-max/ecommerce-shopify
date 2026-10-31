<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'invoice_id','external_order_id','customer_name','phone','address','shipping_phone','shipping_customer_name',
        'shipping_address1','shipping_address2','shipping_city','shipping_province','shipping_zip','shipping_country','email',
        'delivery_charge','discount','advance','subtotal','total_amount','note','status','oms_status','oms_response','purchase_event_id',
        'order_ip_hash','phone_normalized','invoice_batch_id','pathao_consignment_id','pathao_status','pathao_response','dispatched_at'
    ];
    protected function casts(): array { return ['delivery_charge'=>'decimal:2','discount'=>'decimal:2','advance'=>'decimal:2','subtotal'=>'decimal:2','total_amount'=>'decimal:2','dispatched_at'=>'datetime']; }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function invoiceBatch(): BelongsTo { return $this->belongsTo(InvoiceBatch::class); }
}
