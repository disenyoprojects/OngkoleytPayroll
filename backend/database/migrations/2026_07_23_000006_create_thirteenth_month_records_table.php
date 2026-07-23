<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('thirteenth_month_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->unsignedSmallInteger('payroll_year');
            $table->decimal('computed_amount', 12, 2)->default(0);
            $table->decimal('manual_adjustment', 12, 2)->default(0);
            $table->enum('status', ['pending', 'computed', 'locked', 'released'])->default('pending');
            $table->date('released_on')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'payroll_year']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('thirteenth_month_records');
    }
};
