<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A weekly shift pattern per employee. Staff do not stand the same hours every
 * day — an 06:00-15:00 Monday and an 11:00-20:00 Saturday is ordinary here —
 * and one shift_start/shift_end on the employee could not express that, so
 * every off-pattern day had to be corrected by hand after the fact.
 *
 * A row per weekday the employee works differently; a weekday with no row falls
 * back to the employee's default shift. day_of_week follows Carbon and PHP
 * date('w'): 0 = Sunday through 6 = Saturday.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('employee_day_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('shift_start');
            $table->time('shift_end');
            $table->timestamps();

            $table->unique(['employee_id', 'day_of_week']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('employee_day_shifts');
    }
};
