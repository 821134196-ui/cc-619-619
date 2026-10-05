<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 数量流水（只追加，永不修改）：期末实物 = 期初 + Σdelta
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utensil_type_id')->constrained();
            $table->enum('type', ['opening', 'issue', 'return', 'writeoff', 'adjust']);
            $table->integer('delta'); // 带符号
            $table->integer('balance_after');
            $table->foreignId('window_id')->nullable()->constrained();
            $table->string('ref_type')->nullable();   // Issuance|ReturnReceipt|LossClaim|Stocktake
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('period', 7); // YYYY-MM
            $table->string('note')->nullable();
            $table->foreignId('staff_id')->nullable()->constrained();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['utensil_type_id', 'period']);
        });

        // 换班交接：交接时刻"尚未归还"的快照
        Schema::create('shift_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('window_id')->constrained();
            $table->foreignId('from_staff_id')->constrained('staff');
            $table->foreignId('to_staff_id')->constrained('staff');
            $table->json('outstanding_snapshot');
            $table->unsignedInteger('total_outstanding');
            $table->unsignedInteger('pending_claim_qty')->default(0);
            $table->dateTime('handed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_handovers');
        Schema::dropIfExists('stock_movements');
    }
};
