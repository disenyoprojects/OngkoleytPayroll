# Employee Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins add, edit, and (safely) delete employees from the Admin UI, and let each employee's daily basic rate override the global Payroll Settings rate.

**Architecture:** A new `EmployeeController` under the existing `auth:sanctum` admin route group exposes list/create/update/delete plus a small branch-list endpoint. `AttendancePayCalculator::compute()` gains an optional per-employee rate override that every existing call site passes through. A new `EmployeesView.jsx` React tab drives the CRUD UI, following the existing admin-view conventions.

**Tech Stack:** Laravel 11, PHPUnit, MySQL (local/dev) + Postgres (production), React 18, Vite, Axios.

## Global Constraints

- Money values stored as `decimal` and computed server-side only; the frontend displays what the API returns.
- Every change to a `daily_basic_rate` requires a non-empty `reason` and writes exactly one `audit_logs` row. No silent pay changes.
- Employment types are exactly: `regular`, `probationary`, `fixed_term`, `seasonal`.
- All API routes are under `/api`, JSON only, protected by `auth:sanctum` for admin routes.
- Every backend task follows TDD: write the failing PHPUnit test, run it (fail), implement, run again (pass), commit.
- Tests run against the MySQL dev DB via `RefreshDatabase` (matches existing tests); production is Postgres, so any raw SQL must be driver-aware.
- `daily_basic_rate` is nullable on `employees`: `NULL` = use the global `PayrollSetting.daily_basic_rate`.

---

## Task 1: Schema + Employee model — `daily_basic_rate` column and `employee` audit type

**Files:**
- Create: `backend/database/migrations/2026_07_23_100001_add_daily_basic_rate_to_employees_table.php`
- Create: `backend/database/migrations/2026_07_23_100002_add_employee_type_to_audit_logs.php`
- Modify: `backend/app/Models/Employee.php`
- Test: `backend/tests/Unit/EmployeeDailyRateTest.php`

**Interfaces:**
- Consumes: existing `Employee` model, `audit_logs` table (enum `type` currently `['attendance','13th_month']`).
- Produces: `Employee->daily_basic_rate` (nullable, cast `decimal:2` → string|null), and an `audit_logs.type` value `'employee'` accepted by inserts.

- [ ] **Step 1: Write the failing test**

`backend/tests/Unit/EmployeeDailyRateTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeDailyRateTest extends TestCase {
    use RefreshDatabase;

    public function test_daily_basic_rate_defaults_to_null(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->assertNull($employee->fresh()->daily_basic_rate);
    }

    public function test_daily_basic_rate_persists_when_set(): void {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'daily_basic_rate' => 620.50,
        ]);

        $this->assertSame('620.50', $employee->fresh()->daily_basic_rate);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=EmployeeDailyRateTest`
Expected: FAIL — `Unknown column 'daily_basic_rate'` (or mass-assignment/`SQLSTATE` error).

- [ ] **Step 3: Write the column migration**

`backend/database/migrations/2026_07_23_100001_add_daily_basic_rate_to_employees_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('daily_basic_rate', 10, 2)->nullable()->after('employment_type');
        });
    }
    public function down(): void {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('daily_basic_rate');
        });
    }
};
```

- [ ] **Step 4: Write the driver-aware audit-log enum migration**

`backend/database/migrations/2026_07_23_100002_add_employee_type_to_audit_logs.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE audit_logs MODIFY COLUMN type ENUM('attendance','13th_month','employee') NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_type_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_type_check CHECK (type::text = ANY (ARRAY['attendance','13th_month','employee']::text[]))");
        }
        // sqlite and others: enum is emulated as text; no constraint change needed.
    }

    public function down(): void {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE audit_logs MODIFY COLUMN type ENUM('attendance','13th_month') NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_type_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_type_check CHECK (type::text = ANY (ARRAY['attendance','13th_month']::text[]))");
        }
    }
};
```

- [ ] **Step 5: Add the column to the Employee model**

In `backend/app/Models/Employee.php`, add `daily_basic_rate` to `$fillable` and cast it. The `$fillable` array becomes:

```php
    protected $fillable = [
        'employee_code', 'full_name', 'short_name', 'role', 'branch_id',
        'employment_type', 'daily_basic_rate', 'hire_date', 'resignation_date', 'pin_hash',
    ];
```

And add `'daily_basic_rate' => 'decimal:2',` to `$casts`:

```php
    protected $casts = [
        'hire_date' => 'date',
        'resignation_date' => 'date',
        'daily_basic_rate' => 'decimal:2',
    ];
```

- [ ] **Step 6: Run migrations and the test**

