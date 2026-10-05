<?php

namespace App\Services;

use App\Models\Compensation;
use App\Models\Employee;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockLedger;
use App\Models\UtensilType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 月末集中盘点。
 *
 * 防重复计损的设计：
 *  1) 发放即出库、归还即入库；借出后丢失【不写数量流水】，
 *     所以丢失的外借餐具不会出现在月末账面里，也就不可能再被盘亏一次。
 *  2) 库内破损在登记当时已写出库流水，月末实物自然与账面一致。
 *  3) 只有"账上在库、实物缺失"才是真盘亏：确认时一条 adjust_out 流水
 *     + 一张赔付单，二者通过 stock_count_line_id 互相引用且唯一。
 *  4) 主管确认后整单冻结，重复确认/修改一律被拒绝（或幂等回放）。
 */
class StockCountService
{
    public function __construct(private StockLedgerService $ledger)
    {
    }

    /** 创建某月盘点草稿（系统数=数量流水账面数），每月唯一 */
    public function createDraft(string $period, Employee $counter, array $physicalMap = []): StockCount
    {
        return DB::transaction(function () use ($period, $counter, $physicalMap) {
            $existing = StockCount::where('period', $period)->first();
            if ($existing) {
                throw new \DomainException("{$period} 盘点单已存在（状态：{$existing->status}）");
            }

            $count = StockCount::create([
                'period' => $period,
                'status' => StockCount::STATUS_DRAFT,
                'counted_by' => $counter->id,
            ]);

            foreach (UtensilType::orderBy('id')->get() as $type) {
                $systemQty = $this->ledger->balance($type->id);
                $physical = $physicalMap[$type->id] ?? $physicalMap[$type->code] ?? $systemQty;

                StockCountLine::create([
                    'stock_count_id' => $count->id,
                    'utensil_type_id' => $type->id,
                    'system_qty' => $systemQty,
                    'physical_qty' => $physical,
                    'variance' => $physical - $systemQty,
                    'disposition' => StockCountLine::DISP_PENDING,
                ]);
            }

            return $count->load('lines');
        });
    }

    /** 录入实物数量（仅草稿可改） */
    public function setPhysical(StockCountLine $line, int $physical): StockCountLine
    {
        $count = $line->stockCount;
        if ($count->isFrozen()) {
            throw new \DomainException('盘点单已冻结，不能修改实物数量');
        }

        $line->update([
            'physical_qty' => $physical,
            'variance' => $physical - $line->system_qty,
        ]);

        return $line->fresh();
    }

    /**
     * 主管确认盘点差异并冻结。幂等：已冻结再次确认，原样返回、不再产生任何流水/赔付。
     */
    public function confirm(StockCount $count, Employee $supervisor): StockCount
    {
        if (! $supervisor->isSupervisor()) {
            throw new \DomainException('只有主管可以确认盘点');
        }

        // 冻结后重复操作：直接回放，不改任何数据
        if ($count->isFrozen()) {
            return $count->load(['lines', 'confirmedBy']);
        }

        return DB::transaction(function () use ($count, $supervisor) {
            $count = StockCount::with('lines')->where('id', $count->id)->lockForUpdate()->first();
            if ($count->isFrozen()) {
                return $count->load(['lines', 'confirmedBy']);
            }

            foreach ($count->lines as $line) {
                $line->load('utensilType');
                $type = $line->utensilType;

                if ($line->variance === 0) {
                    // 账实相符（含已登记破损/外借丢失早已离账的情形）
                    $line->update(['disposition' => StockCountLine::DISP_RECONCILED]);
                    continue;
                }

                if ($line->variance > 0) {
                    // 盘盈：补入库存
                    $this->ledger->append(
                        $type,
                        StockLedgerService::TYPE_ADJUST_IN,
                        $line->variance,
                        'stock_count_line',
                        $line->id,
                        $count->period,
                    );
                    $line->update(['disposition' => StockCountLine::DISP_ADJUSTED]);
                    continue;
                }

                // 盘亏：出一条数量调整 + 建一张应赔单，二者同指此行，不会重复计损
                $shortage = -$line->variance;

                if (Compensation::where('stock_count_line_id', $line->id)->exists()) {
                    throw new \DomainException('该行盘亏已生成赔付，禁止重复处理');
                }

                $comp = Compensation::create([
                    'comp_no' => 'CP' . now()->format('YmdHis') . strtoupper(Str::random(4)),
                    'kind' => Compensation::KIND_LOST,
                    'utensil_type_id' => $type->id,
                    'qty' => $shortage,
                    'unit_amount' => $type->unit_price,
                    'total_amount' => $type->unit_price * $shortage,
                    'window_id' => $this->firstWindowId(),
                    'reported_by' => $supervisor->id,
                    'stock_count_line_id' => $line->id,
                    'payment_status' => Compensation::PAY_UNPAID,
                    'period' => $count->period,
                    'note' => "月末盘点盘亏（{$count->period}）",
                    'reported_at' => now(),
                ]);

                $this->ledger->append(
                    $type,
                    StockLedgerService::TYPE_ADJUST_OUT,
                    -$shortage,
                    'stock_count_line',
                    $line->id,
                    $count->period,
                );

                $line->update(['disposition' => StockCountLine::DISP_ADJUSTED]);
            }

            $count->update([
                'status' => StockCount::STATUS_CONFIRMED,
                'confirmed_by' => $supervisor->id,
                'confirmed_at' => now(),
            ]);

            return $count->fresh()->load(['lines', 'confirmedBy']);
        });
    }

