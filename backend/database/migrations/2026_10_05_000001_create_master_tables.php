<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 餐具类型（筷子、餐盘、汤碗……）
        Schema::create('utensil_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedInteger('initial_stock')->default(0)->comment('期初库存');
            $table->unsignedInteger('unit_price')->default(0)->comment('赔付单价（分）');
            $table->timestamps();
        });

        // 食堂窗口
        Schema::create('windows', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->timestamps();
        });

        // 员工（role: staff 窗口员工 / supervisor 主管）
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 32)->unique();
            $table->string('name');
            $table->string('role', 16)->default('staff');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
        Schema::dropIfExists('windows');
        Schema::dropIfExists('utensil_types');
    }
};
