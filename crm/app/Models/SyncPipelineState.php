<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncPipelineState extends Model
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'pipeline',
        'last_cursor_updated_at',
        'last_cursor_id',
        'last_run_started_at',
        'last_run_finished_at',
        'last_status',
        'last_error',
    ];

    protected $casts = [
        'last_cursor_updated_at' => 'datetime',
        'last_run_started_at' => 'datetime',
        'last_run_finished_at' => 'datetime',
    ];
}
