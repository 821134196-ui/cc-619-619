<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Compensation extends Model
{
    public const KIND_LOST = 'lost';
    public const KIND_DAMAGED = 'damaged';
    public const PAY_UNPAID = 'unpaid';
    public const PAY_PAID = 'paid';

    protected $table = 'compensations';

    protected $fillable = [
        'comp_no', 'kind', 'utensil_type_id', 'qty', 'unit_amount', 'total_amount',
        'window_id', 'reported_by', 'issuance_item_id', 'stock_count_line_id',
        'payment_status', 'payment_ref', 'paid_at', 'period', 'note', 'reported_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'reported_at' => 'datetime',
    ];

    public function utensilType(): BelongsTo
    {
        return $this->belongsTo(UtensilType::class);
    }

    public function window(): BelongsTo
    {
        return $this->belongsTo(Window::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reported_by');
    }

    public function issuanceItem(): BelongsTo
    {
        return $this->belongsTo(IssuanceItem::class);
    }

    public function stockCountLine(): BelongsTo
    {
        return $this->belongsTo(StockCountLine::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
