<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\UtensilType;
use App\Models\Window;
use App\Services\CompensationService;
use App\Services\IssuanceService;
use App\Services\ShiftService;
use App\Services\StockCountService;
use App\Services\StockLedgerService;
use Illuminate\Database\Seeder;

/**
 * 演示场景（当月）：
 *  - 一号窗口发出 10 个餐盘，3 个被回收中心跨窗口收回，1 个丢失已赔，6 个未还
 *  - 二号窗口发出 8 个汤碗，5 个本窗口收回，3 个未还
 *  - 库内破损 2 双筷子，已登记尚未收款（工作台"待赔付"栏可见）
 *  - 当月盘点草稿：餐盘实物比账面再少 2（"实物盘亏"栏，待主管确认）
 *  - 一号窗口一次已结班的交接快照
 */
class DemoScenarioSeeder extends Seeder
{
    public function run(
        StockLedgerService $ledger,
        IssuanceService $issuanceService,
        CompensationService $compensationService,
        StockCountService $countService,
        ShiftService $shiftService,
    ): void {
        $period = now()->format('Y-m');

        $tray = UtensilType::where('code', 'tray')->first();
        $bowl = UtensilType::where('code', 'bowl')->first();
        $chop = UtensilType::where('code', 'chopsticks')->first();
        $w1 = Window::where('code', 'W1')->first();
        $w2 = Window::where('code', 'W2')->first();
        $w3 = Window::where('code', 'W3')->first();
        $wang = Employee::where('employee_no', 'E001')->first();
        $li = Employee::where('employee_no', 'E002')->first();

        // 一号窗口发餐盘 10
        $trayIssue = $issuanceService->issue($tray, $w1, $wang, 10);
        // 3 件跨窗口到回收中心归还
        foreach ($trayIssue->items->take(3) as $item) {
            $issuanceService->returnByToken($item->return_token, $w3, $li);
        }
        // 1 件丢失并完成赔付
        $lostComp = $compensationService->reportLostForItem($trayIssue->items[3], $wang, $w1);
        $compensationService->pay($lostComp);

        // 二号窗口发汤碗 8，本窗口收回 5
        $bowlIssue = $issuanceService->issue($bowl, $w2, $li, 8);
        foreach ($bowlIssue->items->take(5) as $item) {
            $issuanceService->returnByToken($item->return_token, $w2, $li);
        }

        // 库内破损 2 双筷子：不收款，留在"待赔付"
        $compensationService->reportDamaged($chop, 2, $wang, $w1);

        // 一次完整换班（一号窗口）
        $shift = $shiftService->openShift($w1, $wang);
        $shiftService->closeShift($shift, $wang);

        // 当月盘点草稿（餐盘实物比账面少 2，等待主管确认）
        $count = $countService->createDraft($period, $wang);
        foreach ($count->lines as $line) {
            $line->load('utensilType');
            if ($line->utensilType->code === 'tray') {
                $countService->setPhysical($line, $line->system_qty - 2);
            }
        }
    }
}
