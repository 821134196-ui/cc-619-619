<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockCountLine extends Model
{
    public const DISP_PENDING = 'pending';
    public const DISP_ADJUSTED = 'adjusted';
    public const DISP_RECONCILED = 'reconciled';

    protected $fillable = [
        'stock_count_id', 'utensil_type_id', 'system_qty', 'physical_qty',
        'variance', 'disposition',
    ];

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function utensilType(): BelongsTo
    {
        return $this->belongsTo(UtensilType::class);
    }

    public function compensation(): HasOne
    {
        return $this->hasOne(Compensation::class);
    }
}
