<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';

    protected $fillable = [
        'period', 'status', 'counted_by', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    public function counter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'counted_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'confirmed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    public function isFrozen(): bool
    {
        return $this->status === 'confirmed';
    }
}