Run: `cd backend && php artisan migrate && php artisan test --filter=EmployeeDailyRateTest`
Expected: migrations run clean; `Tests: 2 passed`.

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/2026_07_23_100001_add_daily_basic_rate_to_employees_table.php backend/database/migrations/2026_07_23_100002_add_employee_type_to_audit_logs.php backend/app/Models/Employee.php backend/tests/Unit/EmployeeDailyRateTest.php
git commit -m "feat: add per-employee daily_basic_rate column and employee audit type"
```

---

## Task 2: Per-employee rate override in `AttendancePayCalculator` + all call sites

**Files:**
- Modify: `backend/app/Services/AttendancePayCalculator.php`
- Modify: `backend/app/Http/Controllers/Admin/PayrollController.php`
- Modify: `backend/app/Http/Controllers/Admin/PayrollExportController.php`
- Modify: `backend/app/Http/Controllers/Admin/PayrollPdfController.php`
- Modify: `backend/app/Http/Controllers/Admin/AttendanceDashboardController.php`
- Modify: `backend/app/Services/ThirteenthMonthCalculator.php`
- Modify: `backend/app/Http/Controllers/Kiosk/StaffDashboardController.php`
- Test: `backend/tests/Unit/AttendancePayCalculatorOverrideTest.php`

**Interfaces:**
- Consumes: `Employee->daily_basic_rate` from Task 1.
- Produces: `AttendancePayCalculator::compute(?string $clockIn, ?string $clockOut, PayrollSetting $settings, ?float $dailyRateOverride = null): ?array` — when `$dailyRateOverride` is non-null it replaces `$settings->daily_basic_rate`; otherwise behavior is unchanged.

- [ ] **Step 1: Write the failing test**

`backend/tests/Unit/AttendancePayCalculatorOverrideTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Tests\TestCase;

class AttendancePayCalculatorOverrideTest extends TestCase {
    private function settings(): PayrollSetting {
        return new PayrollSetting([
            'daily_basic_rate' => 505.00,
            'overtime_multiplier' => 1.25,
            'night_diff_multiplier' => 0.10,
        ]);
    }

    public function test_uses_global_rate_when_override_is_null(): void {
        $calc = new AttendancePayCalculator();

        $pay = $calc->compute('08:00', '16:00', $this->settings(), null);

        // 8 regular hours at 505/8 = 63.125/hr => 505.00 basic
        $this->assertSame(505.00, $pay['basic']);
    }

    public function test_uses_override_rate_when_provided(): void {
        $calc = new AttendancePayCalculator();

        $pay = $calc->compute('08:00', '16:00', $this->settings(), 800.00);

        // 8 regular hours at 800/8 = 100/hr => 800.00 basic
        $this->assertSame(800.00, $pay['basic']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=AttendancePayCalculatorOverrideTest`
Expected: FAIL — `test_uses_override_rate_when_provided` fails (override ignored; basic is 505.00) because `compute()` has no 4th parameter yet.

- [ ] **Step 3: Add the override parameter to the calculator**

In `backend/app/Services/AttendancePayCalculator.php`, change the signature and the rate line. Replace:

```php
    public function compute(?string $clockIn, ?string $clockOut, PayrollSetting $settings): ?array {
```

with:

```php
    public function compute(?string $clockIn, ?string $clockOut, PayrollSetting $settings, ?float $dailyRateOverride = null): ?array {
```

and replace:

```php
        $hourlyRate = (float) $settings->daily_basic_rate / 8;
```

with:

```php
        $dailyRate = $dailyRateOverride ?? (float) $settings->daily_basic_rate;
        $hourlyRate = $dailyRate / 8;
```

- [ ] **Step 4: Run the calculator test to verify it passes**

Run: `cd backend && php artisan test --filter=AttendancePayCalculatorOverrideTest`
Expected: `Tests: 2 passed`.

- [ ] **Step 5: Pass the override at each call site**

Define a helper expression used below: for a loaded `$employee`, the override argument is
`$employee->daily_basic_rate === null ? null : (float) $employee->daily_basic_rate`.

**5a.** `backend/app/Http/Controllers/Admin/PayrollController.php` — in `daily()`, replace:

```php
                $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings);
```

with:

```php
                $rate = $record->employee->daily_basic_rate === null ? null : (float) $record->employee->daily_basic_rate;
                $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings, $rate);
```

In `weekly()`, replace:

```php
            $pays = $employeeRecords->map(fn (AttendanceRecord $r) => $this->calculator->compute($r->clock_in, $r->clock_out, $settings));
```

with:

```php
            $rate = $employeeRecords->first()->employee->daily_basic_rate === null ? null : (float) $employeeRecords->first()->employee->daily_basic_rate;
            $pays = $employeeRecords->map(fn (AttendanceRecord $r) => $this->calculator->compute($r->clock_in, $r->clock_out, $settings, $rate));
```

**5b.** `backend/app/Http/Controllers/Admin/AttendanceDashboardController.php` — in `today()`, replace:

```php
            $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings);
```

with:

```php
            $rate = $record->employee->daily_basic_rate === null ? null : (float) $record->employee->daily_basic_rate;
            $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings, $rate);
```

**5c.** `backend/app/Http/Controllers/Admin/PayrollExportController.php` — inside the `foreach ($records as $record)` loop, replace:

```php
                $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings);
