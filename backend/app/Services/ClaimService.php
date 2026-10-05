<?php

namespace App\Services;

use App\Models\IssuedUnit;
use App\Models\LossClaim;
use App\Models\PaymentTransaction;
use App\Models\Staff;
use App\Models\UtensilType;
use App\Models\Window;
use App\Support\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimService
{
    public function __construct(private StockLedger $ledger)
    {
    }

    /**
     * 窗口实物破损登记：
     *  - 产生 pending 赔付单（钱线）
     *  - 同时做 writeoff 数量流水（物线：实物确实不存在了）
     * 之后收到赔款只更新赔付单，不再动数量。
     */
    public function registerBrokenStock(UtensilType $type, int $qty, Window $window, Staff $staff, ?string $reason = null): LossClaim
    {
        if ($qty <= 0) {
            throw new BusinessRuleException('破损数量必须大于 0');
        }

        return DB::transaction(function () use ($type, $qty, $window, $staff, $reason) {
            $now = CarbonImmutable::now();
            $claim = $this->createClaim(LossClaim::SOURCE_BROKEN_STOCK, $type, $qty, $window, $staff, $reason, $now);

            $this->ledger->record($type, 'writeoff', -$qty, [
                'window_id' => $window->id,
                'ref_type' => 'LossClaim',
                'ref_id' => $claim->id,
                'staff_id' => $staff->id,
                'occurred_at' => $now,
                'note' => "破损注销 {$claim->claim_no}",
            ]);

            return $claim;
        });
    }

    /**
     * 发放后丢失登记（按归还码）：
     *  - 仅把"待归还"转为"待赔付"，不做任何数量流水
     *    （发放时库存已扣减；餐具在外的状态改变，实物数量不变）
     *  - 每件只能登记一次（claim_units.issued_unit_id 唯一）
     * 按餐具类型分组，每种生成一张赔付单。
     */
    public function registerLostIssued(array $codes, Staff $staff, ?Window $window, ?string $reason = null): array
    {
        if (!$codes) {
            throw new BusinessRuleException('请提供丢失餐具的归还码');
        }

        return DB::transaction(function () use ($codes, $staff, $window, $reason) {
            $units = IssuedUnit::with(['line.issuance', 'utensilType'])
                ->whereIn('code', $codes)
                ->lockForUpdate()
                ->get();

            if ($units->count() !== count(array_unique($codes))) {
                $found = $units->pluck('code')->all();
                $missing = array_values(array_diff(array_unique($codes), $found));
                throw new BusinessRuleException('无效归还码：' . implode(', ', $missing), 404);
            }

            foreach ($units as $unit) {
                if ($unit->status === IssuedUnit::STATUS_RETURNED) {
                    throw new BusinessRuleException("餐具 {$unit->code} 已归还，不能登记丢失");
                }
                if ($unit->status === IssuedUnit::STATUS_LOST) {
                    throw new BusinessRuleException("餐具 {$unit->code} 已登记丢失赔付，不能重复登记");
                }
            }

            $now = CarbonImmutable::now();
            $claims = [];

            foreach ($units->groupBy('utensil_type_id') as $typeUnits) {
                $type = $typeUnits->first()->utensilType;
                $claim = $this->createClaim(
                    LossClaim::SOURCE_LOST_ISSUED,
                    $type,
                    $typeUnits->count(),
                    $window,
                    $staff,
                    $reason,
                    $now,
                );

                foreach ($typeUnits as $unit) {
                    $claim->units()->create(['issued_unit_id' => $unit->id]);
                    $unit->update(['status' => IssuedUnit::STATUS_LOST]);
                }
                $claims[] = $claim;
            }

            return $claims;
        });
    }

    /**
     * 盘点盘亏生成赔付单（冻结时调用）：仅钱线，数量已由盘点 adjust 处理。
     */
    public function registerStocktakeShortage(UtensilType $type, int $qty, Staff $staff, int $stocktakeId, ?Window $window = null, ?string $reason = null): LossClaim
    {
        return DB::transaction(function () use ($type, $qty, $staff, $stocktakeId, $window, $reason) {
            return $this->createClaim(
                LossClaim::SOURCE_STOCKTAKE_SHORTAGE,
                $type,
                $qty,
                $window,
                $staff,
                $reason ?? "盘点盘亏追赔 #{$stocktakeId}",
                CarbonImmutable::now(),
                $stocktakeId
            );
        });
    }

    /**
     * 模拟收款。幂等：对已支付的赔付单重复收款直接返回原流水。
     * 关键规则：收款只记录 payment_transactions 并把赔付单置 paid，
     * 绝不产生 stock_movements（赔款不回补餐具数量）。
     */
    public function collectPayment(LossClaim $claim, Staff $collector, ?string $clientGatewayRef = null): PaymentTransaction
    {
        return DB::transaction(function () use ($claim, $collector, $clientGatewayRef) {
            $claim->lockForUpdate();
            $claim = LossClaim::find($claim->id);

            if ($claim->status === 'paid') {
                // 幂等返回，不重复扣款、不写流水
                return $claim->payments()->latest('id')->first();
            }

            $now = CarbonImmutable::now();
            $gatewayRef = $clientGatewayRef
                ?? MockPaymentGateway::charge($claim->amount_cents, $claim->claim_no);

            $txn = $claim->payments()->create([
                'amount_cents' => $claim->amount_cents,
                'gateway_ref' => $gatewayRef,
                'gateway' => 'mock',
                'paid_at' => $now,
                'collected_by' => $collector->id,
            ]);

            $claim->update([
                'status' => 'paid',
                'payment_ref' => $gatewayRef,
                'paid_at' => $now,
            ]);

            return $txn;
        });
    }

    private function createClaim(
        string $source,
        UtensilType $type,
        int $qty,
        ?Window $window,
        Staff $staff,
        ?string $reason,
        CarbonImmutable $at,
        ?int $stocktakeId = null,
    ): LossClaim {
        return LossClaim::create([
            'claim_no' => 'PC' . $at->format('YmdHis') . strtoupper(Str::random(4)),
            'source' => $source,
            'status' => 'pending',
            'utensil_type_id' => $type->id,
            'qty' => $qty,
            'unit_price_cents' => $type->unit_price_cents,
            'amount_cents' => $type->unit_price_cents * $qty,
            'window_id' => $window?->id,
            'staff_id' => $staff->id,
            'reason' => $reason,
            'stocktake_id' => $stocktakeId,
        ]);
    }
}
