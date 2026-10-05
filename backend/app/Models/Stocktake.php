<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stocktake extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_FROZEN = 'frozen';

    protected $fillable = [
        'period', 'status', 'created_by', 'submitted_by', 'frozen_by',
        'submitted_at', 'frozen_at', 'summary',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'frozen_at' => 'datetime',
        'summary' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    public function freezer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'frozen_by');
    }

    public function isFrozen(): bool
    {
        return $this->status === self::STATUS_FROZEN;
    }
}
