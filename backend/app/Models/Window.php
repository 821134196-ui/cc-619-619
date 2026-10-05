<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Window extends Model
{
    protected $fillable = ['code', 'name'];

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(Issuance::class);
    }
}
