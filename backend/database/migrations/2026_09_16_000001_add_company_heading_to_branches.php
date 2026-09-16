<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The name and address printed at the head of a payslip, per branch.
 *
 * It was one hardcoded pair in four files, so every payslip read WANG
 * CHOCOLATE INC. — including those of the Kanto Cravings staff, who work for a
 * different business at a different address. The heading belongs to the branch
 * the employee works at, not to the codebase.
 *
 * Both columns are nullable and a branch that leaves them null keeps the old
 * heading, so nothing changes for the Ongkoleyt branches.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('name');
            $table->string('company_address')->nullable()->after('company_name');
        });

        // The heading the office asked for, applied to whatever the Kanto
        // branch ended up being called. Matching on the name rather than an id
        // because branches are seeded per environment.
        DB::table('branches')->where('name', 'like', '%Kanto%')->update([
            'company_name' => 'KANTO CRAVINGS',
            'company_address' => 'Mabini Arcade, Lower Mabini St., Baguio City',
        ]);
    }

    public function down(): void {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'company_address']);
        });
    }
};
