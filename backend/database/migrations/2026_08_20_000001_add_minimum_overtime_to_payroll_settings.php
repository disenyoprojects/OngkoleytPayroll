<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('minimum_overtime_minutes')->default(5)->after('unpaid_break_hours');
        });
    }

    public function down(): void {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropColumn('minimum_overtime_minutes');
        });
    }
};
