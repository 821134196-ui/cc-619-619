<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IssuanceItem extends Model
{
    public const STATUS_OUT = 'out';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_LOST = 'lost';

    protected $fillable = ['issuance_id', 'return_token', 'status'];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class);
    }

    public function returnRecord(): HasOne
    {
        return $this->hasOne(Returns::class);
    }

    public function compensation(): HasOne
    {
        return $this->hasOne(Compensation::class);
    }
}
