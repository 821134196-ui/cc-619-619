<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LossClaim;
use App\Models\UtensilType;
use App\Services\ClaimService;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    use ResolvesContext;

    public function __construct(private ClaimService $claims)
    {
    }

    public function index(Request $request)
    {
        return LossClaim::with(['utensilType', 'window', 'staff', 'payments', 'stocktake:id,period'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /** 窗口实物破损登记（物线 writeoff + 钱线 pending 赔付） */
    public function storeBroken(Request $request)
    {
        $data = $request->validate([
            'utensil_type_id' => 'required|integer|exists:utensil_types,id',
            'qty' => 'required|integer|min:1',
            'window_id' => 'required|integer|exists:windows,id',
            'staff_id' => 'required|integer|exists:staff,id',
            'reason' => 'nullable|string',
        ]);

        $claim = $this->claims->registerBrokenStock(
            UtensilType::findOrFail($data['utensil_type_id']),
            $data['qty'],
            $this->window($request),
            $this->staff($request),
            $data['reason'] ?? null,
        );

        return response()->json($claim, 201);
    }

    /** 发放后丢失登记（仅钱线，不动实物数量） */
    public function storeLost(Request $request)
    {
        $data = $request->validate([
            'codes' => 'required|array|min:1',
            'codes.*' => 'required|string',
            'window_id' => 'nullable|integer|exists:windows,id',
            'staff_id' => 'required|integer|exists:staff,id',
            'reason' => 'nullable|string',
        ]);

        $claims = $this->claims->registerLostIssued(
            array_map('trim', $data['codes']),
            $this->staff($request),
            $this->window($request),
            $data['reason'] ?? null,
        );

        return response()->json($claims, 201);
    }

    /** 模拟收款（幂等；不回补库存） */
    public function pay(Request $request, LossClaim $claim)
    {
        $request->validate(['staff_id' => 'required|integer|exists:staff,id']);

        $alreadyPaid = $claim->status === 'paid';
        $txn = $this->claims->collectPayment($claim, $this->staff($request));

        return response()->json([
            'message' => $alreadyPaid
                ? '该赔付单已收款，重复收款请求幂等处理，未重复扣款'
                : '收款成功（赔款不影响餐具库存数量）',
            'claim' => $claim->fresh(),
            'payment' => $txn,
        ]);
    }
}
