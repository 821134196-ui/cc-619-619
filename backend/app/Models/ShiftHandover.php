<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftHandover extends Model
{
    protected $fillable = [
        'window_id', 'from_staff_id', 'to_staff_id',
        'outstanding_snapshot', 'total_outstanding',
        'pending_claim_qty', 'handed_at',
    ];

    protected $casts = [
        'outstanding_snapshot' => 'array',
        'total_outstanding' => 'integer',
        'pending_claim_qty' => 'integer',
        'handed_at' => 'datetime',
    ];

    public function window(): BelongsTo
    {
        return $this->belongsTo(Window::class);
    }

    public function fromStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'from_staff_id');
    }

    public function toStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'to_staff_id');
    }
}
