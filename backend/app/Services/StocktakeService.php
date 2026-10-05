<?php

namespace App\Services;

use App\Models\IssuedUnit;
use App\Models\LossClaim;
use App\Models\Staff;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\UtensilType;
use App\Support\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class StocktakeService
{
    public function __construct(
        private StockLedger $ledger,
        private ClaimService $claims,
    ) {
    }

    /** 开账（每月一次）：按数量流水生成账面应有数 */
    public function open(string $period, Staff $creator): Stocktake
    {
        $this->assertPeriodFormat($period);

        $existing = Stocktake::where('period', $period)->first();
        if ($existing) {
            throw new BusinessRuleException("{$period} 盘点单已存在（状态：{$existing->status}）");
        }

        return DB::transaction(function () use ($period, $creator) {
            $stocktake = Stocktake::create([
                'period' => $period,
                'status' => Stocktake::STATUS_OPEN,
                'created_by' => $creator->id,
            ]);

            foreach (UtensilType::where('active', true)->get() as $type) {
                $s = $this->ledger->periodSummary($type->id, $period);
                $stocktake->lines()->create([
                    'utensil_type_id' => $type->id,
                    'opening_qty' => $s['opening'],
                    'issued_qty' => abs($s['issued']),
                    'returned_qty' => $s['returned'],
                    'writeoff_qty' => abs($s['writeoff']),
                    'expected_qty' => $s['expected'],
                    'counted_qty' => null,
                    'adjustment_qty' => 0,
                ]);
            }

            return $stocktake->load('lines.utensilType');
        });
    }

    /** 录入实盘数（仅 open 状态可改，冻结后拒绝） */
    public function recordCount(Stocktake $stocktake, UtensilType $type, int $countedQty): StocktakeLine
    {
        $this->assertOpen($stocktake);
        if ($countedQty < 0) {
            throw new BusinessRuleException('实盘数量不能为负');
        }

        $line = StocktakeLine::where('stocktake_id', $stocktake->id)
            ->where('utensil_type_id', $type->id)->firstOrFail();

        $line->update([
            'counted_qty' => $countedQty,
            'adjustment_qty' => $countedQty - $line->expected_qty,
        ]);

        return $line->fresh();
    }

    /** 员工提交盘点（须全部录入实盘），提交后等待主管确认 */
    public function submit(Stocktake $stocktake, Staff $staff): Stocktake
    {
        $this->assertOpen($stocktake);

        $lines = $stocktake->lines;
        if ($lines->contains(fn ($l) => $l->counted_qty === null)) {
            throw new BusinessRuleException('仍有餐具种类未录入实盘数，不能提交');
        }

        $stocktake->update([
            'submitted_by' => $staff->id,
            'submitted_at' => CarbonImmutable::now(),
        ]);

        return $stocktake->fresh();
    }

    /**
     * 主管确认冻结（核心）：
     *  - 仅 supervisor 可操作；仅 open 可冻结 —— 重复确认直接抛错，
     *    不会第二次写 adjust 流水或赔付单（"冻结盘点不会被重复操作改变"）。
     *  - 行锁 + 事务保证"盘点调整"与"盘亏赔付"一次性落库，二者引用同一盘点单，
     *    同一损失只计一次：adjust 走物线，shortage claim 走钱线。
     */
    public function freeze(Stocktake $stocktake, Staff $supervisor): Stocktake
    {
        if (!$supervisor->isSupervisor()) {
            throw new BusinessRuleException('只有主管可以确认盘点结果', 403);
        }

        return DB::transaction(function () use ($stocktake, $supervisor) {
            $locked = Stocktake::where('id', $stocktake->id)->lockForUpdate()->first();

            if ($locked->status === Stocktake::STATUS_FROZEN) {
                throw new BusinessRuleException("盘点单 {$locked->period} 已冻结，不能重复确认或修改");
            }
            if (!$locked->submitted_at) {
                throw new BusinessRuleException('盘点尚未提交，不能确认');
            }

            $period = $locked->period;
            $totalShortageQty = 0;
            $totalShortageAmount = 0;
            $summaryLines = [];

            foreach ($locked->lines()->get() as $line) {
                $type = $line->utensilType;
                if ($line->counted_qty === null) {
                    throw new BusinessRuleException("{$type->name} 未录入实盘数");
                }

                $adjustment = $line->counted_qty - $line->expected_qty;

                // 物线：仅当实盘≠账面时记一条 adjust 流水（冻结这一刻才发生）
                if ($adjustment !== 0) {
                    $this->ledger->record($type, 'adjust', $adjustment, [
                        'ref_type' => 'Stocktake',
                        'ref_id' => $locked->id,
                        'staff_id' => $supervisor->id,
                        'occurred_at' => CarbonImmutable::now(),
                        'note' => "{$period} 盘点" . ($adjustment < 0 ? '盘亏' : '盘盈') . ' ' . abs($adjustment),
                    ]);
                }

                // 钱线：盘亏部分生成追赔单（不产生数量流水），与 adjust 引用同一盘点单
                $shortageClaimQty = 0;
                if ($adjustment < 0) {
                    $shortageClaimQty = -$adjustment;
                    $claim = $this->claims->registerStocktakeShortage(
                        $type,
                        $shortageClaimQty,
                        $supervisor,
                        $locked->id,
                    );
                    $totalShortageQty += $shortageClaimQty;
                    $totalShortageAmount += $claim->amount_cents;
                }

                // 同一损失核对快照
                $recon = $this->buildReconciliation($line, $shortageClaimQty);

                $line->update([
                    'adjustment_qty' => $adjustment,
                    'reconciliation' => $recon,
                ]);

                $summaryLines[] = [
                    'utensil' => $type->name,
                    'expected' => $line->expected_qty,
                    'counted' => $line->counted_qty,
                    'adjustment' => $adjustment,
                    'shortage_claim_qty' => $shortageClaimQty,
                    'consistent' => $recon['consistent'],
                ];
            }

            $locked->update([
                'status' => Stocktake::STATUS_FROZEN,
                'frozen_by' => $supervisor->id,
                'frozen_at' => CarbonImmutable::now(),
                'summary' => [
                    'total_shortage_qty' => $totalShortageQty,
                    'total_shortage_amount_cents' => $totalShortageAmount,
                    'lines' => $summaryLines,
                ],
            ]);

            return $locked->fresh(['lines.utensilType', 'freezer']);
        });
    }

    /**
     * 核对同一损失不重复计损：
     *  - broken_claim_qty（破损赔付）必须 == writeoff_qty（已在账面扣减）
     *  - shortage_claim_qty 必须 == -adjustment（盘亏量），且 adjust 已记物线
     *  - lost_issued_qty（发放丢失）对应在外餐具，从未计入实物，不进入盘点差异
     */
    private function buildReconciliation(StocktakeLine $line, int $shortageClaimQty): array
    {
        $period = $line->stocktake->period;
        $start = CarbonImmutable::createFromFormat('Y-m', $period)->startOfMonth();
        $end = $start->endOfMonth();

        $claims = LossClaim::where('utensil_type_id', $line->utensil_type_id)
            ->whereBetween('created_at', [$start, $end])
            ->get()
            ->groupBy('source');

        $brokenClaimQty = (int) ($claims['broken_stock'] ?? collect())->sum('qty');
        $lostIssuedQty = (int) ($claims['lost_issued'] ?? collect())->sum('qty');

        $outstanding = IssuedUnit::where('utensil_type_id', $line->utensil_type_id)
            ->where('status', IssuedUnit::STATUS_OUT)
            ->whereHas('line.issuance', fn ($q) => $q
                ->where('issued_at', '>=', $start)->where('issued_at', '<=', $end))
            ->count();

        $consistent = $brokenClaimQty === $line->writeoff_qty
            && $shortageClaimQty === max(0, -$line->adjustment_qty);

        return [
            'broken_writeoff_qty' => $line->writeoff_qty,       // 物线已扣
            'broken_claim_qty' => $brokenClaimQty,             // 钱线追赔
            'lost_issued_qty' => $lostIssuedQty,               // 只转待赔付，实物不变
            'outstanding_qty' => $outstanding,                 // 期末仍在外
            'shortage_claim_qty' => $shortageClaimQty,         // 与 adjust(-) 同量
            'consistent' => $consistent,
        ];
    }

    private function assertOpen(Stocktake $stocktake): void
    {
        if ($stocktake->status === Stocktake::STATUS_FROZEN) {
            throw new BusinessRuleException("盘点单 {$stocktake->period} 已冻结，不能更改");
        }
    }

    private function assertPeriodFormat(string $period): void
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new BusinessRuleException('盘点月份格式应为 YYYY-MM');
        }
    }
}
