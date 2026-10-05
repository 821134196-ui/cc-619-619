<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\IssuedUnit;
use App\Models\Staff;
use App\Models\Window;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IssuanceService
{
    public function __construct(private StockLedger $ledger)
    {
    }

    /**
     * 登记发放：
     *  - 数量流水 issue(-qty)，库存实物减少
     *  - 每件生成唯一归还码，进入"待归还"
     */
    public function issue(Window $window, Staff $staff, array $lines, ?CarbonImmutable $at = null, ?string $note = null): Issuance
    {
        $at ??= CarbonImmutable::now();

        return DB::transaction(function () use ($window, $staff, $lines, $at, $note) {
            $issuance = Issuance::create([
                'issue_no' => 'IS' . $at->format('YmdHis') . strtoupper(Str::random(4)),
                'window_id' => $window->id,
                'staff_id' => $staff->id,
                'issued_at' => $at,
                'note' => $note,
            ]);

            foreach ($lines as $row) {
                $type = $row['type'];
                $qty = (int) $row['qty'];
                if ($qty <= 0) {
                    throw new \App\Support\BusinessRuleException('发放数量必须大于 0');
                }

                $line = $issuance->lines()->create([
                    'utensil_type_id' => $type->id,
                    'qty' => $qty,
                ]);

                for ($i = 0; $i < $qty; $i++) {
                    IssuedUnit::create([
                        'line_id' => $line->id,
                        'utensil_type_id' => $type->id,
                        // 归还码：全局唯一、可扫码（R 前缀 + 16 位随机）
                        'code' => 'R' . strtoupper(Str::random(16)),
                        'status' => IssuedUnit::STATUS_OUT,
                    ]);
                }

                $this->ledger->record($type, 'issue', -$qty, [
                    'window_id' => $window->id,
                    'ref_type' => 'Issuance',
                    'ref_id' => $issuance->id,
                    'staff_id' => $staff->id,
                    'occurred_at' => $at,
                    'note' => "发放单 {$issuance->issue_no}",
                ]);
            }

            return $issuance->load('lines.units', 'window', 'staff');
        });
    }
}
