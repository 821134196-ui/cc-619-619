<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Issuance extends Model
{
    protected $fillable = ['issue_no', 'window_id', 'staff_id', 'issued_at', 'note'];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    public function window(): BelongsTo
    {
        return $this->belongsTo(Window::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IssuanceLine::class);
    }
}