```

with:

```php
                $rate = $record->employee->daily_basic_rate === null ? null : (float) $record->employee->daily_basic_rate;
                $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings, $rate);
```

**5d.** `backend/app/Http/Controllers/Admin/PayrollPdfController.php` — replace:

```php
        $rows = $records->map(fn (AttendanceRecord $record) => [
            'employee' => $record->employee,
            'pay' => $this->calculator->compute($record->clock_in, $record->clock_out, $settings),
        ]);
```

with:

```php
        $rows = $records->map(fn (AttendanceRecord $record) => [
            'employee' => $record->employee,
            'pay' => $this->calculator->compute(
                $record->clock_in,
                $record->clock_out,
                $settings,
                $record->employee->daily_basic_rate === null ? null : (float) $record->employee->daily_basic_rate
            ),
        ]);
```

**5e.** `backend/app/Services/ThirteenthMonthCalculator.php` — in `monthlyBreakdown()`, replace:

```php
                    $pay = $this->payCalculator->compute($record->clock_in, $record->clock_out, $settings);
```

with:

```php
                    $rate = $employee->daily_basic_rate === null ? null : (float) $employee->daily_basic_rate;
                    $pay = $this->payCalculator->compute($record->clock_in, $record->clock_out, $settings, $rate);
```

**5f.** `backend/app/Http/Controllers/Kiosk/StaffDashboardController.php` — in `show()`, replace:

```php
        $todayPay = $todayRecord ? $this->payCalculator->compute($todayRecord->clock_in, $todayRecord->clock_out, $settings) : null;
```

with:

```php
        $rate = $employee->daily_basic_rate === null ? null : (float) $employee->daily_basic_rate;
        $todayPay = $todayRecord ? $this->payCalculator->compute($todayRecord->clock_in, $todayRecord->clock_out, $settings, $rate) : null;
```

and replace:

```php
        $weekPays = $weekRecords->map(fn (AttendanceRecord $r) => $this->payCalculator->compute($r->clock_in, $r->clock_out, $settings));
```

with:

```php
        $weekPays = $weekRecords->map(fn (AttendanceRecord $r) => $this->payCalculator->compute($r->clock_in, $r->clock_out, $settings, $rate));
```

- [ ] **Step 6: Run the full backend suite to confirm no regressions**

Run: `cd backend && php artisan test`
Expected: all tests pass (existing payroll/13th-month/staff-dashboard tests still green because the override defaults to null everywhere they don't set a rate).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/AttendancePayCalculator.php backend/app/Http/Controllers/Admin/PayrollController.php backend/app/Http/Controllers/Admin/PayrollExportController.php backend/app/Http/Controllers/Admin/PayrollPdfController.php backend/app/Http/Controllers/Admin/AttendanceDashboardController.php backend/app/Services/ThirteenthMonthCalculator.php backend/app/Http/Controllers/Kiosk/StaffDashboardController.php backend/tests/Unit/AttendancePayCalculatorOverrideTest.php
git commit -m "feat: apply per-employee daily rate override across pay calculations"
```

---

## Task 3: `EmployeeController` — list, branches, and create

**Files:**
- Create: `backend/app/Http/Controllers/Admin/EmployeeController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/EmployeeControllerTest.php`

