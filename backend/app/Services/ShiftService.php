<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\Shift;
use App\Models\Employee;
use App\Models\Window;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    public function openShift(Window $window, Employee $employee, ?\DateTimeInterface $at = null): Shift
    {
        return Shift::create([
            'shift_no' => 'SH' . now()->format('YmdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            'window_id' => $window->id,
            'opened_by' => $employee->id,
            'opened_at' => $at ?? now(),
            'status' => 'open',
        ]);
    }

    /**
     * 换班交接：冻结本班次开出的、尚未归还的数量快照，交给下一班继续跟踪。
     * 未归还的责任随发放记录延续，不因换班消失。
     */
    public function closeShift(Shift $shift, Employee $closer, ?\DateTimeInterface $at = null): Shift
    {
        return DB::transaction(function () use ($shift, $closer, $at) {
            $shift->loadMissing('issuances');

            $rows = Issuance::where('shift_id', $shift->id)
                ->where('status', Issuance::STATUS_OPEN)
                ->with('utensilType')
                ->get()
                ->groupBy('utensil_type_id')
                ->map(function ($group, $typeId) {
                    $first = $group->first();
                    return [
                        'utensil_type_id' => $typeId,
                        'utensil_type' => $first->utensilType->name,
                        'outstanding_qty' => $group->sum(fn ($i) => $i->outstandingQty()),
                        'issuance_count' => $group->count(),
                    ];
                })
                ->values()
                ->all();

            $shift->update([
                'closed_by' => $closer->id,
                'closed_at' => $at ?? now(),
                'handover_snapshot' => $rows,
                'status' => 'closed',
            ]);

            return $shift;
        });
    }

    /** 当前某窗口尚未归还的总量（跨班次累计） */
    public function outstandingByWindow(Window $window): array
    {
        return Issuance::where('window_id', $window->id)
            ->where('status', Issuance::STATUS_OPEN)
            ->with('utensilType')
            ->get()
            ->groupBy('utensil_type_id')
            ->map(fn ($group) => $group->sum(fn ($i) => $i->outstandingQty()))
            ->all();
    }
}
