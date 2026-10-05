<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Staff extends Model
{
    protected $fillable = ['employee_no', 'name', 'role', 'current_window_id'];

    public function window(): BelongsTo
    {
        return $this->belongsTo(Window::class, 'current_window_id');
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }
}
