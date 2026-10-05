<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UtensilType extends Model
{
    protected $fillable = ['code', 'name', 'unit_price_cents', 'initial_qty', 'active'];

    protected $casts = [
        'unit_price_cents' => 'integer',
        'initial_qty' => 'integer',
        'active' => 'boolean',
    ];
}
