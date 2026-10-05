<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UtensilType extends Model
{
    protected $fillable = ['code', 'name', 'initial_stock', 'unit_price'];

    public function issuances(): HasMany
    {
        return $this->hasMany(Issuance::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }

    /** 当前账面数量 = 全部数量流水之和（含期初 initial 行） */
    public function currentBalance(): int
    {
        return (int) StockLedger::where('utensil_type_id', $this->id)->sum('qty_delta');
    }
}