**Interfaces:**
- Consumes: `Employee`, `Branch`, `AuditLog` models; `auth:sanctum` middleware group in `routes/api.php`.
- Produces: `GET /api/admin/employees`, `GET /api/admin/branches`, `POST /api/admin/employees`. Create validates the fields listed below; when `daily_basic_rate` is provided it requires `reason` and writes an `audit_logs` row (`type: employee`, `action: rate_override_set`).

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/EmployeeControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeControllerTest extends TestCase {
    use RefreshDatabase;

    private function payload(Branch $branch, array $overrides = []): array {
        return array_merge([
            'employee_code' => 'ONG-5001',
            'full_name' => 'Test Person',
            'short_name' => 'Test',
            'role' => 'Barista',
            'branch_id' => $branch->id,
            'employment_type' => 'regular',
            'hire_date' => '2026-01-01',
            'pin' => '1234',
        ], $overrides);
    }

    public function test_list_returns_employees_with_branch(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->getJson('/api/admin/employees')
            ->assertOk()
            ->assertJsonPath('0.id', $employee->id)
            ->assertJsonPath('0.branch.id', $employee->branch_id);
    }

    public function test_branches_endpoint_returns_id_and_name(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create(['name' => 'Bonifacio']);

        $this->actingAs($admin)->getJson('/api/admin/branches')
            ->assertOk()
            ->assertJsonPath('0.name', 'Bonifacio');
    }

    public function test_create_persists_employee_and_hashes_pin(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch));

        $response->assertCreated();
        $employee = Employee::where('employee_code', 'ONG-5001')->firstOrFail();
        $this->assertTrue($employee->verifyPin('1234'));
    }

    public function test_create_rejects_duplicate_employee_code(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();
        Employee::factory()->for($branch)->create(['employee_code' => 'ONG-5001']);

        $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch))
            ->assertStatus(422);
    }

    public function test_create_with_daily_rate_requires_reason(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch, [
            'daily_basic_rate' => 620,
        ]))->assertStatus(422);
    }

    public function test_create_with_daily_rate_and_reason_writes_audit_log(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($admin)->postJson('/api/admin/employees', $this->payload($branch, [
            'daily_basic_rate' => 620,
            'reason' => 'Shift lead premium',
        ]))->assertCreated();

        $this->assertSame(1, AuditLog::where('type', 'employee')->where('action', 'rate_override_set')->count());
    }

    public function test_endpoints_require_authentication(): void {
        $this->getJson('/api/admin/employees')->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=EmployeeControllerTest`
Expected: FAIL — route `/api/admin/employees` not found (404), so assertions fail.

- [ ] **Step 3: Write the controller (list + branches + create)**

`backend/app/Http/Controllers/Admin/EmployeeController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller {
    public function index() {
        return response()->json(
            Employee::with('branch')->orderBy('short_name')->get()
        );
    }

    public function branches() {
        return response()->json(
            Branch::orderBy('name')->get(['id', 'name'])
        );
    }

    public function store(Request $request) {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'unique:employees,employee_code'],
            'full_name' => ['required', 'string'],
            'short_name' => ['required', 'string'],
            'role' => ['required', 'string'],
            'branch_id' => ['required', 'exists:branches,id'],
            'employment_type' => ['required', Rule::in(['regular', 'probationary', 'fixed_term', 'seasonal'])],
            'hire_date' => ['required', 'date'],
            'resignation_date' => ['nullable', 'date'],
            'pin' => ['required', 'string', 'size:4'],
            'daily_basic_rate' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string'],
        ]);

        if (array_key_exists('daily_basic_rate', $data) && $data['daily_basic_rate'] !== null
            && empty($data['reason'])) {
            return response()->json([
                'message' => 'A reason is required when setting a daily basic rate.',
                'errors' => ['reason' => ['A reason is required when setting a daily basic rate.']],
            ], 422);
        }

        $employee = new Employee([
            'employee_code' => $data['employee_code'],
            'full_name' => $data['full_name'],
            'short_name' => $data['short_name'],
            'role' => $data['role'],
            'branch_id' => $data['branch_id'],
            'employment_type' => $data['employment_type'],
            'hire_date' => $data['hire_date'],
            'resignation_date' => $data['resignation_date'] ?? null,
            'daily_basic_rate' => $data['daily_basic_rate'] ?? null,
        ]);
        $employee->pin = $data['pin'];
        $employee->save();

        if (($data['daily_basic_rate'] ?? null) !== null) {
            AuditLog::create([
                'type' => 'employee',
                'employee_id' => $employee->id,
                'performed_by' => $request->user()->id,
                'action' => 'rate_override_set',
                'detail' => "Daily basic rate set to {$data['daily_basic_rate']} on create",
                'new_amount' => $data['daily_basic_rate'],
                'reason' => $data['reason'],
            ]);
        }

        return response()->json($employee->load('branch'), 201);
    }
}
```

- [ ] **Step 4: Wire the routes**

In `backend/routes/api.php`, add the import near the other admin controller imports:

```php
use App\Http\Controllers\Admin\EmployeeController;
```

Inside the existing `Route::middleware('auth:sanctum')->group(function () { ... })` block, add:

```php
    Route::get('/admin/employees', [EmployeeController::class, 'index']);
    Route::get('/admin/branches', [EmployeeController::class, 'branches']);
    Route::post('/admin/employees', [EmployeeController::class, 'store']);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=EmployeeControllerTest`
Expected: `Tests: 7 passed`.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Admin/EmployeeController.php backend/routes/api.php backend/tests/Feature/EmployeeControllerTest.php
git commit -m "feat: add employee list, branch list, and create endpoints"
```

---

## Task 4: `EmployeeController` — update (conditional reason + audit)

**Files:**
- Modify: `backend/app/Http/Controllers/Admin/EmployeeController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/EmployeeControllerTest.php`

**Interfaces:**
- Consumes: `EmployeeController` and routes from Task 3.
- Produces: `PUT /api/admin/employees/{employee}`. `pin` optional (blank keeps current). `reason` required only when `daily_basic_rate` changes; a change writes an `audit_logs` row (`action: rate_override_changed`) with `old_amount`/`new_amount`.

