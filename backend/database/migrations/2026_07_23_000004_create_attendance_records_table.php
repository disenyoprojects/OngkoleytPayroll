<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->date('work_date');
            $table->time('shift_start')->default('08:00:00');
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->enum('status', ['pending', 'approved'])->default('pending');
            $table->boolean('adjusted')->default(false);
            $table->string('reason')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('attendance_records');
    }
};
