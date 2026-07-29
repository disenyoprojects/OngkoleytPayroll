# Per-Staff Attendance Log & Payslip Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins view any staff member's monthly attendance log, and generate a per-staff gross payslip for a 1–15 / 16–end / whole-month period, on screen and as a printable PDF.

**Architecture:** Two read-only admin features over existing data. A small `PayslipPeriod` helper resolves the date window from `month`+`period`. Two new controllers (`EmployeeAttendanceController`, `PayslipController`) reuse `AttendancePayCalculator::computeForRecord` to enrich records/lines with pay. The PDF reuses `barryvdh/laravel-dompdf` like the existing 13th-month payslip. Frontend adds a Log modal in the Employees tab and a Payslip view in the Payroll tab.

**Tech Stack:** Laravel 11, PHPUnit, MySQL (dev)/Postgres (prod), barryvdh/laravel-dompdf, React 18/Vite.

## Global Constraints

- Money computed server-side via `AttendancePayCalculator::computeForRecord($record, PayrollSetting::current())`. `daily_basic_rate` cast `decimal:2` returns a string|null.
- Payslip is **gross pay only** — no statutory deductions (SSS/PhilHealth/Pag-IBIG/tax).
- Period windows: `first` = day 01–15; `second` = day 16–last day of month; `whole` = day 01–last day.
- Employee route bindings for these features resolve **including trashed** (`->withTrashed()`), so separated staff still open.
- All routes under the existing `auth:sanctum` group.
- Every backend task follows TDD; tests run on MySQL via `RefreshDatabase`.
- Manila timezone is the app default (dates use `now()` Manila).

---

## Task 1: `PayslipPeriod` window helper

**Files:**
- Create: `backend/app/Services/PayslipPeriod.php`
- Test: `backend/tests/Unit/PayslipPeriodTest.php`

**Interfaces:**
- Produces: `PayslipPeriod::resolve(string $month, string $period): array` returning `['label' => string, 'from' => 'Y-m-d', 'to' => 'Y-m-d']`. `$month` is `'Y-m'`; `$period` is `first|second|whole`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Unit/PayslipPeriodTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\PayslipPeriod;
use Tests\TestCase;

class PayslipPeriodTest extends TestCase {
    public function test_first_half_is_1_to_15(): void {
        $p = PayslipPeriod::resolve('2026-07', 'first');
        $this->assertSame('2026-07-01', $p['from']);
        $this->assertSame('2026-07-15', $p['to']);
    }

    public function test_second_half_runs_to_end_of_a_31_day_month(): void {
        $p = PayslipPeriod::resolve('2026-07', 'second');
        $this->assertSame('2026-07-16', $p['from']);
        $this->assertSame('2026-07-31', $p['to']);
    }

    public function test_second_half_runs_to_end_of_a_30_day_month(): void {
        $p = PayslipPeriod::resolve('2026-06', 'second');
        $this->assertSame('2026-06-16', $p['from']);
        $this->assertSame('2026-06-30', $p['to']);
    }

    public function test_second_half_handles_february(): void {
        $p = PayslipPeriod::resolve('2026-02', 'second');
        $this->assertSame('2026-02-16', $p['from']);
        $this->assertSame('2026-02-28', $p['to']);
    }

