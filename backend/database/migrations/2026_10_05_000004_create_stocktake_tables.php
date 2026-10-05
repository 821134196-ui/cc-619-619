<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 盘点单：open=可录入实盘，frozen=主管确认冻结，不可更改
        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7)->unique(); // YYYY-MM
            $table->enum('status', ['open', 'frozen'])->default('open');
            $table->foreignId('created_by')->constrained('staff');
            $table->foreignId('submitted_by')->nullable()->constrained('staff');
            $table->foreignId('frozen_by')->nullable()->constrained('staff');
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('frozen_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('utensil_type_id')->constrained();
            $table->unsignedInteger('opening_qty');
            $table->unsignedInteger('issued_qty')->default(0);
            $table->unsignedInteger('returned_qty')->default(0);
            $table->unsignedInteger('writeoff_qty')->default(0);
            $table->integer('expected_qty');
            $table->integer('counted_qty')->nullable();
            $table->integer('adjustment_qty')->default(0);
            // 快照：本期已登记赔付损失（件数/金额），用于"同一损失不重复计损"核对
            $table->json('reconciliation')->nullable();
            $table->timestamps();
            $table->unique(['stocktake_id', 'utensil_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_lines');
        Schema::dropIfExists('stocktakes');
    }
};
