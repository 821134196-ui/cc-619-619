<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'loss_claim_id', 'amount_cents', 'gateway_ref', 'gateway', 'paid_at', 'collected_by',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(LossClaim::class, 'loss_claim_id');
    }
}
