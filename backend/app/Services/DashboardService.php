<?php

namespace App\Services;

use App\Models\IssuedUnit;
use App\Models\LossClaim;
use App\Models\ShiftHandover;
use App\Models\Staff;
use App\Models\StocktakeLine;
use App\Models\Window;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * 工作台三类待办，钱物分开：
     *  1. pending_returns  待归还：发放后尚未归还（在外餐具）
     *  2. pending_claims   待赔付：已登记损失但未收款（钱）
     *  3. stock_shortages  实物盘亏：已录实盘、盘亏待主管冻结确认（物）
     */
    public function overview(?Window $window = null): array
    {
        $returnsQuery = IssuedUnit::query()
            ->where('status', IssuedUnit::STATUS_OUT)
            ->whereHas('line.issuance', fn ($q) => $window
                ? $q->where('window_id', $window->id)
                : $q);

        $returnsByType = (clone $returnsQuery)
            ->select('utensil_type_id', DB::raw('COUNT(*) as qty'))
            ->with('utensilType')
            ->groupBy('utensil_type_id')
            ->get()
            ->map(fn ($r) => ['utensil' => $r->utensilType->name, 'qty' => (int) $r->qty])
            ->values();

        $claimsQuery = LossClaim::where('status', 'pending')
            ->when($window, fn ($q) => $q->where('window_id', $window->id));

        $claimsByType = (clone $claimsQuery)
            ->select('utensil_type_id', 'source', DB::raw('SUM(qty) as qty'), DB::raw('SUM(amount_cents) as amount_cents'))
            ->with('utensilType')
            ->groupBy('utensil_type_id', 'source')
            ->get()
            ->map(fn ($c) => [
                'utensil' => $c->utensilType->name,
                'source' => $c->source,
                'qty' => (int) $c->qty,
                'amount_cents' => (int) $c->amount_cents,
            ])
            ->values();

        // 实物盘亏：存在 open 盘点单且已有行实盘 < 账面
        $shortageLines = StocktakeLine::query()
            ->whereNotNull('counted_qty')
            ->whereRaw('counted_qty < expected_qty')
            ->whereHas('stocktake', fn ($q) => $q->where('status', 'open'))
            ->with(['utensilType', 'stocktake'])
            ->get()
            ->map(fn ($l) => [
                'period' => $l->stocktake->period,
                'utensil' => $l->utensilType->name,
                'expected_qty' => $l->expected_qty,
                'counted_qty' => $l->counted_qty,
                'shortage_qty' => $l->expected_qty - $l->counted_qty,
                'submitted' => $l->stocktake->submitted_at !== null,
            ])
            ->values();

        return [
            'pending_returns' => [
                'total_qty' => (int) $returnsQuery->count(),
                'by_utensil' => $returnsByType,
            ],
            'pending_claims' => [
                'total_qty' => (int) (clone $claimsQuery)->sum('qty'),
                'total_amount_cents' => (int) (clone $claimsQuery)->sum('amount_cents'),
                'by_utensil' => $claimsByType,
            ],
            'stock_shortages' => [
                'total_qty' => (int) $shortageLines->sum('shortage_qty'),
                'lines' => $shortageLines,
            ],
        ];
    }

    /**
     * 换班交接：快照当前尚未归还数量（按餐具类型、按发放单）
     * 与待赔付数量，交接后该窗口继续跟踪这些在外餐具。
     */
    public function handover(Window $window, Staff $from, Staff $to): ShiftHandover
    {
        return DB::transaction(function () use ($window, $from, $to) {
            $outstanding = IssuedUnit::where('status', IssuedUnit::STATUS_OUT)
                ->whereHas('line.issuance', fn ($q) => $q->where('window_id', $window->id))
                ->select('utensil_type_id', DB::raw('COUNT(*) as qty'))
                ->with('utensilType')
                ->groupBy('utensil_type_id')
                ->get()
                ->map(fn ($r) => ['utensil' => $r->utensilType->name, 'qty' => (int) $r->qty])
                ->values();

            $byIssuance = IssuedUnit::where('status', IssuedUnit::STATUS_OUT)
                ->whereHas('line.issuance', fn ($q) => $q->where('window_id', $window->id))
                ->selectRaw('line_id, COUNT(*) as qty')
                ->with('line.issuance')
                ->groupBy('line_id')
                ->get()
                ->map(fn ($r) => [
                    'issue_no' => $r->line->issuance->issue_no,
                    'issued_at' => $r->line->issuance->issued_at?->toDateTimeString(),
                    'qty' => (int) $r->qty,
                ])
                ->values();

            $pendingClaimQty = (int) LossClaim::where('status', 'pending')
                ->where('window_id', $window->id)
                ->sum('qty');

            return ShiftHandover::create([
                'window_id' => $window->id,
                'from_staff_id' => $from->id,
                'to_staff_id' => $to->id,
                'outstanding_snapshot' => [
                    'by_utensil' => $outstanding,
                    'by_issuance' => $byIssuance,
                ],
                'total_outstanding' => (int) $outstanding->sum('qty'),
                'pending_claim_qty' => $pendingClaimQty,
                'handed_at' => CarbonImmutable::now(),
            ]);
        });
    }
}
