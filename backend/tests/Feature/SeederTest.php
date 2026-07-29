<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase {
    use RefreshDatabase;

    public function test_database_seeder_creates_branches_and_employees(): void {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, Branch::count());
        $this->assertSame(14, Employee::count());

        $this->assertDatabaseHas('employees', ['employee_code' => 'ONG-1001']);
    }
}