    public function test_whole_month_is_first_to_last_day(): void {
        $p = PayslipPeriod::resolve('2026-07', 'whole');
        $this->assertSame('2026-07-01', $p['from']);
        $this->assertSame('2026-07-31', $p['to']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=PayslipPeriodTest`
Expected: FAIL — class `App\Services\PayslipPeriod` not found.

- [ ] **Step 3: Implement the helper**

`backend/app/Services/PayslipPeriod.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class PayslipPeriod {
    public static function resolve(string $month, string $period): array {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $lastDay = (int) $start->copy()->endOfMonth()->format('d');

        [$fromDay, $toDay, $label] = match ($period) {
            'first' => [1, 15, $start->format('M') . ' 1–15, ' . $start->format('Y')],
            'second' => [16, $lastDay, $start->format('M') . ' 16–' . $lastDay . ', ' . $start->format('Y')],
            default => [1, $lastDay, $start->format('F Y')],
        };

        return [
            'label' => $label,
            'from' => $start->copy()->day($fromDay)->format('Y-m-d'),
            'to' => $start->copy()->day($toDay)->format('Y-m-d'),
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php artisan test --filter=PayslipPeriodTest`
Expected: `Tests: 5 passed`.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/PayslipPeriod.php backend/tests/Unit/PayslipPeriodTest.php
git commit -m "feat: add PayslipPeriod window helper (1-15 / 16-end / whole month)"
```

---

## Task 2: Attendance-log endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Admin/EmployeeAttendanceController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/EmployeeAttendanceControllerTest.php`

**Interfaces:**
- Consumes: `AttendancePayCalculator::computeForRecord`, `PayrollSetting::current()`.
- Produces: `GET /api/admin/employees/{employee}/attendance?month=YYYY-MM` → JSON `{ month, records: [{ ...attendanceFields, pay }] }`, `{employee}` resolved withTrashed.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/EmployeeAttendanceControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAttendanceControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_returns_only_the_requested_month_for_the_employee(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-05', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-08-05', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        $response = $this->actingAs($admin)->getJson("/api/admin/employees/{$employee->id}/attendance?month=2026-07");

        $response->assertOk();
        $this->assertCount(1, $response->json('records'));
        $this->assertSame('2026-07-05', substr($response->json('records.0.work_date'), 0, 10));
        $this->assertNotNull($response->json('records.0.pay'));
    }

    public function test_resolves_a_separated_employee(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-05', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        $employee->delete();

        $this->actingAs($admin)->getJson("/api/admin/employees/{$employee->id}/attendance?month=2026-07")
            ->assertOk()
            ->assertJsonCount(1, 'records');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=EmployeeAttendanceControllerTest`
Expected: FAIL — route not found (404).

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Admin/EmployeeAttendanceController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Http\Request;

class EmployeeAttendanceController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function index(Request $request, Employee $employee) {
        $month = $request->query('month', now()->format('Y-m'));
        $request->merge(['month' => $month])->validate(['month' => ['date_format:Y-m']]);
        [$year, $mon] = explode('-', $month);

        $settings = PayrollSetting::current();
        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereYear('work_date', (int) $year)
            ->whereMonth('work_date', (int) $mon)
            ->orderBy('work_date')
            ->get()
            ->map(function (AttendanceRecord $record) use ($employee, $settings) {
                $record->setRelation('employee', $employee);
                return array_merge($record->toArray(), [
                    'pay' => $this->calculator->computeForRecord($record, $settings),
                ]);
            });

        return response()->json([
            'employee' => $employee->only(['id', 'employee_code', 'full_name', 'short_name', 'role']),
            'month' => $month,
            'records' => $records,
        ]);
    }
}
```

- [ ] **Step 4: Wire the route (withTrashed binding)**

In `backend/routes/api.php`, add the import near the other admin controllers:

```php
use App\Http\Controllers\Admin\EmployeeAttendanceController;
```

Inside the `auth:sanctum` group, add:

```php
    Route::get('/admin/employees/{employee}/attendance', [EmployeeAttendanceController::class, 'index'])->withTrashed();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && php artisan test --filter=EmployeeAttendanceControllerTest`
Expected: `Tests: 2 passed`.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Admin/EmployeeAttendanceController.php backend/routes/api.php backend/tests/Feature/EmployeeAttendanceControllerTest.php
git commit -m "feat: add per-employee monthly attendance log endpoint"
```

---

## Task 3: Payslip JSON endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Admin/PayslipController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/PayslipControllerTest.php`

**Interfaces:**
- Consumes: `PayslipPeriod::resolve`, `AttendancePayCalculator::computeForRecord`.
- Produces: `GET /api/admin/employees/{employee}/payslip?month=YYYY-MM&period=first|second|whole` → JSON `{ employee, period:{label,from,to}, lines:[{date,shift_start,shift_end,clock_in,clock_out,hours,premium_label,day_pay}], totals:{basic,ot,night_diff,gross} }`. `{employee}` withTrashed. A private `buildPayslip(Employee, string $month, string $period): array` is reused by Task 4's PDF.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/PayslipControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayslipControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_first_half_payslip_only_includes_days_1_to_15(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00', 'daily_basic_rate' => null,
        ]);
        // Two worked days in the first half, one in the second half.
        foreach (['2026-07-02', '2026-07-10', '2026-07-20'] as $d) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $d, 'shift_start' => '08:00:00', 'shift_end' => '17:00:00',
                'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
            ]);
        }

        $response = $this->actingAs($admin)->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=first");

        $response->assertOk();
        $this->assertCount(2, $response->json('lines'));
        // 8h/day at 505/8 = 505/day; 2 days => basic 1010.00, gross 1010.00.
        $this->assertSame(1010.0, $response->json('totals.basic'));
        $this->assertSame(1010.0, $response->json('totals.gross'));
        $this->assertSame('2026-07-01', $response->json('period.from'));
        $this->assertSame('2026-07-15', $response->json('period.to'));
    }

