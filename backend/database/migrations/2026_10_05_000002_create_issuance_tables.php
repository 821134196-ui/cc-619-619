<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 发放单：窗口登记发放
        Schema::create('issuances', function (Blueprint $table) {
            $table->id();
            $table->string('issue_no')->unique();
            $table->foreignId('window_id')->constrained();
            $table->foreignId('staff_id')->constrained();
            $table->dateTime('issued_at');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('issuance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issuance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('utensil_type_id')->constrained();
            $table->unsignedInteger('qty');
            $table->timestamps();
        });

        // 每件餐具一个归还码；跨窗口归还凭码定位原发放
        Schema::create('issued_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained('issuance_lines')->cascadeOnDelete();
            $table->foreignId('utensil_type_id')->constrained();
            $table->string('code', 40)->unique();
            // out=待归还 returned=已归还 lost=已登记丢失赔付
            $table->enum('status', ['out', 'returned', 'lost'])->default('out');
            $table->timestamps();
            $table->index(['status', 'utensil_type_id']);
        });

        // 归还登记：code 唯一 => 同一归还码重复扫描只产生一条登记
        Schema::create('return_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('issued_unit_id')->unique()->constrained();
            $table->foreignId('line_id')->constrained('issuance_lines');
            $table->foreignId('issuance_id')->constrained();
            $table->foreignId('issue_window_id')->constrained('windows');
            $table->foreignId('receive_window_id')->constrained('windows');
            $table->foreignId('receiver_staff_id')->constrained('staff');
            $table->dateTime('received_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_receipts');
        Schema::dropIfExists('issued_units');
        Schema::dropIfExists('issuance_lines');
        Schema::dropIfExists('issuances');
    }
};
