<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Returns extends Model
{
    protected $table = 'returns';

    protected $fillable = [
        'issuance_item_id', 'issuance_id', 'utensil_type_id',
        'return_window_id', 'employee_id', 'cross_window', 'returned_at',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(IssuanceItem::class);
    }

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class);
    }

    public function utensilType(): BelongsTo
    {
        return $this->belongsTo(UtensilType::class);
    }

    public function returnWindow(): BelongsTo
    {
        return $this->belongsTo(Window::class, 'return_window_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
