<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\Seeder;

/**
 * One-time import of the real store roster (CURRENT-EMPLOYEES).
 * Idempotent: re-running updates existing rows by employee_code rather than
 * duplicating. Demo PIN 1234 for everyone — replace per-employee before use.
 */
class ImportedEmployeeSeeder extends Seeder {
    // [code, full name, short name, role, branch]
    private const ROSTER = [
        // MABINI
        ['EMP-0001', 'Diosana P. Apaga', 'Diosana', 'Store Supervisor', 'Mabini'],
        ['EMP-0002', 'Ramon S. Moquerio Jr.', 'Ramon', 'Team Leader', 'Mabini'],
        ['EMP-0003', 'Marjory J. Arellano', 'Marjory', 'Team Leader-Counter', 'Mabini'],
        ['EMP-0004', 'Nitz G. Neri', 'Nitz', 'Training Coordinator', 'Mabini'],
        ['EMP-0005', 'Michelle O. Pabua', 'Michelle', 'Store Supervisor', 'Mabini'],
        ['EMP-0006', 'Marjorie B. Ped', 'Marjorie', 'Counter Staff', 'Mabini'],
        ['EMP-0007', 'Kendrick C. Allavado', 'Kendrick', 'Store Staff', 'Mabini'],
        ['EMP-0008', 'Jhanree CJ L. Mote', 'Jhanree', 'Store Staff', 'Mabini'],
        ['EMP-0009', 'Ronald M. Catigum', 'Ronald', 'Store Staff', 'Mabini'],
        ['EMP-0010', 'Jona Nicole S. Galvez', 'Jona', 'Store Staff', 'Mabini'],
        ['EMP-0011', 'Mhelvin Cariño', 'Mhelvin', 'Store Staff', 'Mabini'],

        // ADMIN OFFICE
        ['EMP-0012', 'Ruby Rose K. Anudon', 'Ruby', 'Admin Manager', 'Admin Office'],
        ['EMP-0013', 'Kate Ashley T. De Guzman', 'Kate', 'Admin Supervisor', 'Admin Office'],
        ['EMP-0014', 'Shahhannie Faye B. Tumaling', 'Shahhannie', 'Admin Officer', 'Admin Office'],
        ['EMP-0015', 'Melanie P. Sinong', 'Melanie', 'Admin Officer', 'Admin Office'],

        // DIEGO
        ['EMP-0016', 'Christopher T. Navarro', 'Christopher', 'Team Leader', 'Diego'],
        ['EMP-0017', 'Lailanie E. Tongkilly', 'Lailanie', 'Store Staff', 'Diego'],

        // BONIFACIO
        ['EMP-0018', 'Leyward C. Binay-an', 'Leyward', 'Team Leader', 'Bonifacio'],
        ['EMP-0019', 'Raydel C. Adona', 'Raydel', 'Store Staff', 'Bonifacio'],

        // KANTO CRAVING/BREW
        ['EMP-0020', 'Vangeline R. Alina', 'Vangeline', 'Staff', 'Kanto Craving/Brew'],
        ['EMP-0021', 'Marichu P. Bacalan', 'Marichu', 'Staff', 'Kanto Craving/Brew'],
        ['EMP-0022', 'Rommel Licaros', 'Rommel', 'Staff', 'Kanto Craving/Brew'],
        ['EMP-0023', 'Mirasol M. Soriano', 'Mirasol', 'Supervisor', 'Kanto Craving/Brew'],
        ['EMP-0024', 'Aron John M. Oliva', 'Aron', 'Barista', 'Kanto Craving/Brew'],
        ['EMP-0025', 'Janalie B. Mateo', 'Janalie', 'Staff', 'Kanto Craving/Brew'],
        ['EMP-0026', 'Hannah M. Ortiz', 'Hannah', 'Staff', 'Kanto Craving/Brew'],

        // BODEGA
        ['EMP-0027', 'Ian M. Apelado', 'Ian', 'Company Driver', 'Bodega'],
        ['EMP-0028', 'John Michael O. Lim Jr.', 'John', 'Operations Manager', 'Bodega'],
        ['EMP-0029', 'Dominador C. Daos Jr.', 'Dominador', 'Sales Agent (Sir Matt)', 'Bodega'],
        ['EMP-0030', 'Rodney F. Dela Peña', 'Rodney', 'Sales Agent (Sir Bogs)', 'Bodega'],
        ['EMP-0031', 'Arbilcan L De Guzman', 'Arbilcan', 'Warehouse Staff', 'Bodega'],
        ['EMP-0032', 'Ian Lesther B. Tabon', 'Ian', 'Warehouse Staff', 'Bodega'],
    ];

    public function run(): void {
        $branches = [];
        foreach (['Mabini', 'Admin Office', 'Diego', 'Bonifacio', 'Kanto Craving/Brew', 'Bodega'] as $name) {
            $branches[$name] = Branch::firstOrCreate(['name' => $name]);
        }

        foreach (self::ROSTER as [$code, $full, $short, $role, $branchName]) {
            $employee = Employee::firstOrNew(['employee_code' => $code]);
            $employee->fill([
                'full_name' => $full,
                'short_name' => $short,
                'role' => $role,
                'branch_id' => $branches[$branchName]->id,
                'employment_type' => 'regular',
                'shift_start' => '08:00:00',
                'shift_end' => '17:00:00',
                'hire_date' => now()->toDateString(),
                'daily_basic_rate' => null,
            ]);
            $employee->pin = '1234';
            $employee->save();
        }
    }
}
