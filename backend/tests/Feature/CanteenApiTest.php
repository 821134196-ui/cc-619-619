<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\StockLedger;
use App\Models\UtensilType;
use App\Models\Window;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanteenApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        UtensilType::create(['code' => 'tray', 'name' => '餐盘', 'initial_stock' => 100, 'unit_price' => 1500]);
        $tray = UtensilType::where('code', 'tray')->first();
        app(StockLedgerService::class)->append($tray, 'initial', 100, 'utensil_type', $tray->id, '2026-10', now());
        Window::create(['code' => 'W1', 'name' => '一号窗口']);
        Window::create(['code' => 'W3', 'name' => '回收中心窗口']);
        Employee::create(['employee_no' => 'E001', 'name' => '王芳', 'role' => 'staff']);
        Employee::create(['employee_no' => 'E003', 'name' => '周敏', 'role' => 'supervisor']);
    }

    public function test_full_http_flow_cross_window_return_freeze_and_separate_reconciliation(): void
    {
        // 一号窗口发放 4 件
        $issue = $this->postJson('/api/issuances', [
            'utensil_type_id' => 1, 'window_id' => 1, 'employee_id' => 1, 'qty' => 4,
        ])->assertCreated()->json();
        $this->assertCount(4, $issue['return_tokens']);
        $token = $issue['return_tokens'][0];

        // 回收中心窗口（window 2）跨窗口归还第 1 件
        $scan = $this->postJson('/api/returns/scan', [
            'return_token' => $token, 'window_id' => 2, 'employee_id' => 1,
        ])->assertCreated()->json();
        $this->assertTrue($scan['cross_window']);
        $this->assertSame($issue['issuance_id'], $scan['issuance_id'], 'API 归还仍挂原发放单');
        $this->assertSame('一号窗口', $scan['origin_window']);
        $this->assertSame('回收中心窗口', $scan['return_window']);

        // 重复扫描同一码：200 + duplicated_scan
        $rescan = $this->postJson('/api/returns/scan', [
            'return_token' => $token, 'window_id' => 2, 'employee_id' => 1,
        ])->assertOk()->json();
        $this->assertTrue($rescan['duplicated_scan']);

        // 发放详情：4 发 1 还
        $detail = $this->getJson("/api/issuances/{$issue['issuance_id']}")->assertOk()->json();
        $this->assertSame(4, $detail['qty']);
        $this->assertSame(1, $detail['returned_qty']);
        $this->assertSame(3, $detail['outstanding_qty']);

        // 工作台三栏数据结构齐全
        $dash = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertArrayHasKey('pending_returns', $dash);
        $this->assertArrayHasKey('pending_payments', $dash);
        $this->assertArrayHasKey('inventory_shortages', $dash);

        // 第 2 件丢失登记 + 模拟收款
        $lostToken = $issue['return_tokens'][1];
        $lost = $this->postJson('/api/compensations/lost', [
            'return_token' => $lostToken, 'window_id' => 1, 'employee_id' => 1,
        ])->assertCreated()->json();

        $balanceBefore = StockLedger::where('utensil_type_id', 1)->sum('qty_delta');
        $this->postJson("/api/compensations/{$this->compIdByNo($lost['comp_no'])}/pay", [])->assertOk();
        $balanceAfter = StockLedger::where('utensil_type_id', 1)->sum('qty_delta');
        $this->assertSame((int) $balanceBefore, (int) $balanceAfter, 'API 收款不改数量');

        // 重复收款不会产生新金额（待赔付清单里该单消失即已支付）
        $dash2 = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSame(0, collect($dash2['pending_payments']['items'])
            ->where('comp_no', $lost['comp_no'])->count());

        // 月末盘点：账面 = 100 -4 +1 = 97，实物填 97（丢失早已离账，不盘亏）
        $count = $this->postJson('/api/stock-counts', [
            'period' => '2026-10', 'employee_id' => 1,
        ])->assertCreated()->json();
        $line = collect($count['lines'])->firstWhere('utensil', '餐盘');
        $this->assertSame(97, $line['system_qty']);

        // 普通员工确认被拒
        $this->postJson("/api/stock-counts/{$count['id']}/confirm", ['employee_id' => 1])
            ->assertStatus(422);

        // 主管确认
        $confirmed = $this->postJson("/api/stock-counts/{$count['id']}/confirm", ['employee_id' => 2])
            ->assertOk()->json();
        $this->assertTrue($confirmed['frozen']);

        // 冻结后 PATCH 实物被拒
        $this->patchJson("/api/stock-counts/lines/{$line['line_id']}", ['physical_qty' => 1])
            ->assertStatus(422);

        // 重复确认：仍冻结、无新增赔付
        $reconfirm = $this->postJson("/api/stock-counts/{$count['id']}/confirm", ['employee_id' => 2])
            ->assertOk()->json();
        $this->assertTrue($reconfirm['frozen']);

        // 对账单：钱与数量分列，无重复计损
        $recon = $this->getJson('/api/reconciliation/2026-10')->assertOk()->json();
        $this->assertTrue($recon['no_double_count']);
        $this->assertSame(1500, $recon['totals']['comp_amount_paid']);
        $this->assertSame(0, $recon['totals']['physical_shortage_qty']);

        // 流水里没有任何赔付收款条目；期末 97 可由流水推出
        $ledger = $this->getJson('/api/ledger?utensil_type_id=1&period=2026-10')->assertOk()->json();
        $this->assertSame(97, $ledger['ending_calculation'][0]['ending_balance']);
        $this->assertSame(0, $ledger['ending_calculation'][0]['payment_entries']);
    }

    private function compIdByNo(string $no): int
    {
        return (int) \App\Models\Compensation::where('comp_no', $no)->value('id');
    }
}
