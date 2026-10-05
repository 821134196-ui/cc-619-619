<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'utensil_type_id', 'type', 'delta', 'balance_after',
        'window_id', 'ref_type', 'ref_id', 'period', 'note',
        'staff_id', 'occurred_at',
    ];

    protected $casts = [
        'delta' => 'integer',
        'balance_after' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function utensilType(): BelongsTo
    {
        return $this->belongsTo(UtensilType::class);
    }

    public function window(): BelongsTo
    {
        return $this->belongsTo(Window::class);
    }
}
