<?php

namespace Tests\Feature;

use App\Models\IssuedUnit;
use App\Models\LossClaim;
use App\Models\PaymentTransaction;
use App\Models\ReturnReceipt;
use App\Models\Staff;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\UtensilType;
use App\Models\Window;
use App\Services\ClaimService;
use App\Services\DashboardService;
use App\Services\IssuanceService;
use App\Services\ReturnService;
use App\Services\StockLedger;
use App\Services\StocktakeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanteenWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Window $w1;
    private Window $w2;
    private Staff $zhang;
    private Staff $li;
    private Staff $supervisor;
    private UtensilType $plate;
    private UtensilType $bowl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->w1 = Window::create(['code' => 'W1', 'name' => '川味窗口']);
        $this->w2 = Window::create(['code' => 'W2', 'name' => '面食窗口']);
        $this->zhang = Staff::create(['employee_no' => 'E1', 'name' => '张伟', 'role' => 'staff', 'current_window_id' => $this->w1->id]);
        $this->li = Staff::create(['employee_no' => 'E2', 'name' => '李娜', 'role' => 'staff', 'current_window_id' => $this->w2->id]);
        $this->supervisor = Staff::create(['employee_no' => 'E9', 'name' => '赵敏', 'role' => 'supervisor']);

        $this->plate = UtensilType::create(['code' => 'TP', 'name' => '餐盘', 'unit_price_cents' => 1500, 'initial_qty' => 100]);
        $this->bowl = UtensilType::create(['code' => 'BW', 'name' => '汤碗', 'unit_price_cents' => 600, 'initial_qty' => 200]);

        $ledger = app(StockLedger::class);
        $open = CarbonImmutable::now()->startOfMonth()->addHour();
        $ledger->record($this->plate, 'opening', 100, ['occurred_at' => $open]);
        $ledger->record($this->bowl, 'opening', 200, ['occurred_at' => $open]);
    }

    private function balance(UtensilType $t): int
    {
        return app(StockLedger::class)->currentBalance($t->id);
    }

    /** 场景 1：跨窗口归还仍对应原发放记录；重复扫码只算一次 */
    public function test_cross_window_return_links_to_original_issuance_and_duplicate_scan_is_idempotent(): void
    {
        $issuance = app(IssuanceService::class)->issue(
            $this->w1, $this->zhang,
            [['type' => $this->plate, 'qty' => 3]],
        );

        $this->assertSame(97, $this->balance($this->plate));

        $codes = $issuance->lines->first()->units->pluck('code')->all();
        $this->assertCount(3, $codes);

        // 在 W2（其他窗口）扫描归还第一件
        $r1 = app(ReturnService::class)->scan($codes[0], $this->w2, $this->li);
        $this->assertFalse($r1['duplicate']);
        $receipt = $r1['receipt'];

        // 关键断言：关联的是原发放窗口 W1 / 原发放单，而接收窗口是 W2
        $this->assertSame($this->w1->id, $receipt->issue_window_id);
        $this->assertSame($this->w2->id, $receipt->receive_window_id);
        $this->assertSame($issuance->id, $receipt->issuance_id);
        $this->assertSame($codes[0], $receipt->code);
        $this->assertSame(100 - 3 + 1, $this->balance($this->plate)); // 归还 +1

        // 同一归还码重复扫描：幂等，只算一次
        $r2 = app(ReturnService::class)->scan($codes[0], $this->w2, $this->li);
        $this->assertTrue($r2['duplicate']);
        $this->assertSame($receipt->id, $r2['receipt']->id);
        $this->assertSame(1, ReturnReceipt::count());
        $this->assertSame(1, StockMovement::where('type', 'return')->count());
        $this->assertSame(100 - 3 + 1, $this->balance($this->plate)); // 数量不再变化

        // 剩余两件分别在 W1、W2 归还，全部回指同一发放单
        app(ReturnService::class)->scan($codes[1], $this->w1, $this->zhang);
        app(ReturnService::class)->scan($codes[2], $this->w2, $this->li);
        $this->assertSame(100, $this->balance($this->plate));
        $this->assertSame($issuance->id, IssuedUnit::with('line')->find(ReturnReceipt::where('code', $codes[2])->value('issued_unit_id'))->line->issuance_id);
        $this->assertSame(0, IssuedUnit::where('status', 'out')->count());
    }

    /** 场景 2：发放丢失登记只转待赔付、不动实物；收款不回补库存 */
    public function test_lost_claim_and_payment_never_change_physical_stock(): void
    {
        $issuance = app(IssuanceService::class)->issue(
            $this->w1, $this->zhang,
            [['type' => $this->bowl, 'qty' => 5]],
        );
        $codes = $issuance->lines->first()->units->pluck('code')->all();
        $stockAfterIssue = $this->balance($this->bowl);
        $this->assertSame(195, $stockAfterIssue);

        // 登记 2 件丢失
        $claims = app(ClaimService::class)->registerLostIssued(
            [$codes[0], $codes[1]], $this->zhang, $this->w1, '学生遗失'
        );
        $this->assertCount(1, $claims);
        $claim = $claims[0];
        $this->assertSame('lost_issued', $claim->source);
        $this->assertSame('pending', $claim->status);
        $this->assertSame(2, $claim->qty);
        $this->assertSame(1200, $claim->amount_cents);

        // 实物数量不变（钱物分离）
        $this->assertSame($stockAfterIssue, $this->balance($this->bowl));
        // 餐具状态由待归还变为丢失
        $this->assertSame(2, IssuedUnit::where('status', 'lost')->count());
        $this->assertSame(3, IssuedUnit::where('status', 'out')->count());

        // 已丢失件不能重复登记
        $this->expectException(\App\Support\BusinessRuleException::class);
        app(ClaimService::class)->registerLostIssued([$codes[0]], $this->zhang, $this->w1);
    }

    public function test_payment_is_idempotent_and_does_not_add_stock_back(): void
    {
        $issuance = app(IssuanceService::class)->issue(
            $this->w1, $this->zhang,
            [['type' => $this->bowl, 'qty' => 2]],
        );
        $codes = $issuance->lines->first()->units->pluck('code')->all();
        $claim = app(ClaimService::class)->registerLostIssued($codes, $this->zhang, $this->w1)[0];
        $stockBefore = $this->balance($this->bowl);
        $movementsBefore = StockMovement::count();

        // 模拟收款
        $pay = app(ClaimService::class)->collectPayment($claim, $this->li);
        $this->assertInstanceOf(PaymentTransaction::class, $pay);
        $this->assertSame('paid', $claim->fresh()->status);

        // 关键断言：收款不产生任何数量流水，库存不变
        $this->assertSame($stockBefore, $this->balance($this->bowl));
        $this->assertSame($movementsBefore, StockMovement::count());
        $this->assertSame(1, PaymentTransaction::count());

        // 重复收款：幂等返回原流水，不新增支付、不动库存
        $again = app(ClaimService::class)->collectPayment($claim->fresh(), $this->li);
        $this->assertSame($pay->id, $again->id);
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame($stockBefore, $this->balance($this->bowl));
    }

    /** 破损登记：物线 writeoff 与钱线赔付可分别对账，且数量一致可核对 */
    public function test_broken_stock_writes_writeoff_and_claim_separately(): void
    {
        $claim = app(ClaimService::class)->registerBrokenStock($this->plate, 4, $this->w1, $this->zhang, '摔碎');
        $this->assertSame(96, $this->balance($this->plate));
        $this->assertSame(1, StockMovement::where('type', 'writeoff')->where('delta', -4)->count());

        // 收款后库存仍然是 96
        app(ClaimService::class)->collectPayment($claim, $this->li);
        $this->assertSame(96, $this->balance($this->plate));
    }

    /** 场景 3：冻结盘点 —— 重复操作被拒，调整只落一次，钱物同一损失核对一致 */
    public function test_stocktake_freeze_is_one_shot_and_reconciles_claim_with_adjustment(): void
    {
        $period = CarbonImmutable::now()->format('Y-m');
        $svc = app(StocktakeService::class);

        // 餐盘：发 10，还 3，破损 2 => 账面 100-10+3-2 = 91
        $iss = app(IssuanceService::class)->issue($this->w1, $this->zhang, [
            ['type' => $this->plate, 'qty' => 10],
        ]);
        $codes = $iss->lines->first()->units->pluck('code')->all();
        app(ReturnService::class)->scan($codes[0], $this->w2, $this->li);
        app(ReturnService::class)->scan($codes[1], $codes[1] === $codes[1] ? $this->w1 : $this->w1, $this->zhang);
        app(ReturnService::class)->scan($codes[2], $this->w2, $this->li);
        app(ClaimService::class)->registerBrokenStock($this->plate, 2, $this->w1, $this->zhang);

        // 2 件发放后丢失（不进实物差异）
        app(ClaimService::class)->registerLostIssued([$codes[3], $codes[4]], $this->zhang, $this->w1);

        $stocktake = $svc->open($period, $this->zhang);
        $line = $stocktake->lines->where('utensil_type_id', $this->plate->id)->first();
        $this->assertSame(100, $line->opening_qty);
        $this->assertSame(10, $line->issued_qty);
        $this->assertSame(3, $line->returned_qty);
        $this->assertSame(2, $line->writeoff_qty);
        $this->assertSame(91, $line->expected_qty);

        // 实盘 88 => 盘亏 3
        $svc->recordCount($stocktake, $this->plate, 88);
        $bowlLine = $stocktake->lines->where('utensil_type_id', $this->bowl->id)->first();
        $svc->recordCount($stocktake->fresh(), $this->bowl, $bowlLine->expected_qty); // 汤碗无差异

        // 非主管不能冻结
        $this->expectException(\App\Support\BusinessRuleException::class);
        try {
            $svc->freeze($stocktake->fresh(), $this->zhang);
        } catch (\Throwable $e) {
            $this->assertStringContainsString('主管', $e->getMessage());
            throw $e;
        }
    }

    public function test_freeze_applies_adjust_once_and_second_freeze_is_rejected(): void
    {
        $period = CarbonImmutable::now()->format('Y-m');
        $svc = app(StocktakeService::class);

        app(IssuanceService::class)->issue($this->w1, $this->zhang, [['type' => $this->plate, 'qty' => 10]]);

        $stocktake = $svc->open($period, $this->zhang);
        foreach ($stocktake->lines as $l) {
            $svc->recordCount($stocktake->fresh(), $l->utensilType, $l->expected_qty - 1); // 每种盘亏 1
        }
        $svc->submit($stocktake->fresh(), $this->zhang);

        $adjustCount = fn () => StockMovement::where('type', 'adjust')->count();
        $shortageClaims = fn () => LossClaim::where('source', 'stocktake_shortage')->count();

        $this->assertSame(0, $adjustCount());
        $frozen = $svc->freeze($stocktake->fresh(), $this->supervisor);
        $this->assertTrue($frozen->isFrozen());

        // 每种餐具各一条 adjust(-1)
        $this->assertSame(2, $adjustCount());
        // 每种餐具各一张盘亏赔付单，引用同一盘点单
        $this->assertSame(2, $shortageClaims());
        $this->assertSame(2, LossClaim::where('stocktake_id', $frozen->id)->count());

        // 冻结后改实盘被拒
        try {
            $svc->recordCount($frozen, $this->plate, 1);
            $this->fail('冻结后不应允许修改');
        } catch (\App\Support\BusinessRuleException $e) {
            $this->assertStringContainsString('冻结', $e->getMessage());
        }

        // 重复冻结被拒，adjust / 赔付单数量不变
        try {
            $svc->freeze($frozen, $this->supervisor);
            $this->fail('重复冻结应被拒绝');
        } catch (\App\Support\BusinessRuleException $e) {
            $this->assertStringContainsString('已冻结', $e->getMessage());
        }
        $this->assertSame(2, $adjustCount());
        $this->assertSame(2, $shortageClaims());

        // 钱物核对快照一致
        $plateLine = $frozen->fresh()->lines->where('utensil_type_id', $this->plate->id)->first();
        $recon = $plateLine->reconciliation;
        $this->assertTrue($recon['consistent']);
        $this->assertSame(1, $recon['shortage_claim_qty']);
        $this->assertSame(-1, $plateLine->adjustment_qty);
    }

    /** 数量流水推导期末实物，与盘点账面一致 */
    public function test_ledger_movements_derive_period_end_physical_qty(): void
    {
        app(IssuanceService::class)->issue($this->w1, $this->zhang, [['type' => $this->plate, 'qty' => 10]]);
        $period = CarbonImmutable::now()->format('Y-m');
        $svc = app(StocktakeService::class);
        $st = $svc->open($period, $this->zhang);
        foreach ($st->lines as $l) {
            $svc->recordCount($st->fresh(), $l->utensilType, $l->expected_qty - 5);
        }
        $svc->submit($st->fresh(), $this->zhang);
        $svc->freeze($st->fresh(), $this->supervisor);

        $s = app(StockLedger::class)->periodSummary($this->plate->id, $period);
        // 100 期初 - 10 发放 - 5 盘亏 = 85
        $this->assertSame(100, $s['opening']);
        $this->assertSame(-10, $s['issued']);
        $this->assertSame(-5, $s['adjust']);
        $this->assertSame(85, $s['expected']);
        $this->assertSame(85, app(StockLedger::class)->currentBalance($this->plate->id));
    }

    /** 工作台三类待办分开 */
    public function test_dashboard_separates_pending_returns_claims_and_shortages(): void
    {
        $iss = app(IssuanceService::class)->issue($this->w1, $this->zhang, [['type' => $this->plate, 'qty' => 6]]);
        $codes = $iss->lines->first()->units->pluck('code')->all();
        app(ClaimService::class)->registerLostIssued([$codes[0]], $this->zhang, $this->w1);

        $period = CarbonImmutable::now()->format('Y-m');
        $svc = app(StocktakeService::class);
        $st = $svc->open($period, $this->zhang);
        $line = $st->lines->where('utensil_type_id', $this->bowl->id)->first();
        $svc->recordCount($st->fresh(), $this->bowl, $line->expected_qty - 2); // 汤碗盘亏 2（未冻结）

        $d = app(DashboardService::class)->overview();
        $this->assertSame(5, $d['pending_returns']['total_qty']);           // 6 - 1 丢失
        $this->assertSame(1, $d['pending_claims']['total_qty']);            // 丢失 1 待赔
        $this->assertSame(2, $d['stock_shortages']['total_qty']);           // 汤碗盘亏 2 待确认
    }

    /** 换班交接快照未归还数量 */
    public function test_handover_snapshots_outstanding(): void
    {
        app(IssuanceService::class)->issue($this->w1, $this->zhang, [['type' => $this->plate, 'qty' => 4]]);
        $h = app(DashboardService::class)->handover($this->w1, $this->zhang, $this->li);
        $this->assertSame(4, $h->total_outstanding);
        $this->assertSame('餐盘', $h->outstanding_snapshot['by_utensil'][0]['utensil']);
    }
}
