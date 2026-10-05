<?php

namespace App\Services;

use App\Models\Compensation;
use App\Models\Employee;
use App\Models\IssuanceItem;
use App\Models\PaymentAttempt;
use App\Models\UtensilType;
use App\Models\Window;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 赔付（破损 / 丢失）。
 *
 * 铁律：
 *  - 收款只改"钱"的状态，绝不写库存数量流水；
 *  - 借出后丢失：餐具在发放时已出库，此处只把未还责任核销为赔款，不再出库；
 *  - 库内破损：破损即出库（adjust_out），与赔款登记一一对应；
 *  - 一条损失只能有一个去向（发放明细唯一 / 盘点行唯一），天然防止重复计损。
 */
class CompensationService
{
    public function __construct(private StockLedgerService $ledger)
    {
    }

    /** 借出未还 -> 丢失赔付（关联原发放明细） */
    public function reportLostForItem(IssuanceItem $item, Employee $reporter, Window $window, ?int $unitAmount = null, ?string $note = null): Compensation
    {
        return DB::transaction(function () use ($item, $reporter, $window, $unitAmount, $note) {
            $item->loadMissing('issuance.utensilType');
            $issuance = $item->issuance;

            if ($item->status === IssuanceItem::STATUS_RETURNED) {
                throw new \DomainException('该餐具已归还，不能登记丢失赔付');
            }
            if ($item->status === IssuanceItem::STATUS_LOST) {
                throw new \DomainException('该餐具已登记丢失，不能重复登记');
            }
            if (Compensation::where('issuance_item_id', $item->id)->exists()) {
                throw new \DomainException('该餐具已有赔付记录，不能重复计损');
            }

            $type = $issuance->utensilType;
            $unit = $unitAmount ?? $type->unit_price;
            $period = now()->format('Y-m');

            $comp = Compensation::create([
                'comp_no' => $this->nextNo(),
                'kind' => Compensation::KIND_LOST,
                'utensil_type_id' => $type->id,
                'qty' => 1,
                'unit_amount' => $unit,
                'total_amount' => $unit,
                'window_id' => $window->id,
                'reported_by' => $reporter->id,
                'issuance_item_id' => $item->id,
                'payment_status' => Compensation::PAY_UNPAID,
                'period' => $period,
                'note' => $note,
                'reported_at' => now(),
            ]);

            // 核销未还责任：发放时已出库，这里不写数量流水
            $item->update(['status' => IssuanceItem::STATUS_LOST]);
            $issuance->increment('lost_qty');
            $issuance->fresh()->outstandingQty() === 0
                && $issuance->update(['status' => \App\Models\Issuance::STATUS_CLOSED]);

            return $comp;
        });
    }

    /** 库内破损赔付（餐具实物报废 -> 出库一条 adjust_out，对应这张赔付单） */
    public function reportDamaged(UtensilType $type, int $qty, Employee $reporter, Window $window, ?int $unitAmount = null, ?string $note = null): Compensation
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('破损数量必须大于 0');
        }

        return DB::transaction(function () use ($type, $qty, $reporter, $window, $unitAmount, $note) {
            $available = $this->ledger->balance($type->id);
            if ($qty > $available) {
                throw new \DomainException("破损数量超过当前在库（在库 {$available}）");
            }

            $unit = $unitAmount ?? $type->unit_price;
            $period = now()->format('Y-m');

            $comp = Compensation::create([
                'comp_no' => $this->nextNo(),
                'kind' => Compensation::KIND_DAMAGED,
                'utensil_type_id' => $type->id,
                'qty' => $qty,
                'unit_amount' => $unit,
                'total_amount' => $unit * $qty,
                'window_id' => $window->id,
                'reported_by' => $reporter->id,
                'payment_status' => Compensation::PAY_UNPAID,
                'period' => $period,
                'note' => $note,
                'reported_at' => now(),
            ]);

            $this->ledger->append(
                $type,
                StockLedgerService::TYPE_ADJUST_OUT,
                -$qty,
                'compensation',
                $comp->id,
                $period,
            );

            return $comp;
        });
    }

    /**
     * 模拟收款。幂等：已支付的赔款再次收款只回放原结果，不重复入账。
     * 收款动作不产生任何库存流水。
     */
    public function pay(Compensation $comp, bool $simulateGatewayFailure = false): PaymentAttempt
    {
        return DB::transaction(function () use ($comp, $simulateGatewayFailure) {
            $comp->lockForUpdate();

            // 幂等收款：钱不重复收
            if ($comp->payment_status === Compensation::PAY_PAID) {
                return PaymentAttempt::where('compensation_id', $comp->id)
                    ->where('status', PaymentAttempt::STATUS_SUCCESS)
                    ->latest('id')
                    ->first();
            }

            $ref = 'PAY-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(8));

            $attempt = PaymentAttempt::create([
                'compensation_id' => $comp->id,
                'amount' => $comp->total_amount,
                'channel' => 'mock',
                'status' => $simulateGatewayFailure ? PaymentAttempt::STATUS_FAILED : PaymentAttempt::STATUS_SUCCESS,
                'payment_ref' => $ref,
                'gateway_payload' => [
                    'gateway' => 'mock-pay',
                    'comp_no' => $comp->comp_no,
                    'simulated' => true,
                ],
                'paid_at' => now(),
            ]);

            if (! $simulateGatewayFailure) {
                $comp->update([
                    'payment_status' => Compensation::PAY_PAID,
                    'payment_ref' => $ref,
                    'paid_at' => now(),
                ]);
            }

            return $attempt;
        });
    }

    private function nextNo(): string
    {
        return 'CP' . now()->format('YmdHis') . strtoupper(Str::random(4));
    }
}
