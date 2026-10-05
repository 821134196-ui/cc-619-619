<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnReceipt extends Model
{
    protected $fillable = [
        'code', 'issued_unit_id', 'line_id', 'issuance_id',
        'issue_window_id', 'receive_window_id', 'receiver_staff_id', 'received_at',
    ];

    protected $casts = ['received_at' => 'datetime'];

    public function issuedUnit(): BelongsTo
    {
        return $this->belongsTo(IssuedUnit::class);
    }

    public function issueWindow(): BelongsTo
    {
        return $this->belongsTo(Window::class, 'issue_window_id');
    }

    public function receiveWindow(): BelongsTo
    {
        return $this->belongsTo(Window::class, 'receive_window_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'receiver_staff_id');
    }
}
