<?php

namespace App\Services;

use App\Models\Compensation;
use App\Models\Issuance;
use App\Models\StockCount;
use App\Models\StockCountLine;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * 工作台三栏，彼此独立，员工一眼区分当前要处理什么：
     *  - pending_returns  待归还：已发放未收回（未被赔付核销）的数量
     *  - pending_payments 待赔付：已登记破损/丢失但赔款未收
     *  - inventory_shortages 实物盘亏：盘点草稿中账实不符、待主管确认的行
     */
    public function summary(?int $windowId = null): array
    {
        $openIssuances = Issuance::query()
            ->when($windowId, fn ($q) => $q->where('window_id', $windowId))
            ->where('status', Issuance::STATUS_OPEN)
            ->with(['utensilType', 'window'])
            ->get();

        $pendingReturns = $openIssuances
            ->map(fn ($i) => [
                'issuance_id' => $i->id,
                'issuance_no' => $i->issuance_no,
                'utensil' => $i->utensilType->name,
                'origin_window' => $i->window->name,
                'issued_qty' => $i->qty,
                'returned_qty' => $i->returned_qty,
                'lost_qty' => $i->lost_qty,
                'outstanding_qty' => $i->outstandingQty(),
            ])
            ->filter(fn ($r) => $r['outstanding_qty'] > 0)
            ->values();

        $pendingPayments = Compensation::query()
            ->where('payment_status', Compensation::PAY_UNPAID)
            ->with(['utensilType', 'window'])
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => [
                'comp_id' => $c->id,
                'comp_no' => $c->comp_no,
                'kind' => $c->kind === Compensation::KIND_DAMAGED ? '破损' : '丢失',
                'utensil' => $c->utensilType->name,
                'qty' => $c->qty,
                'amount_yuan' => $c->total_amount / 100,
                'window' => $c->window->name,
                'source' => $c->stock_count_line_id ? '月末盘亏'
                    : ($c->issuance_item_id ? '借出丢失' : '库内破损'),
            ])
            ->values();

        $draftCount = StockCount::where('status', StockCount::STATUS_DRAFT)->with('lines.utensilType')->first();
        $inventoryShortages = $draftCount
            ? $draftCount->lines
                ->filter(fn ($l) => $l->variance < 0)
                ->map(fn ($l) => [
                    'stock_count_line_id' => $l->id,
                    'period' => $draftCount->period,
                    'utensil' => $l->utensilType->name,
                    'system_qty' => $l->system_qty,
                    'physical_qty' => $l->physical_qty,
                    'shortage_qty' => -$l->variance,
                    'status' => '待主管确认',
                ])
                ->values()
            : collect();

        return [
            'pending_returns' => [
                'count_of_issuances' => $pendingReturns->count(),
                'total_qty' => $pendingReturns->sum('outstanding_qty'),
                'items' => $pendingReturns,
            ],
            'pending_payments' => [
                'count' => $pendingPayments->count(),
                'total_amount_yuan' => round($pendingPayments->sum('amount_yuan'), 2),
                'items' => $pendingPayments,
            ],
            'inventory_shortages' => [
                'draft_period' => $draftCount?->period,
                'count' => $inventoryShortages->count(),
                'total_shortage_qty' => $inventoryShortages->sum('shortage_qty'),
                'items' => $inventoryShortages,
            ],
        ];
    }
}