    /**
     * 赔付 × 盘点 核对报告：证明同一损失只计了一次。
     * - issued_lost：外借丢失赔付（发放时已出库，不影响盘点账面）
     * - damaged：库内破损赔付（登记时已出库）
     * - stock_shortage：盘点盘亏赔付（确认时出库，调整流水与赔付一一对应）
     */
    public function reconciliation(string $period): array
    {
        $comps = Compensation::where('period', $period)->with('utensilType', 'issuanceItem', 'stockCountLine')->get();

        $byKind = [
            Compensation::KIND_DAMAGED => [],
            'issued_lost' => [],
            'stock_shortage' => [],
        ];

        foreach ($comps as $comp) {
            $entry = [
                'comp_no' => $comp->comp_no,
                'utensil' => $comp->utensilType->name,
                'qty' => $comp->qty,
                'amount' => $comp->total_amount,
                'payment_status' => $comp->payment_status,
            ];

            if ($comp->kind === Compensation::KIND_DAMAGED) {
                $byKind[Compensation::KIND_DAMAGED][] = $entry + ['stock_effect' => 'adjust_out 已在登记时出库'];
            } elseif ($comp->stock_count_line_id) {
                $byKind['stock_shortage'][] = $entry + [
                    'stock_count_line_id' => $comp->stock_count_line_id,
                    'stock_effect' => 'adjust_out 与盘点行一一对应',
                ];
            } else {
                $byKind['issued_lost'][] = $entry + [
                    'issuance_item_id' => $comp->issuance_item_id,
                    'stock_effect' => '发放时已出库，赔付不写数量流水',
                ];
            }
        }

        $count = StockCount::where('period', $period)->with('lines')->first();

        return [
            'period' => $period,
            'stock_count_status' => $count?->status,
            'compensations' => $byKind,
            'totals' => [
                'comp_qty' => $comps->sum('qty'),
                'comp_amount_paid' => $comps->where('payment_status', Compensation::PAY_PAID)->sum('total_amount'),
                'comp_amount_unpaid' => $comps->where('payment_status', Compensation::PAY_UNPAID)->sum('total_amount'),
                'physical_shortage_qty' => $count?->lines->where('variance', '<', 0)->sum(fn ($l) => -$l->variance) ?? 0,
                'physical_gain_qty' => $count?->lines->where('variance', '>', 0)->sum('variance') ?? 0,
            ],
            'no_double_count' => $this->assertNoDoubleCount($period),
        ];
    }

    /** 硬性核对：每张赔付单最多挂一个损失来源；每个盘点行最多一张赔付单；每个外借明细最多一张赔付单 */
    public function assertNoDoubleCount(string $period): bool
    {
        $comps = Compensation::where('period', $period)->get();

        if ($comps->contains(fn ($c) => $c->issuance_item_id && $c->stock_count_line_id)) {
            return false; // 同一损失不能同时挂外借明细与盘点行
        }
        if ($comps->whereNotNull('stock_count_line_id')->groupBy('stock_count_line_id')->contains(fn ($g) => $g->count() > 1)) {
            return false;
        }
        if ($comps->whereNotNull('issuance_item_id')->groupBy('issuance_item_id')->contains(fn ($g) => $g->count() > 1)) {
            return false;
        }

        return true;
    }

    private function firstWindowId(): int
    {
        return (int) \App\Models\Window::orderBy('id')->value('id');
    }
}
