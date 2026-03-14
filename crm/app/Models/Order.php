<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'external_id','customer_id','number','currency','total','issued_at',
        'synced_at','erp_reference',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'synced_at' => 'datetime',
        'total' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}