<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 班次（换班交接单位）
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('shift_no', 40)->unique();
            $table->foreignId('window_id')->constrained();
            $table->foreignId('opened_by')->constrained('employees');
            $table->dateTime('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('employees');
            $table->dateTime('closed_at')->nullable();
            $table->json('handover_snapshot')->nullable()->comment('交接时尚未归还数量快照');
            $table->string('status', 16)->default('open'); // open / closed
            $table->timestamps();
        });

        // 发放单：某窗口某员工向某类餐具发出的一批
        Schema::create('issuances', function (Blueprint $table) {
            $table->id();
            $table->string('issuance_no', 40)->unique();
            $table->foreignId('utensil_type_id')->constrained();
            $table->foreignId('window_id')->constrained()->comment('原发放窗口');
            $table->foreignId('employee_id')->constrained()->comment('发放经手人');
            $table->foreignId('shift_id')->nullable()->constrained();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('returned_qty')->default(0);
            $table->unsignedInteger('lost_qty')->default(0)->comment('已报失并赔付的数量');
            $table->string('status', 16)->default('open'); // open / closed
            $table->dateTime('issued_at');
            $table->timestamps();
        });

        // 发放明细：每件餐具一个唯一归还码（扫码归还、幂等的最小单位）
        Schema::create('issuance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issuance_id')->constrained()->cascadeOnDelete();
            $table->string('return_token', 64)->unique()->comment('归还码');
            $table->string('status', 16)->default('out'); // out / returned / lost
            $table->timestamps();
        });

        // 归还记录（可跨窗口；每件餐具最多一条 -> 天然幂等）
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issuance_item_id')->unique()->constrained();
            $table->foreignId('issuance_id')->constrained();
            $table->foreignId('utensil_type_id')->constrained();
            $table->foreignId('return_window_id')->constrained('windows')->comment('实际收到归还的窗口');
            $table->foreignId('employee_id')->constrained()->comment('归还经手人');
            $table->boolean('cross_window')->default(false)->comment('是否跨窗口归还');
            $table->dateTime('returned_at');
            $table->timestamps();
        });

        // 月末盘点单（主管确认后冻结）——先于赔付表，因赔付可引用盘点行
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7)->unique()->comment('盘点月份 YYYY-MM');
            $table->string('status', 16)->default('draft'); // draft / confirmed（冻结）
            $table->foreignId('counted_by')->constrained('employees');
            $table->foreignId('confirmed_by')->nullable()->constrained('employees');
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });

        // 盘点行：账面 / 实物 / 差异 / 处理方式
        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('utensil_type_id')->constrained();
            $table->integer('system_qty')->comment('账面数量');
            $table->integer('physical_qty')->comment('实物数量');
            $table->integer('variance')->comment('差异 = 实物 - 账面（负为盘亏）');
            // adjusted：盘亏/盘盈已出调整流水；reconciled：账实相符，无需调整
            $table->string('disposition', 16)->default('pending');
            $table->unique(['stock_count_id', 'utensil_type_id']);
            $table->timestamps();
        });

        // 赔付登记（破损 / 丢失）。与库存数量两条线：收款绝不写库存流水
        Schema::create('compensations', function (Blueprint $table) {
            $table->id();
            $table->string('comp_no', 40)->unique();
            $table->string('kind', 16)->default('lost'); // lost / damaged
            $table->foreignId('utensil_type_id')->constrained();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('unit_amount')->default(0)->comment('单价（分）');
            $table->unsignedInteger('total_amount')->default(0)->comment('应赔金额（分）');
            $table->foreignId('window_id')->constrained();
            $table->foreignId('reported_by')->constrained('employees');
            // 损失来源二选一：借出后丢失的发放明细，或月末盘点盘亏行
            $table->foreignId('issuance_item_id')->nullable()->unique()->constrained();
            $table->foreignId('stock_count_line_id')->nullable()->unique()->constrained();
            $table->string('payment_status', 16)->default('unpaid'); // unpaid / paid
            $table->string('payment_ref', 64)->nullable()->comment('模拟收款单号');
            $table->dateTime('paid_at')->nullable();
            $table->string('period', 7)->nullable()->comment('归属月份 YYYY-MM');
            $table->text('note')->nullable();
            $table->dateTime('reported_at');
            $table->timestamps();
        });

        // 库存数量流水：唯一能解释期末实物的数量账
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utensil_type_id')->constrained();
            $table->string('type', 24)->comment('initial/issue/return/adjust_in/adjust_out');
            $table->integer('qty_delta')->comment('带符号数量变动');
            $table->integer('balance_after')->comment('变动后结存');
            $table->string('ref_type', 32)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('period', 7)->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
        });

        // 模拟收款尝试（模拟支付网关回调留痕）
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compensation_id')->constrained('compensations')->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('channel', 16)->default('mock');
            $table->string('status', 16)->default('success'); // success / failed
            $table->string('payment_ref', 64)->unique();
            $table->json('gateway_payload')->nullable();
            $table->dateTime('paid_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('stock_ledger');
        Schema::dropIfExists('compensations');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('returns');
        Schema::dropIfExists('issuance_items');
        Schema::dropIfExists('issuances');
        Schema::dropIfExists('shifts');
    }
};
