<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('daily_basic_rate', 12, 2)->default(505.00);
            $table->unsignedTinyInteger('standard_working_days_per_month')->default(26);
            $table->decimal('overtime_multiplier', 4, 2)->default(1.25);
            $table->decimal('night_diff_multiplier', 4, 2)->default(0.10);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('release_date');
            $table->unsignedTinyInteger('minimum_months')->default(1);
            $table->json('included_earnings')->default(new \Illuminate\Database\Query\Expression("(JSON_ARRAY('BASIC'))"));
            $table->json('employment_types_included')->default(new \Illuminate\Database\Query\Expression("(JSON_ARRAY('regular','probationary','fixed_term','seasonal'))"));
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('payroll_settings');
    }
};
