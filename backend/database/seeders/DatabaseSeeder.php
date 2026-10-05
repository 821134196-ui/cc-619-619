<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\UtensilType;
use App\Models\Window;
use App\Services\StockLedgerService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(StockLedgerService $ledger): void
    {
        $types = [
            ['code' => 'tray', 'name' => '不锈钢餐盘', 'initial_stock' => 100, 'unit_price' => 1500],
            ['code' => 'bowl', 'name' => '汤碗', 'initial_stock' => 200, 'unit_price' => 800],
            ['code' => 'chopsticks', 'name' => '筷子（双）', 'initial_stock' => 300, 'unit_price' => 300],
        ];

        foreach ($types as $t) {
            $type = UtensilType::create($t);
            $ledger->append(
                $type,
                StockLedgerService::TYPE_INITIAL,
                $t['initial_stock'],
                'utensil_type',
                $type->id,
                now()->format('Y-m'),
                now()->startOfMonth(),
            );
        }

        Window::create(['code' => 'W1', 'name' => '一号窗口']);
        Window::create(['code' => 'W2', 'name' => '二号窗口']);
        Window::create(['code' => 'W3', 'name' => '回收中心窗口']);

        Employee::create(['employee_no' => 'E001', 'name' => '王芳', 'role' => 'staff']);
        Employee::create(['employee_no' => 'E002', 'name' => '李强', 'role' => 'staff']);
        Employee::create(['employee_no' => 'E003', 'name' => '周敏', 'role' => 'supervisor']);
    }
}
