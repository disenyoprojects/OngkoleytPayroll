<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->date('date'); // the day it applies to; shows on the payslip whose window covers it
            $table->string('label'); // e.g. "Night shift bonus", "Rice allowance"
            $table->string('category')->default('other'); // cash_on_hand | allowance | bonus | deduction | other
            $table->decimal('amount', 12, 2); // + adds to pay, - deducts
            $table->boolean('paid')->default(false); // already handed over in cash → netted from amount still to release
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('payroll_adjustments');
    }
};
