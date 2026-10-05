<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IssuedUnit extends Model
{
    public const STATUS_OUT = 'out';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_LOST = 'lost';

    protected $fillable = ['line_id', 'utensil_type_id', 'code', 'status'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(IssuanceLine::class, 'line_id');
    }

    public function utensilType(): BelongsTo
    {
        return $this->belongsTo(UtensilType::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(ReturnReceipt::class);
    }
}