    public function test_payslip_resolves_a_separated_employee(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-07-03', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);
        $employee->delete();

        $this->actingAs($admin)->getJson("/api/admin/employees/{$employee->id}/payslip?month=2026-07&period=whole")
            ->assertOk()
            ->assertJsonCount(1, 'lines');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=PayslipControllerTest`
Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Admin/PayslipController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\PayslipPeriod;
use Illuminate\Http\Request;

class PayslipController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function show(Request $request, Employee $employee) {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', 'in:first,second,whole'],
        ]);

        return response()->json($this->buildPayslip($employee, $data['month'], $data['period']));
    }

    public function buildPayslip(Employee $employee, string $month, string $period): array {
        $window = PayslipPeriod::resolve($month, $period);
        $settings = PayrollSetting::current();

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$window['from'], $window['to']])
            ->whereNotNull('clock_out')
            ->orderBy('work_date')
            ->get();

        $lines = [];
        $basic = $ot = $nightDiff = $gross = 0.0;
        foreach ($records as $record) {
            $record->setRelation('employee', $employee);
            $pay = $this->calculator->computeForRecord($record, $settings);
            if ($pay === null) {
                continue;
            }
            $basic += (float) $pay['basic'];
            $ot += (float) $pay['ot'];
            $nightDiff += (float) $pay['night_diff'];
            $gross += (float) $pay['total'];
            $lines[] = [
                'date' => $record->work_date->format('Y-m-d'),
                'shift_start' => $record->shift_start,
                'shift_end' => $record->shift_end,
                'clock_in' => $record->clock_in,
                'clock_out' => $record->clock_out,
                'hours' => $pay['total_hours'],
                'premium_label' => $pay['premium_label'],
                'day_pay' => $pay['total'],
            ];
        }

        $rate = $employee->daily_basic_rate === null ? (float) $settings->daily_basic_rate : (float) $employee->daily_basic_rate;

        return [
            'employee' => $employee->only(['id', 'employee_code', 'full_name', 'short_name', 'role', 'branch_id']) + [
                'branch' => $employee->branch?->name,
                'daily_rate' => $rate,
            ],
            'period' => $window,
            'lines' => $lines,
            'totals' => [
                'basic' => round($basic, 2),
                'ot' => round($ot, 2),
                'night_diff' => round($nightDiff, 2),
                'gross' => round($gross, 2),
            ],
        ];
    }
}
```

- [ ] **Step 4: Wire the route**

In `backend/routes/api.php`, add the import:

```php
use App\Http\Controllers\Admin\PayslipController;
```

Inside the `auth:sanctum` group, add:

```php
    Route::get('/admin/employees/{employee}/payslip', [PayslipController::class, 'show'])->withTrashed();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && php artisan test --filter=PayslipControllerTest`
Expected: `Tests: 2 passed`.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Admin/PayslipController.php backend/routes/api.php backend/tests/Feature/PayslipControllerTest.php
git commit -m "feat: add per-employee payslip endpoint (semi-monthly/monthly gross)"
```

---

## Task 4: Payslip PDF endpoint + blade

**Files:**
- Modify: `backend/app/Http/Controllers/Admin/PayslipController.php`
- Create: `backend/resources/views/pdf/payslip.blade.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/PayslipControllerTest.php`

**Interfaces:**
- Consumes: `PayslipController::buildPayslip` from Task 3; `Barryvdh\DomPDF\Facade\Pdf`.
- Produces: `GET /api/admin/employees/{employee}/payslip/pdf?month=…&period=…` → `application/pdf` stream.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/PayslipControllerTest.php` (inside the class):

```php
    public function test_payslip_pdf_returns_a_pdf_document(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['daily_basic_rate' => null]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-07-03', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);

        $response = $this->actingAs($admin)->get("/api/admin/employees/{$employee->id}/payslip/pdf?month=2026-07&period=whole");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertNotEmpty($response->getContent());
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=test_payslip_pdf_returns_a_pdf_document`
Expected: FAIL — route not found.

- [ ] **Step 3: Add the `pdf()` method to `PayslipController`**

Add this method to `backend/app/Http/Controllers/Admin/PayslipController.php`, and add `use Barryvdh\DomPDF\Facade\Pdf;` to its imports:

```php
    public function pdf(Request $request, Employee $employee) {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', 'in:first,second,whole'],
        ]);

        $payslip = $this->buildPayslip($employee, $data['month'], $data['period']);
        $pdf = Pdf::loadView('pdf.payslip', ['payslip' => $payslip]);

        return $pdf->stream("payslip-{$employee->employee_code}-{$data['month']}-{$data['period']}.pdf");
    }
```

- [ ] **Step 4: Create the blade**

`backend/resources/views/pdf/payslip.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; color: #221A13; font-size: 12px; }
    h1 { font-size: 18px; margin: 0 0 2px; }
    .muted { color: #7A6A57; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    th, td { border: 1px solid #E7DCC6; padding: 6px 8px; text-align: left; }
    th { background: #FAF6EC; }
    .right { text-align: right; }
    .totals td { border: none; padding: 3px 8px; }
    .gross { font-size: 15px; font-weight: bold; }
</style>
</head>
<body>
    <h1>Payslip</h1>
    <div class="muted">
        {{ $payslip['employee']['full_name'] }} · {{ $payslip['employee']['role'] }}
        · {{ $payslip['employee']['branch'] ?? '—' }}
    </div>
    <div class="muted">Period: {{ $payslip['period']['label'] }} ({{ $payslip['period']['from'] }} to {{ $payslip['period']['to'] }})</div>
    <div class="muted">Daily rate: ₱{{ number_format($payslip['employee']['daily_rate'], 2) }}</div>

    <table>
        <thead>
            <tr><th>Date</th><th>Shift</th><th>In</th><th>Out</th><th class="right">Hours</th><th>Type</th><th class="right">Day Pay</th></tr>
        </thead>
        <tbody>
            @forelse ($payslip['lines'] as $line)
                <tr>
                    <td>{{ $line['date'] }}</td>
                    <td>{{ substr($line['shift_start'], 0, 5) }}–{{ substr($line['shift_end'], 0, 5) }}</td>
                    <td>{{ $line['clock_in'] ? substr($line['clock_in'], 0, 5) : '—' }}</td>
                    <td>{{ $line['clock_out'] ? substr($line['clock_out'], 0, 5) : '—' }}</td>
                    <td class="right">{{ number_format($line['hours'], 2) }}</td>
                    <td>{{ $line['premium_label'] }}</td>
                    <td class="right">₱{{ number_format($line['day_pay'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">No worked days in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals" style="width: 260px; margin-left: auto;">
        <tr><td>Basic</td><td class="right">₱{{ number_format($payslip['totals']['basic'], 2) }}</td></tr>
        <tr><td>Overtime</td><td class="right">₱{{ number_format($payslip['totals']['ot'], 2) }}</td></tr>
        <tr><td>Night Differential</td><td class="right">₱{{ number_format($payslip['totals']['night_diff'], 2) }}</td></tr>
        <tr class="gross"><td>Gross Pay</td><td class="right">₱{{ number_format($payslip['totals']['gross'], 2) }}</td></tr>
    </table>
    <p class="muted" style="margin-top: 14px;">Gross pay — excludes statutory deductions (SSS / PhilHealth / Pag-IBIG / tax).</p>
</body>
</html>
```

- [ ] **Step 5: Wire the route**

In `backend/routes/api.php`, inside the `auth:sanctum` group, add:

```php
    Route::get('/admin/employees/{employee}/payslip/pdf', [PayslipController::class, 'pdf'])->withTrashed();
```

- [ ] **Step 6: Run the tests**

Run: `cd backend && php artisan test --filter=PayslipControllerTest`
Expected: all pass (3 tests).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/Admin/PayslipController.php backend/resources/views/pdf/payslip.blade.php backend/routes/api.php backend/tests/Feature/PayslipControllerTest.php
git commit -m "feat: add printable PDF payslip"
```

---

## Task 5: Frontend — attendance log modal (Employees tab)

**Files:**
- Create: `frontend/src/components/AttendanceLogModal.jsx`
- Modify: `frontend/src/pages/admin/EmployeesView.jsx`

**Interfaces:**
- Consumes: `GET /api/admin/employees/{id}/attendance?month=YYYY-MM`; `apiClient`, `ModalShell`, `Button`, `Pill`, `tableWrap/tableStyle/thStyle/tdStyle` from `../components/ui`; `formatTime12`, `formatHoursLabel` from `../theme`.
- Produces: an `AttendanceLogModal` opened by a per-row "Log" button in both the Active and Separated employee tables.

- [ ] **Step 1: Create the modal component**

`frontend/src/components/AttendanceLogModal.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../api/client";
import { formatTime12 } from "../theme";
import { Button, ModalShell, Pill, tableWrap, tableStyle, thStyle, tdStyle } from "./ui";

function thisMonth() {
  return new Date().toISOString().slice(0, 7);
}

export default function AttendanceLogModal({ employee, onClose }) {
  const [month, setMonth] = useState(thisMonth());
  const [records, setRecords] = useState([]);

  useEffect(() => {
    apiClient.get(`/api/admin/employees/${employee.id}/attendance?month=${month}`)
      .then((res) => setRecords(res.data.records));
  }, [employee.id, month]);

  function shiftMonth(delta) {
    const [y, m] = month.split("-").map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    setMonth(d.toISOString().slice(0, 7));
  }

  return (
    <ModalShell width={760} onClose={onClose}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12 }}>
        <h3 style={{ margin: 0 }}>Attendance — {employee.short_name}</h3>
        <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
          <Button small onClick={() => shiftMonth(-1)}>‹</Button>
          <span style={{ fontSize: 13, minWidth: 90, textAlign: "center" }}>{month}</span>
          <Button small onClick={() => shiftMonth(1)}>›</Button>
        </div>
      </div>
      <div style={tableWrap}>
        <table style={tableStyle}>
          <thead>
            <tr>
              <th style={thStyle}>Date</th>
              <th style={thStyle}>Shift</th>
              <th style={thStyle}>In</th>
              <th style={thStyle}>Out</th>
              <th style={{ ...thStyle, textAlign: "right" }}>Hours</th>
              <th style={thStyle}>Type</th>
            </tr>
          </thead>
          <tbody>
            {records.length === 0 && (
              <tr><td style={{ ...tdStyle, textAlign: "center", color: "#7A6A57" }} colSpan={6}>No attendance this month.</td></tr>
            )}
            {records.map((r) => (
              <tr key={r.id}>
                <td style={tdStyle}>{String(r.work_date).slice(0, 10)}</td>
                <td style={tdStyle}>{String(r.shift_start).slice(0, 5)}–{String(r.shift_end).slice(0, 5)}</td>
                <td style={tdStyle}>{r.clock_in ? formatTime12(r.clock_in) : "—"}</td>
                <td style={tdStyle}>{r.clock_out ? formatTime12(r.clock_out) : "—"}</td>
                <td style={{ ...tdStyle, textAlign: "right" }}>{r.pay ? `${r.pay.total_hours}h` : "—"}</td>
                <td style={tdStyle}>
                  {r.absence_type ? <Pill tone="locked">{r.absence_type.replace("_", " ")}</Pill>
                    : (r.pay?.premium_label && r.pay.premium_label !== "Ordinary" ? <Pill tone="pending">{r.pay.premium_label}</Pill> : "—")}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div style={{ marginTop: 14 }}><Button onClick={onClose}>Close</Button></div>
    </ModalShell>
  );
}
```

- [ ] **Step 2: Wire the "Log" button into EmployeesView**

In `frontend/src/pages/admin/EmployeesView.jsx`:

Add the import:

```jsx
import AttendanceLogModal from "../../components/AttendanceLogModal";
```

Add state near the other `useState` hooks:

```jsx
  const [logEmployee, setLogEmployee] = useState(null);
```

In the **Active** table's action cell (where the Edit/Remove buttons are), add a Log button before Edit:

```jsx
                  <Button small onClick={() => setLogEmployee(emp)}>Log</Button>{" "}
```

In the **Separated** table's action cell (where Restore is), add:

```jsx
                    <Button small onClick={() => setLogEmployee(emp)}>Log</Button>{" "}
```

Render the modal once, near the other modals at the end of the component's JSX:

```jsx
      {logEmployee && <AttendanceLogModal employee={logEmployee} onClose={() => setLogEmployee(null)} />}
```

- [ ] **Step 3: Build the frontend**

Run: `cd frontend && npm run build`
Expected: compiles, `dist/` produced.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/AttendanceLogModal.jsx frontend/src/pages/admin/EmployeesView.jsx
git commit -m "feat: per-staff attendance log modal in the Employees tab"
```

---

## Task 6: Frontend — payslip view (Payroll tab)

**Files:**
- Create: `frontend/src/pages/admin/PayslipView.jsx`
- Modify: `frontend/src/pages/admin/PayrollView.jsx`

**Interfaces:**
- Consumes: `GET /api/admin/employees/{id}/payslip?month&period` and `/payslip/pdf`; `GET /api/admin/employees` (staff list); `GET /api/admin/branches` (unused here, skip); `apiClient`, `Button`, `inputStyle`, `tableWrap/tableStyle/thStyle/tdStyle`; `formatPHP`, `formatTime12`, `FONT_DISPLAY`.
- Produces: a `PayslipView` rendered when the Payroll view switch is set to `payslip`.

- [ ] **Step 1: Create the payslip view**

`frontend/src/pages/admin/PayslipView.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP, formatTime12 } from "../../theme";
import { Button, inputStyle, tableWrap, tableStyle, thStyle, tdStyle } from "../../components/ui";

function thisMonth() {
  return new Date().toISOString().slice(0, 7);
}

const PERIODS = [["first", "1–15"], ["second", "16–end"], ["whole", "Whole month"]];

export default function PayslipView() {
  const [staff, setStaff] = useState([]);
  const [employeeId, setEmployeeId] = useState("");
  const [month, setMonth] = useState(thisMonth());
  const [period, setPeriod] = useState("first");
  const [slip, setSlip] = useState(null);

  useEffect(() => {
    apiClient.get("/api/admin/employees").then((res) => setStaff(res.data));
  }, []);

  useEffect(() => {
    if (!employeeId) { setSlip(null); return; }
    apiClient.get(`/api/admin/employees/${employeeId}/payslip?month=${month}&period=${period}`)
      .then((res) => setSlip(res.data));
  }, [employeeId, month, period]);

  function downloadPdf() {
    if (!employeeId) return;
    window.open(`${apiClient.defaults.baseURL}/api/admin/employees/${employeeId}/payslip/pdf?month=${month}&period=${period}`, "_blank");
  }

  return (
    <div>
      <div style={{ display: "flex", gap: 12, flexWrap: "wrap", marginBottom: 16, alignItems: "center" }}>
        <select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} style={{ ...inputStyle, width: "auto" }}>
          <option value="">Select staff…</option>
          {staff.map((s) => <option key={s.id} value={s.id}>{s.full_name}</option>)}
        </select>
        <input type="month" value={month} onChange={(e) => setMonth(e.target.value)} style={{ ...inputStyle, width: "auto" }} />
        <select value={period} onChange={(e) => setPeriod(e.target.value)} style={{ ...inputStyle, width: "auto" }}>
          {PERIODS.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
        </select>
        <Button variant="outline" onClick={downloadPdf} disabled={!slip}>⬇ PDF</Button>
      </div>

      {!slip && <div style={{ color: "#7A6A57", fontSize: 13 }}>Select a staff member to view their payslip.</div>}

      {slip && (
        <div>
          <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 16, marginBottom: 14 }}>
            <div style={{ fontWeight: 700, fontSize: 16 }}>{slip.employee.full_name}</div>
            <div style={{ fontSize: 13, color: "#7A6A57" }}>{slip.employee.role} · {slip.employee.branch ?? "—"}</div>
            <div style={{ fontSize: 13, color: "#7A6A57" }}>Period: {slip.period.label} ({slip.period.from} to {slip.period.to})</div>
            <div style={{ fontSize: 13, color: "#7A6A57" }}>Daily rate: {formatPHP(slip.employee.daily_rate)}</div>
          </div>

          <div style={tableWrap}>
            <table style={tableStyle}>
              <thead>
                <tr>
                  <th style={thStyle}>Date</th>
                  <th style={thStyle}>Shift</th>
                  <th style={thStyle}>In</th>
                  <th style={thStyle}>Out</th>
                  <th style={{ ...thStyle, textAlign: "right" }}>Hours</th>
                  <th style={{ ...thStyle, textAlign: "right" }}>Day Pay</th>
                </tr>
              </thead>
              <tbody>
                {slip.lines.length === 0 && (
                  <tr><td style={{ ...tdStyle, textAlign: "center", color: "#7A6A57" }} colSpan={6}>No worked days in this period.</td></tr>
                )}
                {slip.lines.map((l) => (
                  <tr key={l.date}>
                    <td style={tdStyle}>{l.date}</td>
                    <td style={tdStyle}>{String(l.shift_start).slice(0, 5)}–{String(l.shift_end).slice(0, 5)}</td>
                    <td style={tdStyle}>{l.clock_in ? formatTime12(l.clock_in) : "—"}</td>
                    <td style={tdStyle}>{l.clock_out ? formatTime12(l.clock_out) : "—"}</td>
                    <td style={{ ...tdStyle, textAlign: "right" }}>{l.hours}h</td>
                    <td style={{ ...tdStyle, textAlign: "right" }}>
                      {formatPHP(l.day_pay)}
                      {l.premium_label && l.premium_label !== "Ordinary" && (
                        <div style={{ fontSize: 11, color: "#9A6B12" }}>{l.premium_label}</div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div style={{ maxWidth: 300, marginLeft: "auto", marginTop: 14, fontSize: 14 }}>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0" }}><span>Basic</span><span>{formatPHP(slip.totals.basic)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0" }}><span>Overtime</span><span>{formatPHP(slip.totals.ot)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0" }}><span>Night Differential</span><span>{formatPHP(slip.totals.night_diff)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "6px 0", fontWeight: 700, fontSize: 16, borderTop: "1px solid #E7DCC6" }}><span>Gross Pay</span><span>{formatPHP(slip.totals.gross)}</span></div>
          </div>
          <p style={{ color: "#7A6A57", fontSize: 12, marginTop: 10 }}>Gross pay — excludes statutory deductions (SSS / PhilHealth / Pag-IBIG / tax).</p>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Add the Payslip switch to PayrollView**

In `frontend/src/pages/admin/PayrollView.jsx`:

Add the import:

```jsx
import PayslipView from "./PayslipView";
```

The Payroll page has a Daily/Weekly button row. Add a third button and a payslip branch. Change the range buttons block (the two `<button>`s for Daily/Weekly) to also include Payslip:

```jsx
          <button onClick={() => setRange("daily")} style={tabBtnStyle(range === "daily")}>Daily</button>
          <button onClick={() => setRange("weekly")} style={tabBtnStyle(range === "weekly")}>Weekly</button>
          <button onClick={() => setRange("payslip")} style={tabBtnStyle(range === "payslip")}>Payslip</button>
```

Then, immediately after the `<h1>Payroll</h1>` heading and BEFORE the StatCard/`data`-dependent block, short-circuit to the payslip view when selected — so the payslip mode does not run the daily/weekly `data` fetch/guards. Wrap the existing daily/weekly body: at the top of the returned JSX, render the switch row always, but render either `<PayslipView/>` or the existing daily/weekly content based on `range`.

Concretely, restructure the component's return so the button row is always shown, and:

```jsx
      {range === "payslip" ? (
        <PayslipView />
      ) : (!data || dataRange !== range) ? (
        <div>Loading...</div>
      ) : (
        <>
          {/* existing StatCard + table block for daily/weekly */}
        </>
      )}
```

Move the `if (!data || dataRange !== range) return <div>Loading...</div>;` early-return OUT (delete that line) and fold the loading state into the ternary above, so `range === "payslip"` never blocks on payroll `data`. Keep the `useEffect` that fetches daily/weekly as-is (it will fetch for daily/weekly ranges; for `payslip` it will call `/api/admin/payroll/payslip` which does not exist — so guard the effect: only fetch when `range !== "payslip"`).

Guard the effect:

```jsx
  useEffect(() => {
    if (range === "payslip") return;
    let cancelled = false;
    setData(null);
    const endpoint = range === "daily" ? "/api/admin/payroll/daily" : "/api/admin/payroll/weekly";
    apiClient.get(endpoint).then((res) => { if (!cancelled) { setData(res.data); setDataRange(range); } });
    return () => { cancelled = true; };
  }, [range]);
```

- [ ] **Step 3: Build the frontend**

Run: `cd frontend && npm run build`
Expected: compiles clean, `dist/` produced.

- [ ] **Step 4: Manual smoke (deferred to user)**

The user verifies: Employees → Log shows a month of attendance with prev/next; Payroll → Payslip picks a staff + period and shows the breakdown + totals, and ⬇ PDF opens a printable payslip.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/admin/PayslipView.jsx frontend/src/pages/admin/PayrollView.jsx
git commit -m "feat: per-staff payslip view (Payroll > Payslip) with period + PDF"
```

---

## Self-Review Notes

- **Spec coverage:** attendance log endpoint (Task 2) ✓; log UI in Employees (Task 5) ✓; payslip period math (Task 1) ✓; payslip JSON endpoint + separated-employee (Task 3) ✓; payslip PDF + blade (Task 4) ✓; payslip UI in Payroll with period + PDF (Task 6) ✓; withTrashed bindings on all three routes ✓; gross-only note in blade + UI ✓; period `first/second/whole` windows ✓; tests for window math, endpoint filtering, totals, PDF content-type ✓.
- **Type consistency:** `PayslipPeriod::resolve` returns `{label,from,to}` used identically by `buildPayslip` (Task 3) and the PDF (Task 4) and frontend (`slip.period.label/from/to`). `buildPayslip` returns `{employee,period,lines,totals}` consumed by both `show()` (Task 3) and `pdf()` (Task 4) and the frontend PayslipView (Task 6). Line fields (`date,shift_start,shift_end,clock_in,clock_out,hours,premium_label,day_pay`) match between blade and PayslipView.
- **Decimal note:** `daily_basic_rate` cast returns a string; `buildPayslip` coerces with `(float)` and resolves override-vs-global. Totals asserted as floats (1010.0) matching `round()`.
- **PayrollView race:** the payslip branch is gated so it never runs the daily/weekly `data` guard (Task 6 Step 2), preserving the earlier range-race fix.
