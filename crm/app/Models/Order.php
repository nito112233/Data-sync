<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public const SYNC_MODE_ASYNC = 'async';
    public const SYNC_MODE_BATCH_DEMO = 'batch_demo';

    protected $fillable = [
        'external_id','customer_id','number','status','sync_mode','currency','total','issued_at',
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

    public function outboxMessages()
    {
        return $this->hasMany(OutboxMessage::class, 'aggregate_id')
            ->where('aggregate_type', OutboxMessage::AGGREGATE_TYPE_ORDER);
    }
}
