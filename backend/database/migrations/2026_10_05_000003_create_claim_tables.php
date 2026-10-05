<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 赔付单（钱的流）：与餐具数量流严格分开
        // broken_stock      窗口实物破损：登记时即做 writeoff 数量流水
        // lost_issued       发放后丢失：只把"待归还"转为"待赔付"，不动实物数量
        // stocktake_shortage 盘点盘亏：冻结时已用 adjust 计损，本单仅追收赔款
        Schema::create('loss_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_no')->unique();
            $table->enum('source', ['broken_stock', 'lost_issued', 'stocktake_shortage']);
            // pending=待赔付 paid=已收款
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->foreignId('utensil_type_id')->constrained();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('amount_cents');
            $table->foreignId('window_id')->nullable()->constrained();
            $table->foreignId('staff_id')->constrained('staff');
            $table->string('reason')->nullable();
            $table->foreignId('stocktake_id')->nullable()->constrained();
            $table->string('payment_ref')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'source']);
        });

        // lost_issued 赔付与具体在外餐具件的关联（一件只能赔一次）
        Schema::create('claim_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loss_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_unit_id')->unique()->constrained();
            $table->timestamps();
        });

        // 收款流水（模拟支付网关）
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loss_claim_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('gateway_ref')->unique();
            $table->string('gateway')->default('mock');
            $table->dateTime('paid_at');
            $table->foreignId('collected_by')->constrained('staff');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('claim_units');
        Schema::dropIfExists('loss_claims');
    }
};
