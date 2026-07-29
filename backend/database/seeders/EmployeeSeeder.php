<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder {
    private const ROSTER = [
        ['code' => 'ONG-1001', 'short' => 'Joshua Bacuyag', 'full' => 'Joshua Bacuyag', 'role' => 'Operations Manager', 'branch' => 'General Luna', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1002', 'short' => 'Kristen', 'full' => 'Kristen Nicole Urbano', 'role' => 'Brand Manager', 'branch' => 'General Luna', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1003', 'short' => 'Kyle', 'full' => 'Kyle Antazo', 'role' => 'Marketing & Investor Relations Officer', 'branch' => 'General Luna', 'type' => 'probationary', 'hireMonth' => 5, 'lastMonth' => 12],
        ['code' => 'ONG-1004', 'short' => 'Khirby', 'full' => 'Khirby Domingo', 'role' => 'Driver (Manual, knows Baguio & La Trinidad roads)', 'branch' => 'La Trinidad', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1005', 'short' => 'Summer', 'full' => 'Summer Dizon', 'role' => 'Barista (Female)', 'branch' => 'Bonifacio', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1006', 'short' => 'Jhon', 'full' => 'Jhon Alonzo', 'role' => 'Male Counter Staff', 'branch' => 'Diego Silang', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1007', 'short' => 'Jhon', 'full' => 'Jhon Ancheta', 'role' => 'Male Kitchen Staff', 'branch' => 'Diego Silang', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 9],
        ['code' => 'ONG-1008', 'short' => 'Jamiel Miclat', 'full' => 'Jamiel Miclat', 'role' => 'Marketing & Investor Relations Manager', 'branch' => 'General Luna', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1009', 'short' => 'Jhen', 'full' => 'Jhen Aquino', 'role' => 'Female Kitchen Staff', 'branch' => 'Bonifacio', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1010', 'short' => 'Jhen', 'full' => 'Jhen Navarro', 'role' => 'Female Counter Staff', 'branch' => 'La Trinidad', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1011', 'short' => 'Michiko', 'full' => 'Michiko Reyes', 'role' => 'Sales Agent (Knows how to drive)', 'branch' => 'La Union', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1012', 'short' => 'Michiko', 'full' => 'Michiko Ramos', 'role' => 'Market Coordinator', 'branch' => 'La Union', 'type' => 'seasonal', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1013', 'short' => 'Glenn', 'full' => 'Glenn Aspiras', 'role' => 'Male Kitchen Staff', 'branch' => 'Bonifacio', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1014', 'short' => 'Rhea', 'full' => 'Rhea Ibarra', 'role' => 'Female Counter Staff', 'branch' => 'Diego Silang', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
    ];

    public function run(): void {
        $year = now()->year;
        foreach (self::ROSTER as $row) {
            $branch = Branch::where('name', $row['branch'])->firstOrFail();
            $employee = Employee::firstOrNew(['employee_code' => $row['code']]);
            $employee->fill([
                'full_name' => $row['full'],
                'short_name' => $row['short'],
                'role' => $row['role'],
                'branch_id' => $branch->id,
                'employment_type' => $row['type'],
                'hire_date' => sprintf('%d-%02d-01', $year, $row['hireMonth']),
                'resignation_date' => $row['lastMonth'] < 12 ? sprintf('%d-%02d-28', $year, $row['lastMonth']) : null,
            ]);
            $employee->save();
        }
    }
}
