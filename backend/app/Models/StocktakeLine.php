<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeLine extends Model
{
    protected $fillable = [
        'stocktake_id', 'utensil_type_id', 'opening_qty', 'issued_qty',
        'returned_qty', 'writeoff_qty', 'expected_qty', 'counted_qty',
        'adjustment_qty', 'reconciliation',
    ];

    protected $casts = [
        'opening_qty' => 'integer',
        'issued_qty' => 'integer',
        'returned_qty' => 'integer',
        'writeoff_qty' => 'integer',
        'expected_qty' => 'integer',
        'counted_qty' => 'integer',
        'adjustment_qty' => 'integer',
        'reconciliation' => 'array',
    ];

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function utensilType(): BelongsTo
    {
        return $this->belongsTo(UtensilType::class);
    }
}
