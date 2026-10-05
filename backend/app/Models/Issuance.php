<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Issuance extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'issuance_no', 'utensil_type_id', 'window_id', 'employee_id', 'shift_id',
        'qty', 'returned_qty', 'lost_qty', 'status', 'issued_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    public function utensilType(): BelongsTo
    {
        return $this->belongsTo(UtensilType::class);
    }

    public function window(): BelongsTo
    {
        return $this->belongsTo(Window::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(IssuanceItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(Returns::class);
    }

    /** 尚未归还（未还 = 发放 - 已还 - 已报失） */
    public function outstandingQty(): int
    {
        return $this->qty - $this->returned_qty - $this->lost_qty;
    }
}
