<?php

namespace Database\Seeders;

use App\Models\PayrollSetting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder {
    public function run(): void {
        $this->call([
            BranchSeeder::class,
            EmployeeSeeder::class,
        ]);
        PayrollSetting::current();
    }
}