- [ ] **Step 1: Write the failing test**

Append these methods to `backend/tests/Feature/EmployeeControllerTest.php` (inside the class):

```php
    public function test_update_without_rate_change_needs_no_reason(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['role' => 'Barista']);

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'short_name' => $employee->short_name,
            'role' => 'Head Barista',
            'branch_id' => $employee->branch_id,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
        ])->assertOk();

        $this->assertSame('Head Barista', $employee->fresh()->role);
    }

    public function test_update_changing_rate_requires_reason(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'short_name' => $employee->short_name,
            'role' => $employee->role,
            'branch_id' => $employee->branch_id,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
            'daily_basic_rate' => 700,
        ])->assertStatus(422);
    }

    public function test_update_changing_rate_with_reason_writes_audit_log(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => 500]);

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'short_name' => $employee->short_name,
            'role' => $employee->role,
            'branch_id' => $employee->branch_id,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
            'daily_basic_rate' => 700,
            'reason' => 'Promotion',
        ])->assertOk();

        $this->assertSame('700.00', $employee->fresh()->daily_basic_rate);
        $log = AuditLog::where('type', 'employee')->where('action', 'rate_override_changed')->firstOrFail();
        $this->assertSame('500.00', $log->old_amount);
        $this->assertSame('700.00', $log->new_amount);
    }

    public function test_update_with_blank_pin_keeps_existing_pin(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->putJson("/api/admin/employees/{$employee->id}", [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'short_name' => $employee->short_name,
            'role' => $employee->role,
            'branch_id' => $employee->branch_id,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
            'pin' => '',
        ])->assertOk();

        $this->assertTrue($employee->fresh()->verifyPin('1234'));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=EmployeeControllerTest`
Expected: FAIL — the 4 new tests fail with 404/405 (route `PUT /api/admin/employees/{employee}` not defined).

- [ ] **Step 3: Add the `update` method to the controller**

In `backend/app/Http/Controllers/Admin/EmployeeController.php`, add this method to the class:

```php
    public function update(Request $request, Employee $employee) {
        $data = $request->validate([
            'employee_code' => ['required', 'string', Rule::unique('employees', 'employee_code')->ignore($employee->id)],
            'full_name' => ['required', 'string'],
            'short_name' => ['required', 'string'],
            'role' => ['required', 'string'],
            'branch_id' => ['required', 'exists:branches,id'],
            'employment_type' => ['required', Rule::in(['regular', 'probationary', 'fixed_term', 'seasonal'])],
            'hire_date' => ['required', 'date'],
            'resignation_date' => ['nullable', 'date'],
            'pin' => ['nullable', 'string', 'size:4'],
            'daily_basic_rate' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string'],
        ]);

        $oldRate = $employee->daily_basic_rate === null ? null : (float) $employee->daily_basic_rate;
        $newRate = array_key_exists('daily_basic_rate', $data) && $data['daily_basic_rate'] !== null
            ? (float) $data['daily_basic_rate']
            : null;
        $rateChanged = $oldRate !== $newRate;

        if ($rateChanged && empty($data['reason'])) {
            return response()->json([
                'message' => 'A reason is required when changing the daily basic rate.',
                'errors' => ['reason' => ['A reason is required when changing the daily basic rate.']],
            ], 422);
        }

        $employee->fill([
            'employee_code' => $data['employee_code'],
            'full_name' => $data['full_name'],
            'short_name' => $data['short_name'],
            'role' => $data['role'],
            'branch_id' => $data['branch_id'],
            'employment_type' => $data['employment_type'],
            'hire_date' => $data['hire_date'],
            'resignation_date' => $data['resignation_date'] ?? null,
            'daily_basic_rate' => $newRate,
        ]);
        if (! empty($data['pin'])) {
            $employee->pin = $data['pin'];
        }
        $employee->save();

        if ($rateChanged) {
            AuditLog::create([
                'type' => 'employee',
                'employee_id' => $employee->id,
                'performed_by' => $request->user()->id,
                'action' => 'rate_override_changed',
                'detail' => sprintf('Daily basic rate changed from %s to %s',
                    $oldRate === null ? 'global' : number_format($oldRate, 2),
                    $newRate === null ? 'global' : number_format($newRate, 2)),
                'old_amount' => $oldRate,
                'new_amount' => $newRate,
                'reason' => $data['reason'],
            ]);
        }

        return response()->json($employee->load('branch'));
    }
```

- [ ] **Step 4: Wire the route**

In `backend/routes/api.php`, inside the `auth:sanctum` group, add:

```php
    Route::put('/admin/employees/{employee}', [EmployeeController::class, 'update']);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=EmployeeControllerTest`
