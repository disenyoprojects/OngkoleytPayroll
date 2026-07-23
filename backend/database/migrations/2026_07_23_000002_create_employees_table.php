<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code')->unique();
            $table->string('full_name');
            $table->string('short_name');
            $table->string('role');
            $table->foreignId('branch_id')->constrained();
            $table->enum('employment_type', ['regular', 'probationary', 'fixed_term', 'seasonal']);
            $table->date('hire_date');
            $table->date('resignation_date')->nullable();
            $table->string('pin_hash');
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('employees');
    }
};
