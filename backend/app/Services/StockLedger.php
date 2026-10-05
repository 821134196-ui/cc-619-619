<?php

namespace App\Services;

use App\Models\StockMovement;
use App\Models\UtensilType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 餐具数量流水（只追加）。
 * 期末实物 = 期初 + Σ(issue/return/writeoff/adjust)。
 * 赔款不产生任何流水。
 */
class StockLedger
{
    public function record(
        UtensilType $type,
        string $moveType,
        int $delta,
        array $attrs = [],
    ): StockMovement {
        return DB::transaction(function () use ($type, $moveType, $delta, $attrs) {
            $last = StockMovement::where('utensil_type_id', $type->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $balance = ($last?->balance_after ?? 0) + $delta;
            if ($balance < 0) {
                throw new \App\Support\BusinessRuleException(
                    "库存不足：{$type->name}当前实物 {$last?->balance_after}，本次变动 {$delta} 后为负"
                );
            }

            $when = isset($attrs['occurred_at'])
                ? CarbonImmutable::parse($attrs['occurred_at'])
                : CarbonImmutable::now();

            return StockMovement::create([
                'utensil_type_id' => $type->id,
                'type' => $moveType,
                'delta' => $delta,
                'balance_after' => $balance,
                'window_id' => $attrs['window_id'] ?? null,
                'ref_type' => $attrs['ref_type'] ?? null,
                'ref_id' => $attrs['ref_id'] ?? null,
                'period' => $when->format('Y-m'),
                'note' => $attrs['note'] ?? null,
                'staff_id' => $attrs['staff_id'] ?? null,
                'occurred_at' => $when,
            ]);
        });
    }

    public function currentBalance(int $utensilTypeId): int
    {
        return (int) StockMovement::where('utensil_type_id', $utensilTypeId)
            ->orderByDesc('id')->value('balance_after') ?? 0;
    }

    /** 某期期初实物（=上一期末 balance_after） */
    public function openingQty(int $utensilTypeId, string $period): int
    {
        $start = CarbonImmutable::createFromFormat('Y-m', $period)->startOfMonth();
        $m = StockMovement::where('utensil_type_id', $utensilTypeId)
            ->where('occurred_at', '<', $start)
            ->orderByDesc('id')->first();
        return $m?->balance_after ?? 0;
    }

    /**
     * 某期汇总：期初 + 发放/归还/破损注销/盘点调整 = 账面应有实物
     */
    public function periodSummary(int $utensilTypeId, string $period): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m', $period)->startOfMonth();
        $end = $start->endOfMonth();

        $rows = StockMovement::where('utensil_type_id', $utensilTypeId)
            ->whereBetween('occurred_at', [$start, $end])
            ->get(['type', 'delta', 'balance_after']);

        $sum = fn (string $t) => (int) $rows->where('type', $t)->sum('delta');

        $openingBefore = $this->openingQty($utensilTypeId, $period);
        $openingInPeriod = $sum('opening'); // 系统在本月建账时，期初流水落在月内
        $issued = $sum('issue');      // 负数
        $returned = $sum('return');   // 正数
        $writeoff = $sum('writeoff'); // 负数
        $adjust = $sum('adjust');     // 盘亏为负、盘盈为正

        // 期初列 = 月前余额 + 月内建账期初
        $opening = $openingBefore + $openingInPeriod;
        $expected = $opening + $issued + $returned + $writeoff + $adjust;

        return compact('opening', 'issued', 'returned', 'writeoff', 'adjust', 'expected');
    }
}
