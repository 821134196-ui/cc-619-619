<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Issuance;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockLedger;
use App\Models\UtensilType;
use App\Models\Window;
use App\Services\CompensationService;
use App\Services\DashboardService;
use App\Services\IssuanceService;
use App\Services\ShiftService;
use App\Services\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CanteenController extends Controller
{
    public function __construct(
        private IssuanceService $issuances,
        private CompensationService $compensations,
        private ShiftService $shifts,
        private StockCountService $counts,
        private DashboardService $dashboard,
    ) {
    }

    /* ---------- 基础数据 ---------- */

    public function masters(): JsonResponse
    {
        return response()->json([
            'utensil_types' => UtensilType::orderBy('id')->get()->map(fn ($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'initial_stock' => $t->initial_stock,
                'unit_price_yuan' => $t->unit_price / 100,
                'current_balance' => $t->currentBalance(),
            ]),
            'windows' => Window::orderBy('id')->get(['id', 'code', 'name']),
            'employees' => Employee::orderBy('id')->get(['id', 'employee_no', 'name', 'role']),
        ]);
    }

    /* ---------- 工作台 ---------- */

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->summary($request->query('window_id')));
    }

    /* ---------- 发放 ---------- */

    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'utensil_type_id' => ['required', 'exists:utensil_types,id'],
            'window_id' => ['required', 'exists:windows,id'],
            'employee_id' => ['required', 'exists:employees,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:10000'],
            'shift_id' => ['nullable', 'exists:shifts,id'],
        ]);

        $issuance = $this->issuances->issue(
            UtensilType::findOrFail($data['utensil_type_id']),
            Window::findOrFail($data['window_id']),
            Employee::findOrFail($data['employee_id']),
            $data['qty'],
            ! empty($data['shift_id']) ? \App\Models\Shift::find($data['shift_id']) : null,
        );

        return response()->json([
            'issuance_id' => $issuance->id,
            'issuance_no' => $issuance->issuance_no,
            'qty' => $issuance->qty,
            'return_tokens' => $issuance->items->pluck('return_token'),
        ], 201);
    }

    /* ---------- 扫码归还（可跨窗口，幂等） ---------- */

    public function returnByScan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'return_token' => ['required', 'string'],
            'window_id' => ['required', 'exists:windows,id'],
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        try {
            $result = $this->issuances->returnByToken(
                $data['return_token'],
                Window::findOrFail($data['window_id']),
                Employee::findOrFail($data['employee_id']),
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $r = $result['return'];
        return response()->json([
            'duplicated_scan' => $result['duplicated'],
            'return_id' => $r->id,
            'issuance_id' => $r->issuance_id,
            'issuance_no' => $r->issuance->issuance_no,
            'origin_window' => $r->issuance->window->name,
            'return_window' => $r->returnWindow->name,
            'cross_window' => (bool) $r->cross_window,
            'message' => $result['duplicated']
                ? '重复扫描：该归还码已归还，只计一次'
                : ($r->cross_window ? '跨窗口归还成功，已关联原发放记录' : '归还成功'),
        ], $result['duplicated'] ? 200 : 201);
    }

    public function issuanceDetail(int $id): JsonResponse
    {
        $issuance = Issuance::with(['utensilType', 'window', 'items', 'returns.returnWindow'])->findOrFail($id);

        return response()->json([
            'issuance_no' => $issuance->issuance_no,
            'utensil' => $issuance->utensilType->name,
            'origin_window' => $issuance->window->name,
            'qty' => $issuance->qty,
            'returned_qty' => $issuance->returned_qty,
            'lost_qty' => $issuance->lost_qty,
            'outstanding_qty' => $issuance->outstandingQty(),
            'items' => $issuance->items->map(fn ($it) => [
                'token' => $it->return_token,
                'status' => $it->status,
                'return_window' => $it->returnRecord?->returnWindow->name,
                'cross_window' => $it->returnRecord?->cross_window,
            ]),
        ]);
    }

    /* ---------- 换班交接 ---------- */

    public function openShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'window_id' => ['required', 'exists:windows,id'],
            'employee_id' => ['required', 'exists:employees,id'],
        ]);
        $shift = $this->shifts->openShift(Window::findOrFail($data['window_id']), Employee::findOrFail($data['employee_id']));
        return response()->json(['shift_id' => $shift->id, 'shift_no' => $shift->shift_no], 201);
    }

    public function closeShift(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
        ]);
        try {
            $shift = $this->shifts->closeShift(\App\Models\Shift::findOrFail($id), Employee::findOrFail($data['employee_id']));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return response()->json([
            'shift_no' => $shift->shift_no,
            'status' => $shift->status,
            'handover_snapshot' => $shift->handover_snapshot,
        ]);
    }

    /* ---------- 赔付登记 ---------- */

    public function reportLost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'return_token' => ['required', 'string'],
            'window_id' => ['required', 'exists:windows,id'],
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        $item = \App\Models\IssuanceItem::where('return_token', $data['return_token'])->first();
        if (! $item) {
            return $this->fail('归还码无效');
        }

        try {
            $comp = $this->compensations->reportLostForItem(
                $item,
                Employee::findOrFail($data['employee_id']),
                Window::findOrFail($data['window_id']),
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return response()->json(['comp_no' => $comp->comp_no, 'amount_yuan' => $comp->total_amount / 100], 201);
    }

    public function reportDamaged(Request $request): JsonResponse
    {
        $data = $request->validate([
            'utensil_type_id' => ['required', 'exists:utensil_types,id'],
            'qty' => ['required', 'integer', 'min:1'],
            'window_id' => ['required', 'exists:windows,id'],
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        try {
            $comp = $this->compensations->reportDamaged(
                UtensilType::findOrFail($data['utensil_type_id']),
                $data['qty'],
                Employee::findOrFail($data['employee_id']),
                Window::findOrFail($data['window_id']),
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return response()->json(['comp_no' => $comp->comp_no, 'amount_yuan' => $comp->total_amount / 100], 201);
    }

    /** 模拟收款（含模拟网关失败开关） */
    public function pay(Request $request, int $id): JsonResponse
    {
        $comp = \App\Models\Compensation::findOrFail($id);
        $attempt = $this->compensations->pay($comp, (bool) $request->input('simulate_failure', false));

        return response()->json([
            'comp_no' => $comp->fresh()->comp_no,
            'payment_status' => $comp->fresh()->payment_status,
            'payment_ref' => $attempt->payment_ref,
            'gateway_status' => $attempt->status,
            'message' => $attempt->status === 'failed'
                ? '模拟支付网关失败，赔款未收'
                : '收款成功（赔款不改变库存数量）',
        ]);
    }

    /* ---------- 月末盘点 ---------- */

    public function createCount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'employee_id' => ['required', 'exists:employees,id'],
            'physical' => ['nullable', 'array'],
        ]);

        try {
            $count = $this->counts->createDraft(
                $data['period'],
                Employee::findOrFail($data['employee_id']),
                $data['physical'] ?? [],
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return response()->json($this->countPayload($count), 201);
    }

    public function setCountPhysical(Request $request, int $lineId): JsonResponse
    {
        $data = $request->validate(['physical_qty' => ['required', 'integer', 'min:0', 'max:1000000']]);
        try {
            $line = $this->counts->setPhysical(StockCountLine::findOrFail($lineId), $data['physical_qty']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
        return response()->json(['line_id' => $line->id, 'variance' => $line->variance]);
    }

    public function confirmCount(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'exists:employees,id']]);
        try {
            $count = $this->counts->confirm(StockCount::findOrFail($id), Employee::findOrFail($data['employee_id']));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
        return response()->json($this->countPayload($count) + ['message' => '盘点已确认并冻结']);
    }

    public function countDetail(int $id): JsonResponse
    {
        return response()->json($this->countPayload(StockCount::with('lines.utensilType', 'confirmedBy')->findOrFail($id)));
    }

    /** 盘点 × 赔付 对账 */
    public function reconciliation(string $period): JsonResponse
    {
        return response()->json($this->counts->reconciliation($period));
    }

    /* ---------- 数量流水 ---------- */

    public function ledger(Request $request): JsonResponse
    {
        $request->validate([
            'utensil_type_id' => ['nullable', 'exists:utensil_types,id'],
            'period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $rows = StockLedger::with('utensilType')
            ->when($request->query('utensil_type_id'), fn ($q, $v) => $q->where('utensil_type_id', $v))
            ->when($request->query('period'), fn ($q, $v) => $q->where('period', $v))
            ->orderBy('id')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'utensil' => $l->utensilType->name,
                'type' => $l->type,
                'qty_delta' => $l->qty_delta,
                'balance_after' => $l->balance_after,
                'ref_type' => $l->ref_type,
                'ref_id' => $l->ref_id,
                'period' => $l->period,
                'at' => $l->occurred_at->toDateTimeString(),
            ]);

        // 期末实物计算说明：期初 + 流水合计（赔付收款不出现在其中）
        $byType = $rows->groupBy('utensil')->map(fn ($group, $name) => [
            'utensil' => $name,
            'sum_delta' => $group->sum('qty_delta'),
            'ending_balance' => $group->last()['balance_after'],
            'payment_entries' => 0,
        ])->values();

        return response()->json(['entries' => $rows, 'ending_calculation' => $byType]);
    }

    /* ---------- 辅助 ---------- */

    private function countPayload(StockCount $count): array
    {
        $count->loadMissing('lines.utensilType', 'confirmedBy');

        return [
            'id' => $count->id,
            'period' => $count->period,
            'status' => $count->status,
            'frozen' => $count->isFrozen(),
            'confirmed_by' => $count->confirmedBy?->name,
            'confirmed_at' => $count->confirmed_at?->toDateTimeString(),
            'lines' => $count->lines->map(fn ($l) => [
                'line_id' => $l->id,
                'utensil' => $l->utensilType->name,
                'system_qty' => $l->system_qty,
                'physical_qty' => $l->physical_qty,
                'variance' => $l->variance,
                'disposition' => $l->disposition,
            ]),
        ];
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
