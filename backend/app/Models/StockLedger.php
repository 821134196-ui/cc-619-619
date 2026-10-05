<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLedger extends Model
{
    protected $table = 'stock_ledger';

    protected $fillable = [
        'utensil_type_id', 'type', 'qty_delta', 'balance_after',
        'ref_type', 'ref_id', 'period', 'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function utensilType(): BelongsTo
    {
        return $this->belongsTo(UtensilType::class);
    }
}
