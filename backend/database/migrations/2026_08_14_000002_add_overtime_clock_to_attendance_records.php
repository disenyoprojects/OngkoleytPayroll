<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second clock in/out pair for overtime. Staff clock out when the shift
 * ends, wait for the delivery truck, then clock back in to unload — the gap
 * in between is not worked and must not be paid, which a single pair
 * (clock_in..clock_out) could not express.
 *
 * The day now holds three pairs, matching the paper timesheet's columns:
 *   Time 1    clock_in  .. break_out
 *   Time 2    break_in  .. clock_out
 *   Overtime  ot_in     .. ot_out
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->time('ot_in')->nullable()->after('break_in');
            $table->time('ot_out')->nullable()->after('ot_in');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['ot_in', 'ot_out']);
        });
    }
};
