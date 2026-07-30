<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->decimal('late_penalty_amount', 8, 2)->default(75.00)->after('unpaid_break_hours');
        });
    }

    public function down(): void {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropColumn('late_penalty_amount');
        });
    }
};
