<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LossClaim extends Model
{
    public const SOURCE_BROKEN_STOCK = 'broken_stock';
    public const SOURCE_LOST_ISSUED = 'lost_issued';
    public const SOURCE_STOCKTAKE_SHORTAGE = 'stocktake_shortage';

    protected $fillable = [
        'claim_no', 'source', 'status', 'utensil_type_id', 'qty',
        'unit_price_cents', 'amount_cents', 'window_id', 'staff_id',
        'reason', 'stocktake_id', 'payment_ref', 'paid_at',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price_cents' => 'integer',
        'amount_cents' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function utensilType(): BelongsTo
    {
        return $this->belongsTo(UtensilType::class);
    }

    public function window(): BelongsTo
    {
        return $this->belongsTo(Window::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ClaimUnit::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}
