<?php

namespace App\Services;

use App\Models\IssuedUnit;
use App\Models\ReturnReceipt;
use App\Models\Staff;
use App\Models\Window;
use App\Support\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReturnService
{
    public function __construct(private StockLedger $ledger)
    {
    }

    /**
     * 扫描归还码登记归还。
     *
     * 关键规则：
     *  - 任意窗口均可扫码；登记记录同时保存"发放窗口"和"实际接收窗口"，
     *    通过 issued_unit -> line -> issuance 始终关联回原发放记录。
     *  - 同一归还码重复扫描只算一次：已有 receipt 时直接返回旧记录（幂等）。
     *  - 已登记丢失赔付的餐具不能再归还（钱与物两条线，需走冲销流程）。
     */
    public function scan(string $code, Window $receiveWindow, Staff $receiver, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        return DB::transaction(function () use ($code, $receiveWindow, $receiver, $at) {
            $unit = IssuedUnit::with([
                'line.issuance.window', 'utensilType',
            ])->where('code', $code)->first();

            if (!$unit) {
                throw new BusinessRuleException("无效归还码：{$code}", 404);
            }

            // 幂等：该码已登记过归还 —— 重复扫描返回既有记录，不再产生流水
            $existing = ReturnReceipt::where('issued_unit_id', $unit->id)->first();
            if ($existing) {
                return [
                    'receipt' => $existing->load('issueWindow', 'receiveWindow', 'receiver'),
                    'duplicate' => true,
                ];
            }

            if ($unit->status === IssuedUnit::STATUS_LOST) {
                throw new BusinessRuleException('该餐具已登记丢失并赔付，不能重复归还；如需冲销请联系主管');
            }

            $line = $unit->line;
            $issuance = $line->issuance;

            $receipt = ReturnReceipt::create([
                'code' => $unit->code,
                'issued_unit_id' => $unit->id,
                'line_id' => $line->id,
                'issuance_id' => $issuance->id,
                'issue_window_id' => $issuance->window_id,          // 原发放窗口
                'receive_window_id' => $receiveWindow->id,          // 实际接收窗口（可不同）
                'receiver_staff_id' => $receiver->id,
                'received_at' => $at,
            ]);

            $unit->update(['status' => IssuedUnit::STATUS_RETURNED]);

            $this->ledger->record($unit->utensilType, 'return', 1, [
                'window_id' => $receiveWindow->id,
                'ref_type' => 'ReturnReceipt',
                'ref_id' => $receipt->id,
                'staff_id' => $receiver->id,
                'occurred_at' => $at,
                'note' => sprintf(
                    '%s回收，原发放窗口 %s / 发放单 %s',
                    $receiveWindow->code,
                    $issuance->window->code,
                    $issuance->issue_no
                ),
            ]);

            return [
                'receipt' => $receipt->load('issueWindow', 'receiveWindow', 'receiver', 'issuedUnit.utensilType'),
                'duplicate' => false,
            ];
        });
    }
}
