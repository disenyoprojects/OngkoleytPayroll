<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder {
    public function run(): void {
        foreach (['General Luna', 'Bonifacio', 'Diego Silang', 'La Trinidad', 'La Union'] as $name) {
            Branch::firstOrCreate(['name' => $name]);
        }
    }
}
