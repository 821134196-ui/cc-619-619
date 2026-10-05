<?php

namespace App\Services;

use App\Models\StockLedger;
use App\Models\UtensilType;
use Illuminate\Support\Facades\DB;

/**
 * 库存数量流水：系统中唯一改变餐具数量的入口。
 * 赔付收款永远不经过这里，保证"钱"与"数量"两条线。
 */
class StockLedgerService
{
    public const TYPE_INITIAL = 'initial';
    public const TYPE_ISSUE = 'issue';
    public const TYPE_RETURN = 'return';
    public const TYPE_ADJUST_IN = 'adjust_in';
    public const TYPE_ADJUST_OUT = 'adjust_out';

    public function append(
        UtensilType $type,
        string $typeName,
        int $delta,
        ?string $refType = null,
        ?int $refId = null,
        ?string $period = null,
        ?\DateTimeInterface $occurredAt = null,
    ): StockLedger {
        return DB::transaction(function () use ($type, $typeName, $delta, $refType, $refId, $period, $occurredAt) {
            $last = StockLedger::where('utensil_type_id', $type->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $balance = ($last->balance_after ?? 0) + $delta;

            return StockLedger::create([
                'utensil_type_id' => $type->id,
                'type' => $typeName,
                'qty_delta' => $delta,
                'balance_after' => $balance,
                'ref_type' => $refType,
                'ref_id' => $refId,
                'period' => $period,
                'occurred_at' => $occurredAt ?? now(),
            ]);
        });
    }

    public function balance(int $utensilTypeId): int
    {
        return (int) StockLedger::where('utensil_type_id', $utensilTypeId)->sum('qty_delta');
    }
}
