<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimUnit extends Model
{
    protected $fillable = ['loss_claim_id', 'issued_unit_id'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(LossClaim::class, 'loss_claim_id');
    }

    public function issuedUnit(): BelongsTo
    {
        return $this->belongsTo(IssuedUnit::class);
    }
}
