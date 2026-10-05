<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Issuance;
use App\Models\Returns;
use App\Models\StockCount;
use App\Models\StockLedger;
use App\Models\UtensilType;
use App\Models\Window;
use App\Services\CompensationService;
use App\Services\IssuanceService;
use App\Services\StockCountService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanteenFlowTest extends TestCase
{
    use RefreshDatabase;

    private UtensilType $tray;
    private Window $w1;
    private Window $w3;
    private Employee $staff;
    private Employee $staff2;
    private Employee $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tray = UtensilType::create(['code' => 'tray', 'name' => '餐盘', 'initial_stock' => 100, 'unit_price' => 1500]);
        app(StockLedgerService::class)->append($this->tray, StockLedgerService::TYPE_INITIAL, 100, 'utensil_type', $this->tray->id, '2026-09', now());
        $this->w1 = Window::create(['code' => 'W1', 'name' => '一号窗口']);
        $this->w3 = Window::create(['code' => 'W3', 'name' => '回收中心窗口']);
        $this->staff = Employee::create(['employee_no' => 'E001', 'name' => '王芳', 'role' => 'staff']);
        $this->staff2 = Employee::create(['employee_no' => 'E002', 'name' => '李强', 'role' => 'staff']);
        $this->supervisor = Employee::create(['employee_no' => 'E003', 'name' => '周敏', 'role' => 'supervisor']);
    }

    /**
     * 验证一：跨窗口归还仍对应原发放记录；重复扫描同一归还码只算一次。
     */
    public function test_cross_window_return_links_to_original_issuance_and_rescan_is_idempotent(): void
    {
        $svc = app(IssuanceService::class);
        $ledger = app(StockLedgerService::class);

        $issuance = $svc->issue($this->tray, $this->w1, $this->staff, 5);
        $token = $issuance->items->first()->return_token;

        $this->assertSame(95, $ledger->balance($this->tray->id));

        // 在另一个窗口（回收中心）扫码归还
        $first = $svc->returnByToken($token, $this->w3, $this->staff2);
        $this->assertFalse($first['duplicated']);
        $this->assertTrue($first['return']->cross_window);
        $this->assertSame($issuance->id, $first['return']->issuance_id, '归还必须挂到原发放单');
        $this->assertSame($this->w3->id, $first['return']->return_window_id, '归还记录的是实际收件窗口');
        $this->assertSame(96, $ledger->balance($this->tray->id));

        // 用同一归还码再扫两次：只保留第一条归还，数量不再增加
        $second = $svc->returnByToken($token, $this->w3, $this->staff2);
        $third = $svc->returnByToken($token, $this->w1, $this->staff);
        $this->assertTrue($second['duplicated']);
        $this->assertTrue($third['duplicated']);
        $this->assertSame($first['return']->id, $second['return']->id);
        $this->assertSame($first['return']->id, $third['return']->id);
        $this->assertSame(1, Returns::where('issuance_item_id', $issuance->items->first()->id)->count());
        $this->assertSame(1, $issuance->fresh()->returned_qty);
        $this->assertSame(96, $ledger->balance($this->tray->id), '重复扫描不能重复入库');

        // 原发放单仍能统计出剩余未还 4 件
        $this->assertSame(4, $issuance->fresh()->outstandingQty());
    }

    /**
     * 验证二：盘点冻结后，重复确认 / 重复修改都不会改变结果。
     */
    public function test_frozen_stock_count_cannot_be_changed_by_repeated_operations(): void
    {
        $svc = app(IssuanceService::class);
        $counts = app(StockCountService::class);
        $ledger = app(StockLedgerService::class);

        $svc->issue($this->tray, $this->w1, $this->staff, 10); // 账面 90

        $period = '2026-10';
        $draft = $counts->createDraft($period, $this->staff);
        $line = $draft->lines->firstWhere('utensil_type_id', $this->tray->id);
        $counts->setPhysical($line, 87); // 盘亏 3

        $confirmed = $counts->confirm($draft, $this->supervisor);
        $this->assertTrue($confirmed->isFrozen());
        $this->assertSame(87, $ledger->balance($this->tray->id));

        $adjustRowsAfterFirst = StockLedger::where('ref_type', 'stock_count_line')->count();
        $shortageCompsAfterFirst = \App\Models\Compensation::where('stock_count_line_id', $line->id)->count();

        // 重复确认：幂等回放，不新增流水、不新增赔付
        $again = $counts->confirm($confirmed->fresh(), $this->supervisor);
        $this->assertTrue($again->isFrozen());
        $this->assertSame($adjustRowsAfterFirst, StockLedger::where('ref_type', 'stock_count_line')->count());
        $this->assertSame($shortageCompsAfterFirst, \App\Models\Compensation::where('stock_count_line_id', $line->id)->count());
        $this->assertSame(87, $ledger->balance($this->tray->id));

        // 冻结后改实物数：被拒绝
        $this->expectException(\DomainException::class);
        $counts->setPhysical($line->fresh(), 50);
    }

    /** 普通员工不能确认盘点 */
    public function test_only_supervisor_can_confirm_stock_count(): void
    {
        $counts = app(StockCountService::class);
        $draft = $counts->createDraft('2026-10', $this->staff);

        $this->expectException(\DomainException::class);
        $counts->confirm($draft, $this->staff);
    }

    /**
     * 验证三：赔付（钱）与库存（数量）分别对账，且同一损失不重复计损。
     */
    public function test_compensation_and_stock_quantities_reconcile_separately(): void
    {
        $svc = app(IssuanceService::class);
        $comps = app(CompensationService::class);
        $counts = app(StockCountService::class);
        $ledger = app(StockLedgerService::class);

        // 发出 10 件（100 -> 90）
        $issuance = $svc->issue($this->tray, $this->w1, $this->staff, 10);

        // 收回 4 件（90 -> 94），其中 2 件跨窗口
        foreach ($issuance->items->take(2) as $item) {
            $svc->returnByToken($item->return_token, $this->w3, $this->staff2);
        }
        foreach ($issuance->items->slice(2, 2) as $item) {
            $svc->returnByToken($item->return_token, $this->w1, $this->staff);
        }

        // 借出的另外 2 件确认丢失并赔付 —— 发放时已出库，不再写数量流水
        $lostComps = [];
        foreach ($issuance->items->slice(4, 2) as $item) {
            $lostComps[] = $comps->reportLostForItem($item, $this->staff, $this->w1);
        }
        $balanceBeforePayment = $ledger->balance($this->tray->id);
        foreach ($lostComps as $comp) {
            $comps->pay($comp);
        }
        $this->assertSame($balanceBeforePayment, $ledger->balance($this->tray->id), '收款不能把数量加回库存');

        // 库内破损 1 件 —— 登记时出库（94 -> 93），与赔付单一一对应
        $damaged = $comps->reportDamaged($this->tray, 1, $this->staff, $this->w1);
        $this->assertSame(93, $ledger->balance($this->tray->id));
        $comps->pay($damaged);
        $this->assertSame(93, $ledger->balance($this->tray->id), '破损收款同样不改变数量');

        // 月末盘点：实物与账面完全一致（外借丢失、库内破损都已离账，不会再被盘亏一次）
        $period = '2026-10';
        $count = $counts->createDraft($period, $this->staff, [$this->tray->id => 93]);
        $confirmed = $counts->confirm($count, $this->supervisor);
        $this->assertSame(93, $ledger->balance($this->tray->id), '无差异时盘点不动库存');

        $report = $counts->reconciliation($period);

        // 钱账：丢失 2 件 + 破损 1 件 = 3 × 15 元
        $this->assertSame(4500, $report['totals']['comp_amount_paid']);
        $this->assertSame(0, $report['totals']['comp_amount_unpaid']);
        // 数量账：该月无盘亏盘盈
        $this->assertSame(0, $report['totals']['physical_shortage_qty']);
        $this->assertSame(0, $report['totals']['physical_gain_qty']);
        // 防重复计损
        $this->assertTrue($report['no_double_count']);
        $this->assertSame(2, count($report['compensations']['issued_lost']));
        $this->assertSame(1, count($report['compensations']['damaged']));
        $this->assertSame(0, count($report['compensations']['stock_shortage']));

        // 期末实物 = 期初 + 流水合计 = 100 -10 +4 -1 = 93
        $sumDelta = (int) StockLedger::where('utensil_type_id', $this->tray->id)->sum('qty_delta');
        $lastBalance = (int) StockLedger::where('utensil_type_id', $this->tray->id)->orderByDesc('id')->value('balance_after');
        $this->assertSame(93, $sumDelta);
        $this->assertSame(93, $lastBalance);
    }

    /** 盘亏场景：盘点调整与赔付核对同一损失，不会重复出库 */
    public function test_physical_shortage_generates_single_adjustment_and_single_compensation(): void
    {
        $svc = app(IssuanceService::class);
        $comps = app(CompensationService::class);
        $counts = app(StockCountService::class);
        $ledger = app(StockLedgerService::class);

        $svc->issue($this->tray, $this->w1, $this->staff, 10); // 90
        // 破损 2（90 -> 88）
        $comps->reportDamaged($this->tray, 2, $this->staff, $this->w1);
        // 月末只点到 85：盘亏 3
        $count = $counts->createDraft('2026-10', $this->staff, [$this->tray->id => 85]);
        $line = $count->lines->firstWhere('utensil_type_id', $this->tray->id);
        $this->assertSame(-3, $line->variance);

        $counts->confirm($count, $this->supervisor);

        $this->assertSame(85, $ledger->balance($this->tray->id));
        // 盘亏 3 件恰好生成 1 条 adjust_out 与 1 张赔付单
        $this->assertSame(1, StockLedger::where('ref_type', 'stock_count_line')->where('qty_delta', -3)->count());
        $shortageComp = \App\Models\Compensation::where('stock_count_line_id', $line->id)->first();
        $this->assertNotNull($shortageComp);
        $this->assertSame(3, $shortageComp->qty);
        $this->assertSame(4500, $shortageComp->total_amount);

        // 再确认一次也不会多出任何东西
        $counts->confirm($count->fresh(), $this->supervisor);
        $this->assertSame(1, \App\Models\Compensation::where('stock_count_line_id', $line->id)->count());
        $this->assertSame(85, $ledger->balance($this->tray->id));
    }

    /** 已丢失的餐具再扫码归还必须被拒绝，防止数量与赔付双算 */
    public function test_returning_lost_item_is_rejected(): void
    {
        $svc = app(IssuanceService::class);
        $comps = app(CompensationService::class);

        $issuance = $svc->issue($this->tray, $this->w1, $this->staff, 1);
        $item = $issuance->items->first();
        $comps->reportLostForItem($item, $this->staff, $this->w1);

        $this->expectException(\DomainException::class);
        $svc->returnByToken($item->return_token, $this->w3, $this->staff2);
    }
}
