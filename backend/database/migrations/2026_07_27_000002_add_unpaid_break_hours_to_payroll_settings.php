<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->decimal('unpaid_break_hours', 4, 2)->default(1.00)->after('night_diff_multiplier');
        });
    }

    public function down(): void {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropColumn('unpaid_break_hours');
        });
    }
};
