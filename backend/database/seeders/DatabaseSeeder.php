<?php

namespace Database\Seeders;

use App\Models\Staff;
use App\Models\StockMovement;
use App\Models\UtensilType;
use App\Models\Window;
use App\Services\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $w1 = Window::create(['code' => 'W1', 'name' => '川味窗口']);
        $w2 = Window::create(['code' => 'W2', 'name' => '面食窗口']);
        $w3 = Window::create(['code' => 'W3', 'name' => '快餐窗口']);

        Staff::create(['employee_no' => 'E1001', 'name' => '张伟', 'role' => 'staff', 'current_window_id' => $w1->id]);
        Staff::create(['employee_no' => 'E1002', 'name' => '李娜', 'role' => 'staff', 'current_window_id' => $w2->id]);
        Staff::create(['employee_no' => 'E1003', 'name' => '王强', 'role' => 'staff', 'current_window_id' => $w3->id]);
        Staff::create(['employee_no' => 'E9001', 'name' => '赵敏（主管）', 'role' => 'supervisor', 'current_window_id' => null]);

        $types = [
            ['code' => 'TP', 'name' => '不锈钢餐盘', 'unit_price_cents' => 1500, 'initial_qty' => 100],
            ['code' => 'BW', 'name' => '汤碗',       'unit_price_cents' => 600,  'initial_qty' => 200],
            ['code' => 'CZ', 'name' => '筷子（双）', 'unit_price_cents' => 200,  'initial_qty' => 300],
            ['code' => 'SZ', 'name' => '勺子',       'unit_price_cents' => 300,  'initial_qty' => 150],
        ];

        $ledger = app(StockLedger::class);
        $opening = CarbonImmutable::now()->startOfMonth()->addHours(8);

        foreach ($types as $t) {
            $type = UtensilType::create($t);
            $ledger->record($type, 'opening', $t['initial_qty'], [
                'note' => '期初建账',
                'occurred_at' => $opening,
            ]);
        }
    }
}