Expected: `Tests: 11 passed`.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Admin/EmployeeController.php backend/routes/api.php backend/tests/Feature/EmployeeControllerTest.php
git commit -m "feat: add employee update with conditional reason and rate-change audit"
```

---

## Task 5: `EmployeeController` — delete (no-history guard + audit)

**Files:**
- Modify: `backend/app/Http/Controllers/Admin/EmployeeController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/EmployeeControllerTest.php`

**Interfaces:**
- Consumes: `EmployeeController` from Tasks 3-4; `Employee` relations `attendanceRecords()`, `earnings()`, `thirteenthMonthRecords()`.
- Produces: `DELETE /api/admin/employees/{employee}` — deletes only when the employee has no attendance/earning/13th-month records; otherwise `422`. A successful delete writes an `audit_logs` row (`action: deleted`).

- [ ] **Step 1: Write the failing test**

Append these methods to `backend/tests/Feature/EmployeeControllerTest.php`. Add `use App\Models\AttendanceRecord;` to the imports at the top of the file first.

```php
    public function test_delete_succeeds_when_employee_has_no_history(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        $this->actingAs($admin)->deleteJson("/api/admin/employees/{$employee->id}")
            ->assertOk();

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
        $this->assertSame(1, AuditLog::where('type', 'employee')->where('action', 'deleted')->count());
    }

    public function test_delete_is_refused_when_employee_has_attendance(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create();

        $this->actingAs($admin)->deleteJson("/api/admin/employees/{$employee->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=EmployeeControllerTest`
Expected: FAIL — the 2 new tests fail with 404/405 (route `DELETE /api/admin/employees/{employee}` not defined).

- [ ] **Step 3: Add the `destroy` method to the controller**

In `backend/app/Http/Controllers/Admin/EmployeeController.php`, add this method to the class:

```php
    public function destroy(Request $request, Employee $employee) {
        $hasHistory = $employee->attendanceRecords()->exists()
            || $employee->earnings()->exists()
            || $employee->thirteenthMonthRecords()->exists();

        if ($hasHistory) {
            return response()->json([
                'message' => 'This employee has attendance or payroll history and cannot be deleted. Set a resignation date instead.',
            ], 422);
        }

        AuditLog::create([
            'type' => 'employee',
            'employee_id' => $employee->id,
            'performed_by' => $request->user()->id,
            'action' => 'deleted',
            'detail' => "Deleted employee {$employee->employee_code} ({$employee->full_name})",
        ]);

        $employee->delete();

        return response()->json(['message' => 'Employee deleted.']);
    }
```

Note: the audit row is written before `delete()` so `employee_id` is still valid; the `audit_logs.employee_id` foreign key is nullable, so the row survives the employee's removal.

- [ ] **Step 4: Wire the route**

In `backend/routes/api.php`, inside the `auth:sanctum` group, add:

```php
    Route::delete('/admin/employees/{employee}', [EmployeeController::class, 'destroy']);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=EmployeeControllerTest`
Expected: `Tests: 13 passed`.

- [ ] **Step 6: Run the full backend suite**

Run: `cd backend && php artisan test`
Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/Admin/EmployeeController.php backend/routes/api.php backend/tests/Feature/EmployeeControllerTest.php
git commit -m "feat: add employee delete guarded by no-history check"
```

---

## Task 6: Frontend — `EmployeesView` tab (list, add/edit form, delete, hide-resigned)

**Files:**
- Create: `frontend/src/pages/admin/EmployeesView.jsx`
- Modify: `frontend/src/pages/admin/AdminApp.jsx`

**Interfaces:**
- Consumes: `apiClient` (`frontend/src/api/client.js`, already sends the admin bearer token); `Button`, `inputStyle` (`frontend/src/components/ui.jsx`); `formatPHP` (`frontend/src/theme.js`); API endpoints from Tasks 3-5.
- Produces: an "Employees" admin tab rendering the CRUD UI.

- [ ] **Step 1: Create the `EmployeesView` component**

`frontend/src/pages/admin/EmployeesView.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { Button, inputStyle } from "../../components/ui";
import { formatPHP } from "../../theme";

const EMPLOYMENT_TYPES = ["regular", "probationary", "fixed_term", "seasonal"];

const BLANK = {
  employee_code: "", full_name: "", short_name: "", role: "",
  branch_id: "", employment_type: "regular", hire_date: "",
  resignation_date: "", pin: "", daily_basic_rate: "",
};

function isResigned(emp) {
  return emp.resignation_date && new Date(emp.resignation_date) < new Date();
}

export default function EmployeesView() {
  const [employees, setEmployees] = useState([]);
  const [branches, setBranches] = useState([]);
  const [hideResigned, setHideResigned] = useState(true);
  const [editing, setEditing] = useState(null); // null = closed; {} = form state
  const [originalRate, setOriginalRate] = useState("");
  const [error, setError] = useState(null);

  function load() {
    apiClient.get("/api/admin/employees").then((res) => setEmployees(res.data));
  }

  useEffect(() => {
    load();
    apiClient.get("/api/admin/branches").then((res) => setBranches(res.data));
  }, []);

  function openAdd() {
    setError(null);
    setOriginalRate("");
    setEditing({ ...BLANK, id: null });
  }

  function openEdit(emp) {
    setError(null);
    const rate = emp.daily_basic_rate == null ? "" : String(emp.daily_basic_rate);
    setOriginalRate(rate);
    setEditing({
      id: emp.id,
      employee_code: emp.employee_code,
      full_name: emp.full_name,
      short_name: emp.short_name,
      role: emp.role,
      branch_id: emp.branch_id,
      employment_type: emp.employment_type,
      hire_date: emp.hire_date ? String(emp.hire_date).slice(0, 10) : "",
      resignation_date: emp.resignation_date ? String(emp.resignation_date).slice(0, 10) : "",
      pin: "",
      daily_basic_rate: rate,
      reason: "",
    });
  }

  function set(field, value) {
    setEditing((e) => ({ ...e, [field]: value }));
  }

  const rateChanged = editing && String(editing.daily_basic_rate) !== String(originalRate);

  async function save() {
    setError(null);
    const payload = { ...editing };
    if (payload.daily_basic_rate === "") payload.daily_basic_rate = null;
    if (payload.resignation_date === "") payload.resignation_date = null;
    if (!payload.pin) delete payload.pin;
    try {
      if (editing.id) {
        await apiClient.put(`/api/admin/employees/${editing.id}`, payload);
      } else {
        await apiClient.post("/api/admin/employees", payload);
      }
      setEditing(null);
      load();
    } catch (err) {
      setError(err.response?.data?.message || "Could not save employee.");
    }
  }

  async function remove(emp) {
    if (!window.confirm(`Delete ${emp.short_name}? This cannot be undone.`)) return;
    try {
      await apiClient.delete(`/api/admin/employees/${emp.id}`);
      load();
    } catch (err) {
      window.alert(err.response?.data?.message || "Could not delete employee.");
    }
  }

  const visible = hideResigned ? employees.filter((e) => !isResigned(e)) : employees;

  return (
    <div>
      <div style={{ display: "flex", alignItems: "center", gap: 16, marginBottom: 16 }}>
        <Button variant="gold" onClick={openAdd}>+ Add Employee</Button>
        <label style={{ fontSize: 13, display: "flex", gap: 6, alignItems: "center" }}>
          <input type="checkbox" checked={hideResigned} onChange={(e) => setHideResigned(e.target.checked)} />
          Hide resigned
        </label>
      </div>

      <table style={{ width: "100%", borderCollapse: "collapse", background: "white", border: "1px solid #E7DCC6", borderRadius: 10 }}>
        <thead>
          <tr style={{ textAlign: "left", fontSize: 12, color: "#7A6A57" }}>
            <th style={{ padding: 10 }}>Code</th>
            <th style={{ padding: 10 }}>Name</th>
            <th style={{ padding: 10 }}>Branch</th>
            <th style={{ padding: 10 }}>Type</th>
            <th style={{ padding: 10 }}>Daily Rate</th>
            <th style={{ padding: 10 }}>Status</th>
            <th style={{ padding: 10 }}></th>
          </tr>
        </thead>
        <tbody>
          {visible.map((emp) => (
            <tr key={emp.id} style={{ borderTop: "1px solid #E7DCC6", fontSize: 13 }}>
              <td style={{ padding: 10 }}>{emp.employee_code}</td>
              <td style={{ padding: 10 }}>{emp.full_name}</td>
              <td style={{ padding: 10 }}>{emp.branch?.name}</td>
              <td style={{ padding: 10 }}>{emp.employment_type.replace("_", " ")}</td>
              <td style={{ padding: 10 }}>{emp.daily_basic_rate == null ? "— (global)" : formatPHP(emp.daily_basic_rate)}</td>
              <td style={{ padding: 10 }}>{isResigned(emp) ? "Resigned" : "Active"}</td>
              <td style={{ padding: 10, textAlign: "right", whiteSpace: "nowrap" }}>
                <Button small onClick={() => openEdit(emp)}>Edit</Button>{" "}
                <Button small variant="danger" onClick={() => remove(emp)}>Delete</Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {editing && (
        <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 20, marginTop: 16, maxWidth: 520 }}>
          <h3 style={{ marginTop: 0 }}>{editing.id ? "Edit Employee" : "Add Employee"}</h3>
          {[
            ["employee_code", "Employee Code", "text"],
            ["full_name", "Full Name", "text"],
            ["short_name", "Short Name", "text"],
            ["role", "Role", "text"],
            ["hire_date", "Hire Date", "date"],
            ["resignation_date", "Resignation Date (optional)", "date"],
          ].map(([field, label, type]) => (
            <div key={field} style={{ marginBottom: 12 }}>
              <div style={{ fontSize: 12, marginBottom: 4 }}>{label}</div>
              <input type={type} value={editing[field]} onChange={(e) => set(field, e.target.value)} style={inputStyle} />
            </div>
          ))}
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>Branch</div>
            <select value={editing.branch_id} onChange={(e) => set("branch_id", e.target.value)} style={inputStyle}>
              <option value="">Select branch…</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>Employment Type</div>
            <select value={editing.employment_type} onChange={(e) => set("employment_type", e.target.value)} style={inputStyle}>
              {EMPLOYMENT_TYPES.map((t) => <option key={t} value={t}>{t.replace("_", " ")}</option>)}
            </select>
          </div>
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>PIN {editing.id ? "(leave blank to keep current)" : "(4 digits)"}</div>
            <input type="text" value={editing.pin} onChange={(e) => set("pin", e.target.value)} style={inputStyle} />
          </div>
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>Daily Basic Rate (₱) — blank = use global rate</div>
            <input type="number" step="0.01" value={editing.daily_basic_rate} onChange={(e) => set("daily_basic_rate", e.target.value)} style={inputStyle} />
          </div>
          {rateChanged && (
            <div style={{ marginBottom: 12 }}>
              <div style={{ fontSize: 12, marginBottom: 4, color: "#C1521F" }}>Reason (required — the daily rate changed)</div>
              <input type="text" value={editing.reason || ""} onChange={(e) => set("reason", e.target.value)} style={inputStyle} />
            </div>
          )}
          {error && <div style={{ color: "#C1521F", fontSize: 12, marginBottom: 12 }}>{error}</div>}
          <Button variant="gold" onClick={save}>Save</Button>{" "}
          <Button onClick={() => setEditing(null)}>Cancel</Button>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Confirm the `Button` props used already exist**

The `Button` component in `frontend/src/components/ui.jsx` already supports the `small` prop and a `danger` variant (rust outline), so no change is needed here. Quick confirm:

Run: `cd frontend && grep -n "danger\|small" src/components/ui.jsx`
Expected: matches for both. If (unexpectedly) `danger` is absent, add `danger: { background: "transparent", color: COLOR.rust, border: \`1px solid ${COLOR.rust}\` },` to the `variants` map.

- [ ] **Step 3: Add the Employees tab to `AdminApp`**

In `frontend/src/pages/admin/AdminApp.jsx`:

Add the import alongside the other view imports:

```jsx
import EmployeesView from "./EmployeesView";
```

Add the tab to the `TABS` array (after the `audit` entry):

```jsx
  ["employees", "Employees"],
```

Add the render branch alongside the others (after the audit line):

```jsx
      {tab === "employees" && <EmployeesView />}
```

- [ ] **Step 4: Build the frontend to confirm it compiles**

Run: `cd frontend && npm run build`
Expected: build succeeds with no errors, `dist/` is produced.

- [ ] **Step 5: Manual smoke test (local dev)**

Run backend and frontend dev servers (`cd backend && php artisan serve`, `cd frontend && npm run dev`), log in as admin, open the Employees tab, and confirm: the roster lists; "Add Employee" creates one; editing a rate reveals the required Reason box and saving without it shows the API error; deleting a seeded employee (who has no history) works while one referenced by attendance is refused with the guiding message; "Hide resigned" hides the resigned seed employee.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/admin/EmployeesView.jsx frontend/src/pages/admin/AdminApp.jsx frontend/src/components/ui.jsx
git commit -m "feat: add Employees admin tab with add/edit/delete and hide-resigned filter"
```

---

## Self-Review Notes

- **Spec coverage:** nullable `daily_basic_rate` column (Task 1) ✓; `employee` audit type, driver-aware (Task 1) ✓; calculator override + all 8 call sites (Task 2) ✓; list/branches/create with rate-audit (Task 3) ✓; update with conditional reason + audit (Task 4) ✓; delete with no-history guard + audit (Task 5) ✓; Employees tab, table, form, conditional reason box, hide-resigned filter, delete confirmation (Task 6) ✓; all tests from the spec's testing section are present across Tasks 1-5 ✓.
- **Auth in tests:** existing admin tests use `$this->actingAs($admin)`, which still authenticates under Sanctum for feature tests even though the login endpoint now returns a bearer token — so new tests follow the same proven pattern.
- **Decimal casts:** `daily_basic_rate` cast as `decimal:2` returns a string (e.g. `"700.00"`), which is why audit `old_amount`/`new_amount` assertions compare against `'500.00'`/`'700.00'` strings, and call sites coerce with `(float)` before passing to the calculator.
