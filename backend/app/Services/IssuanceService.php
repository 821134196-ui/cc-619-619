<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\IssuanceItem;
use App\Models\Returns;
use App\Models\Shift;
use App\Models\UtensilType;
use App\Models\Window;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IssuanceService
{
    public function __construct(private StockLedgerService $ledger)
    {
    }

    /**
     * 窗口发放一批餐具：每件生成唯一归还码，并写一条 issue 数量流水。
     */
    public function issue(
        UtensilType $type,
        Window $window,
        Employee $employee,
        int $qty,
        ?Shift $shift = null,
        ?\DateTimeInterface $at = null,
    ): Issuance {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('发放数量必须大于 0');
        }

        return DB::transaction(function () use ($type, $window, $employee, $qty, $shift, $at) {
            $issuance = Issuance::create([
                'issuance_no' => $this->nextNo(),
                'utensil_type_id' => $type->id,
                'window_id' => $window->id,
                'employee_id' => $employee->id,
                'shift_id' => $shift?->id,
                'qty' => $qty,
                'issued_at' => $at ?? now(),
            ]);

            $rows = [];
            foreach (range(1, $qty) as $seq) {
                $rows[] = [
                    'issuance_id' => $issuance->id,
                    'return_token' => $this->uniqueToken($issuance->issuance_no, $seq),
                    'status' => IssuanceItem::STATUS_OUT,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            IssuanceItem::insert($rows);

            $this->ledger->append(
                $type,
                StockLedgerService::TYPE_ISSUE,
                -$qty,
                'issuance',
                $issuance->id,
                ($at ?? now())->format('Y-m'),
                $at,
            );

            return $issuance->load('items');
        });
    }

    /**
     * 扫码归还。任何窗口都可以收，归还记录始终关联【原发放】明细。
     * 同一归还码重复扫描：返回第一次的记录，不重复计数、不重复写流水。
     *
     * @return array{return: Returns, duplicated: bool}
     */
    public function returnByToken(string $token, Window $window, Employee $employee, ?\DateTimeInterface $at = null): array
    {
        return DB::transaction(function () use ($token, $window, $employee, $at) {
            /** @var IssuanceItem $item */
            $item = IssuanceItem::where('return_token', $token)
                ->with(['issuance'])
                ->lockForUpdate()
                ->first();

            if (! $item) {
                throw new \DomainException('归还码无效：' . $token);
            }

            // 幂等：已归还 -> 回放首次归还记录
            $existing = Returns::where('issuance_item_id', $item->id)->first();
            if ($existing) {
                return ['return' => $existing->load('issuance', 'returnWindow'), 'duplicated' => true];
            }

            if ($item->status === IssuanceItem::STATUS_LOST) {
                throw new \DomainException('该餐具已登记丢失并进入赔付流程，不能归还');
            }

            $issuance = $item->issuance;
            $record = Returns::create([
                'issuance_item_id' => $item->id,
                'issuance_id' => $issuance->id,
                'utensil_type_id' => $issuance->utensil_type_id,
                'return_window_id' => $window->id,
                'employee_id' => $employee->id,
                'cross_window' => $window->id !== $issuance->window_id,
                'returned_at' => $at ?? now(),
            ]);

            $item->update(['status' => IssuanceItem::STATUS_RETURNED]);

            $issuance->increment('returned_qty');
            if ($issuance->fresh()->outstandingQty() === 0) {
                $issuance->update(['status' => Issuance::STATUS_CLOSED]);
            }

            $this->ledger->append(
                $issuance->utensilType,
                StockLedgerService::TYPE_RETURN,
                1,
                'return',
                $record->id,
                ($at ?? now())->format('Y-m'),
                $at,
            );

            return ['return' => $record->load('issuance', 'returnWindow'), 'duplicated' => false];
        });
    }

    private function nextNo(): string
    {
        return 'IS' . now()->format('YmdHis') . strtoupper(Str::random(4));
    }

    private function uniqueToken(string $issuanceNo, int $seq): string
    {
        // 归还码自带发放单号，扫码即可回溯到原发放记录
        return 'RT-' . substr($issuanceNo, 2) . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT)
             . '-' . strtoupper(Str::random(6));
    }
}
