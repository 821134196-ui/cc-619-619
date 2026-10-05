<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Window extends Model
{
    protected $fillable = ['code', 'name'];

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }
}
