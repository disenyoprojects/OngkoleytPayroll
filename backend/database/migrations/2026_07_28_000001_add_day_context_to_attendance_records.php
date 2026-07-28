<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->time('shift_end')->default('17:00:00')->after('shift_start');
            $table->string('holiday_type')->nullable()->after('shift_end');
            $table->boolean('is_rest_day')->default(false)->after('holiday_type');
            $table->string('absence_type')->nullable()->after('is_rest_day');
            $table->time('break_out')->nullable()->after('absence_type');
            $table->time('break_in')->nullable()->after('break_out');
        });
    }

    public function down(): void {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['shift_end', 'holiday_type', 'is_rest_day', 'absence_type', 'break_out', 'break_in']);
        });
    }
};
