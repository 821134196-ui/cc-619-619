<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $fillable = [
        'shift_no', 'window_id', 'opened_by', 'opened_at',
        'closed_by', 'closed_at', 'handover_snapshot', 'status',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'handover_snapshot' => 'array',
    ];

    public function window(): BelongsTo
    {
        return $this->belongsTo(Window::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'closed_by');
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(Issuance::class);
    }
}
