<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'compensation_id', 'amount', 'channel', 'status',
        'payment_ref', 'gateway_payload', 'paid_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'gateway_payload' => 'array',
    ];

    public function compensation(): BelongsTo
    {
        return $this->belongsTo(Compensation::class);
    }
}
