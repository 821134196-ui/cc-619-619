<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IssuanceLine extends Model
{
    protected $fillable = ['issuance_id', 'utensil_type_id', 'qty'];

    protected $casts = ['qty' => 'integer'];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class);
    }

    public function utensilType(): BelongsTo
    {
        return $this->belongsTo(UtensilType::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(IssuedUnit::class, 'line_id');
    }
}
