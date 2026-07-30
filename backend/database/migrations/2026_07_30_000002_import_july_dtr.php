<?php

use App\Models\Employee;
use Database\Seeders\JulyDtrSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Load the July 1–15 DTR on deploy. Guarded so it only runs where the
     * real roster is present (production) — on a fresh/test database no
     * roster employees exist, so this is a no-op and never pollutes tests.
     */
    public function up(): void {
        if (Employee::withTrashed()->where('employee_code', 'EMP-0001')->exists()) {
            (new JulyDtrSeeder())->run();
        }
    }

    public function down(): void {
        // Data import; nothing to roll back.
    }
};
