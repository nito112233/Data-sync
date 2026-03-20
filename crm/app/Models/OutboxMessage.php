<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    public const AGGREGATE_TYPE_ORDER = 'order';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'status',
        'attempts',
        'last_error',
        'available_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'aggregate_id');
    }
}
