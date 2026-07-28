# DOLE Payroll Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Compute payroll from real DTR data with per-day scheduled shifts, rest days, special/regular holidays, and non-working statuses, applying standard Philippine DOLE premium rates.

**Architecture:** Add per-day context columns to `attendance_records`. Extend `AttendancePayCalculator::compute()` with backward-compatible optional inputs (day context) that layer DOLE premium multipliers on top of the existing regular/OT/night-diff base math. A new `computeForRecord()` wrapper reads a record's per-day shift + day context (falling back to the employee's default shift) so the 8 existing call sites collapse to one call. Admin attendance-adjust UI gains the day fields.

**Tech Stack:** Laravel 11, PHPUnit, MySQL (dev) + Postgres (prod), React 18/Vite.

## Global Constraints

- Money computed server-side only; `daily_basic_rate` cast `decimal:2` returns a string or null.
- Hourly rate = daily ÷ 8. Existing base math unchanged: regular = worked minutes inside `[shift_start, shift_end]` − break; OT = worked after shift_end; night diff = paid hours (shift_start onward) in 22:00–06:00.
- DOLE premium multipliers (applied on top of base): ordinary regular ×1.00 / OT ×1.25; rest day ×1.30 / OT hourly ×1.30×1.30; special holiday ×1.30 / OT hourly ×1.30×1.30; special+rest ×1.50; regular holiday ×2.00 / OT ×2.60; regular holiday+rest ×2.60 / OT ×3.38. Night diff = +10% of the applicable (premium) hourly rate.
- Non-worked defaults: `absent`, `awol`, `travel`, `leave`, `sick_leave` → pay 0. `half_day` → regular capped at half the scheduled hours.
- All new columns are plain nullable/boolean (portable MySQL + Postgres); no enum DDL.
- Migrations run on Railway/Postgres on deploy; tests run on MySQL via `RefreshDatabase`.
- Backward compatibility: existing `compute()` calls (4th=rate, 5th=shiftStart, 6th=shiftEnd) must keep working; day context is a new 7th arg defaulting to an ordinary worked day.

---

## Task 1: Attendance day-model columns + model

**Files:**
- Create: `backend/database/migrations/2026_07_28_000001_add_day_context_to_attendance_records.php`
- Modify: `backend/app/Models/AttendanceRecord.php`
- Test: `backend/tests/Unit/AttendanceRecordDayContextTest.php`

**Interfaces:**
- Produces: `AttendanceRecord` with new fillable/cast fields `shift_end` (time), `holiday_type` (string|null: `special`/`regular`), `is_rest_day` (bool), `absence_type` (string|null), `break_out` (time|null), `break_in` (time|null).

- [ ] **Step 1: Write the failing test**

`backend/tests/Unit/AttendanceRecordDayContextTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceRecordDayContextTest extends TestCase {
    use RefreshDatabase;

    public function test_day_context_fields_persist(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => '2026-07-15',
            'shift_start' => '09:00:00',
            'shift_end' => '18:00:00',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'holiday_type' => 'special',
            'is_rest_day' => true,
            'absence_type' => null,
            'break_out' => '13:00:00',
            'break_in' => '14:00:00',
        ]);

        $fresh = $record->fresh();
        $this->assertSame('18:00:00', $fresh->shift_end);
        $this->assertSame('special', $fresh->holiday_type);
        $this->assertTrue($fresh->is_rest_day);
        $this->assertSame('13:00:00', $fresh->break_out);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=AttendanceRecordDayContextTest`
Expected: FAIL — unknown column `shift_end`.

- [ ] **Step 3: Write the migration**

`backend/database/migrations/2026_07_28_000001_add_day_context_to_attendance_records.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->time('shift_end')->default('17:00:00')->after('shift_start');
            $table->string('holiday_type')->nullable()->after('shift_end');
            $table->boolean('is_rest_day')->default(false)->after('holiday_type');
            $table->string('absence_type')->nullable()->after('is_rest_day');
            $table->time('break_out')->nullable()->after('absence_type');
            $table->time('break_in')->nullable()->after('break_out');
        });
    }

    public function down(): void {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['shift_end', 'holiday_type', 'is_rest_day', 'absence_type', 'break_out', 'break_in']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `backend/app/Models/AttendanceRecord.php`, set:

```php
    protected $fillable = [
        'employee_id', 'work_date', 'shift_start', 'shift_end', 'clock_in', 'clock_out',
        'holiday_type', 'is_rest_day', 'absence_type', 'break_out', 'break_in',
        'status', 'adjusted', 'reason', 'details',
    ];
    protected $casts = [
        'work_date' => 'date',
        'adjusted' => 'boolean',
        'is_rest_day' => 'boolean',
    ];
```

- [ ] **Step 5: Run migration and the test**

Run: `cd backend && php artisan migrate && php artisan test --filter=AttendanceRecordDayContextTest`
Expected: `Tests: 1 passed`.

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_07_28_000001_add_day_context_to_attendance_records.php backend/app/Models/AttendanceRecord.php backend/tests/Unit/AttendanceRecordDayContextTest.php
git commit -m "feat: add per-day shift + holiday/rest/absence context to attendance records"
```

---

## Task 2: DOLE premium engine in `AttendancePayCalculator`

**Files:**
- Create: `backend/app/Services/DoleRates.php`
- Modify: `backend/app/Services/AttendancePayCalculator.php`
- Test: `backend/tests/Unit/DolePremiumCalculatorTest.php`

**Interfaces:**
- Consumes: `PayrollSetting` (`daily_basic_rate`, `overtime_multiplier`, `night_diff_multiplier`, `unpaid_break_hours`).
- Produces: `AttendancePayCalculator::compute(?string $clockIn, ?string $clockOut, PayrollSetting $settings, ?float $dailyRateOverride = null, ?string $shiftStart = null, ?string $shiftEnd = null, array $day = []): ?array`. `$day` keys (all optional): `holiday_type` (`special`/`regular`/null), `is_rest_day` (bool), `absence_type` (string|null), `break_out` (`H:i`|null), `break_in` (`H:i`|null). Return array adds `premium_label` (string) and `premium_multiplier` (float) to the existing keys.
- `DoleRates::regularMultiplier(?string $holidayType, bool $isRestDay): float` and `DoleRates::label(?string $holidayType, bool $isRestDay): string`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Unit/DolePremiumCalculatorTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Tests\TestCase;

class DolePremiumCalculatorTest extends TestCase {
    private function settings(): PayrollSetting {
        return new PayrollSetting([
            'daily_basic_rate' => 505.00,
            'overtime_multiplier' => 1.25,
            'night_diff_multiplier' => 0.10,
            'unpaid_break_hours' => 1.0,
        ]);
    }

    // Shift 09:00-18:00 (9h window, 8h after 1h break); clock 09:00-18:00.
    private function eightHourDay(array $day): array {
        return (new AttendancePayCalculator())
            ->compute('09:00', '18:00', $this->settings(), null, '09:00', '18:00', $day);
    }

    public function test_ordinary_day_no_premium(): void {
        $pay = $this->eightHourDay([]);
        $this->assertSame(505.00, $pay['basic']);
        $this->assertSame(505.00, $pay['total']);
        $this->assertSame(1.0, $pay['premium_multiplier']);
    }

    public function test_rest_day_worked_is_130_percent(): void {
        $pay = $this->eightHourDay(['is_rest_day' => true]);
        // 8 * 63.125 * 1.30 = 656.50
        $this->assertSame(656.50, $pay['basic']);
        $this->assertSame(656.50, $pay['total']);
        $this->assertSame('Rest Day', $pay['premium_label']);
    }

    public function test_special_holiday_worked_is_130_percent(): void {
        $pay = $this->eightHourDay(['holiday_type' => 'special']);
        $this->assertSame(656.50, $pay['basic']);
        $this->assertSame('Special Holiday', $pay['premium_label']);
    }

    public function test_special_holiday_on_rest_day_is_150_percent(): void {
        $pay = $this->eightHourDay(['holiday_type' => 'special', 'is_rest_day' => true]);
        // 8 * 63.125 * 1.50 = 757.50
        $this->assertSame(757.50, $pay['basic']);
    }

    public function test_regular_holiday_worked_is_200_percent(): void {
        $pay = $this->eightHourDay(['holiday_type' => 'regular']);
        // 8 * 63.125 * 2.00 = 1010.00
        $this->assertSame(1010.00, $pay['basic']);
    }

    public function test_absent_pays_zero(): void {
        $pay = $this->eightHourDay(['absence_type' => 'absent']);
        $this->assertSame(0.0, $pay['total']);
    }

    public function test_leave_and_travel_pay_zero_by_default(): void {
        $this->assertSame(0.0, $this->eightHourDay(['absence_type' => 'leave'])['total']);
        $this->assertSame(0.0, $this->eightHourDay(['absence_type' => 'travel'])['total']);
    }

    public function test_half_day_caps_regular_at_half_scheduled(): void {
        // Worked the full 8h but flagged half_day: regular capped at 4h.
        $pay = $this->eightHourDay(['absence_type' => 'half_day']);
        // 4 * 63.125 = 252.50
        $this->assertSame(252.50, $pay['basic']);
    }

    public function test_night_diff_stacks_on_special_holiday_rate(): void {
        // Shift 14:00-23:00 (9h window, 8h after break); clock 14:00-23:00 special holiday.
        // Night hours 22:00-23:00 = 1h. Night pay = 1 * 63.125 * 1.30 * 0.10 = 8.21.
        $pay = (new AttendancePayCalculator())
            ->compute('14:00', '23:00', $this->settings(), null, '14:00', '23:00', ['holiday_type' => 'special']);
        $this->assertSame(8.21, $pay['night_diff']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=DolePremiumCalculatorTest`
Expected: FAIL — `premium_multiplier` key missing / premiums not applied.

- [ ] **Step 3: Write `DoleRates`**

`backend/app/Services/DoleRates.php`:

```php
<?php

namespace App\Services;

class DoleRates {
    /**
     * Multiplier applied to REGULAR-hour pay for the day's nature.
     * Ordinary 1.00, rest day 1.30, special 1.30, special+rest 1.50,
     * regular holiday 2.00, regular holiday+rest 2.60.
     */
    public static function regularMultiplier(?string $holidayType, bool $isRestDay): float {
        if ($holidayType === 'regular') {
            return $isRestDay ? 2.60 : 2.00;
        }
        if ($holidayType === 'special') {
            return $isRestDay ? 1.50 : 1.30;
        }
        return $isRestDay ? 1.30 : 1.00;
    }

    /**
     * Extra factor applied to the ordinary OT rate for the day's nature.
     * Ordinary 1.00 (OT already 1.25), rest/special 1.30, regular holiday 2.00,
     * regular holiday+rest 2.60. (DOLE OT-on-premium-day is 30% of the premium hourly.)
     */
    public static function overtimeFactor(?string $holidayType, bool $isRestDay): float {
        if ($holidayType === 'regular') {
            return $isRestDay ? 2.60 : 2.00;
        }
        if ($holidayType === 'special') {
            return $isRestDay ? 1.50 : 1.30;
        }
        return $isRestDay ? 1.30 : 1.00;
    }

    public static function label(?string $holidayType, bool $isRestDay): string {
        $parts = [];
        if ($holidayType === 'regular') { $parts[] = 'Regular Holiday'; }
        if ($holidayType === 'special') { $parts[] = 'Special Holiday'; }
        if ($isRestDay) { $parts[] = 'Rest Day'; }
        return $parts ? implode(' + ', $parts) : 'Ordinary';
    }
}
```

- [ ] **Step 4: Rewrite `compute()` to layer premiums**

Replace `backend/app/Services/AttendancePayCalculator.php` with:

```php
<?php

namespace App\Services;

use App\Models\PayrollSetting;

class AttendancePayCalculator {
    private const DEFAULT_SHIFT_START = '08:00';
    private const DEFAULT_SHIFT_END = '17:00';
    private const NO_PAY_ABSENCES = ['absent', 'awol', 'travel', 'leave', 'sick_leave'];

    public function compute(
        ?string $clockIn,
        ?string $clockOut,
        PayrollSetting $settings,
        ?float $dailyRateOverride = null,
        ?string $shiftStart = null,
        ?string $shiftEnd = null,
        array $day = [],
    ): ?array {
        if (! $clockIn || ! $clockOut) {
            return null;
        }

        $holidayType = $day['holiday_type'] ?? null;
        $isRestDay = (bool) ($day['is_rest_day'] ?? false);
        $absenceType = $day['absence_type'] ?? null;
        $premiumLabel = DoleRates::label($holidayType, $isRestDay);
        $regularMult = DoleRates::regularMultiplier($holidayType, $isRestDay);
        $otFactor = DoleRates::overtimeFactor($holidayType, $isRestDay);

        // No-pay absence statuses short-circuit to a zeroed result.
        if (in_array($absenceType, self::NO_PAY_ABSENCES, true)) {
            return $this->zeroed($premiumLabel, $regularMult);
        }

        $start = $this->minutesOf($clockIn);
        $end = $this->minutesOf($clockOut);
        if ($end <= $start) {
            $end += 24 * 60;
        }

        $shiftStartMin = $this->minutesOf($shiftStart ?: self::DEFAULT_SHIFT_START);
        $shiftEndMin = $this->minutesOf($shiftEnd ?: self::DEFAULT_SHIFT_END);
        if ($shiftEndMin <= $shiftStartMin) {
            $shiftEndMin += 24 * 60;
        }

        // Break: actual window if both given, else the flat setting.
        if (! empty($day['break_out']) && ! empty($day['break_in'])) {
            $breakHours = max(0, ($this->minutesOf($day['break_in']) - $this->minutesOf($day['break_out'])) / 60.0);
        } else {
            $breakHours = (float) ($settings->unpaid_break_hours ?? 0);
        }

        $regWithinMin = (float) max(0, min($end, $shiftEndMin) - max($start, $shiftStartMin));
        $regWithinHours = $regWithinMin / 60.0;
        $regularHours = $regWithinHours > $breakHours ? $regWithinHours - $breakHours : $regWithinHours;

        // Half day caps paid regular at half the scheduled (post-break) hours.
        if ($absenceType === 'half_day') {
            $scheduledHours = max(0, ($shiftEndMin - $shiftStartMin) / 60.0 - $breakHours);
            $regularHours = min($regularHours, $scheduledHours / 2.0);
        }

        $otMin = (float) max(0, $end - max($shiftEndMin, $start));
        $otHours = $otMin / 60.0;

        $dailyRate = $dailyRateOverride ?? (float) $settings->daily_basic_rate;
        $hourlyRate = $dailyRate / 8;

        $paidStart = max($start, $shiftStartMin);
        $nightStart = 22 * 60;
        $nightEnd = 24 * 60 + 6 * 60;
        $overlapStart = max($paidStart, $nightStart);
        $overlapEnd = min($end, $nightEnd);
        $nightDiffHours = (float) max(0, ($overlapEnd - $overlapStart) / 60.0);

        // Regular pay at the day's premium multiplier.
        $basic = round($regularHours * $hourlyRate * $regularMult, 2);
        // OT: ordinary is hourly*ot_multiplier; premium days multiply by the OT factor.
        $ot = round($otHours * $hourlyRate * (float) $settings->overtime_multiplier * $otFactor, 2);
        // Night diff: +nd_multiplier of the applicable premium hourly rate.
        $nightDiff = round($nightDiffHours * $hourlyRate * $regularMult * (float) $settings->night_diff_multiplier, 2);
        $total = round($basic + $ot + $nightDiff, 2);

        return [
            'total_hours' => round($regularHours + $otHours, 4),
            'regular_hours' => $regularHours,
            'ot_hours' => $otHours,
            'night_diff_hours' => $nightDiffHours,
            'basic' => $basic,
            'ot' => $ot,
            'night_diff' => $nightDiff,
            'total' => $total,
            'premium_label' => $premiumLabel,
            'premium_multiplier' => $regularMult,
        ];
    }

    private function zeroed(string $label, float $mult): array {
        return [
            'total_hours' => 0.0, 'regular_hours' => 0.0, 'ot_hours' => 0.0, 'night_diff_hours' => 0.0,
            'basic' => 0.0, 'ot' => 0.0, 'night_diff' => 0.0, 'total' => 0.0,
            'premium_label' => $label, 'premium_multiplier' => $mult,
        ];
    }

    private function minutesOf(string $hhmm): int {
        [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
        return $h * 60 + $m;
    }
}
```

- [ ] **Step 5: Run the premium tests and the existing calculator tests**

Run: `cd backend && php artisan test --filter=AttendancePayCalculator && php artisan test --filter=DolePremiumCalculatorTest`
Expected: all pass. The existing `AttendancePayCalculatorTest`, `AttendancePayCalculatorBreakTest`, and `AttendancePayCalculatorOverrideTest` still pass because `$day` defaults to an ordinary day (multiplier 1.0), leaving their results unchanged.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/DoleRates.php backend/app/Services/AttendancePayCalculator.php backend/tests/Unit/DolePremiumCalculatorTest.php
git commit -m "feat: apply DOLE holiday/rest-day premiums and no-pay/half-day statuses"
```

---

## Task 3: `computeForRecord()` wrapper + switch call sites

**Files:**
- Modify: `backend/app/Services/AttendancePayCalculator.php`
- Modify: `backend/app/Http/Controllers/Admin/PayrollController.php`
- Modify: `backend/app/Http/Controllers/Admin/PayrollExportController.php`
- Modify: `backend/app/Http/Controllers/Admin/PayrollPdfController.php`
- Modify: `backend/app/Http/Controllers/Admin/AttendanceDashboardController.php`
- Modify: `backend/app/Services/ThirteenthMonthCalculator.php`
- Modify: `backend/app/Http/Controllers/Kiosk/StaffDashboardController.php`
- Test: `backend/tests/Unit/ComputeForRecordTest.php`

**Interfaces:**
- Consumes: `AttendanceRecord` (with `employee` loaded), `PayrollSetting`.
- Produces: `AttendancePayCalculator::computeForRecord(\App\Models\AttendanceRecord $record, PayrollSetting $settings): ?array` — reads the per-day shift (falling back to the employee's default shift), the employee's daily-rate override, and the record's day context, then calls `compute()`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Unit/ComputeForRecordTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputeForRecordTest extends TestCase {
    use RefreshDatabase;

    public function test_uses_per_day_shift_and_special_holiday_premium(): void {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00', 'daily_basic_rate' => null,
        ]);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id, 'work_date' => '2026-07-15',
            'shift_start' => '09:00:00', 'shift_end' => '18:00:00',
            'clock_in' => '09:00:00', 'clock_out' => '18:00:00',
            'holiday_type' => 'special',
        ]);
        $record->setRelation('employee', $employee);

        $settings = PayrollSetting::current();
        $pay = (new AttendancePayCalculator())->computeForRecord($record, $settings);

        // 8h at 63.125 * 1.30 = 656.50 (uses the record's 09:00-18:00 shift, not the employee default)
        $this->assertSame(656.50, $pay['basic']);
    }

    public function test_falls_back_to_employee_shift_when_record_shift_missing(): void {
        $employee = Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '08:00:00', 'shift_end' => '17:00:00', 'daily_basic_rate' => null,
        ]);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id, 'work_date' => '2026-07-10',
            'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);
        $record->setRelation('employee', $employee);

        $pay = (new AttendancePayCalculator())->computeForRecord($record, PayrollSetting::current());
        // 08:00-17:00 (9h) - 1h break = 8h regular, ordinary rate => 505.00
        $this->assertSame(505.00, $pay['basic']);
    }
}
```

Note: the record migration defaults `shift_start`/`shift_end` to 08:00/17:00, so "missing" means equal to the employee default here; the fallback logic still must prefer the record's stored shift. The first test proves the record's 09:00-18:00 is used.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=ComputeForRecordTest`
Expected: FAIL — `computeForRecord` undefined.

- [ ] **Step 3: Add `computeForRecord()`**

In `backend/app/Services/AttendancePayCalculator.php`, add this method to the class:

```php
    public function computeForRecord(\App\Models\AttendanceRecord $record, PayrollSetting $settings): ?array {
        $employee = $record->employee;
        $rate = $employee?->daily_basic_rate === null ? null : (float) $employee->daily_basic_rate;
        $shiftStart = $record->shift_start ?: ($employee?->shift_start);
        $shiftEnd = $record->shift_end ?: ($employee?->shift_end);

        return $this->compute(
            $record->clock_in,
            $record->clock_out,
            $settings,
            $rate,
            $shiftStart,
            $shiftEnd,
            [
                'holiday_type' => $record->holiday_type,
                'is_rest_day' => (bool) $record->is_rest_day,
                'absence_type' => $record->absence_type,
                'break_out' => $record->break_out,
                'break_in' => $record->break_in,
            ],
        );
    }
```

- [ ] **Step 4: Switch every call site to `computeForRecord`**

Each site currently builds `$rate` then calls `compute($record->clock_in, $record->clock_out, $settings, $rate, $emp->shift_start, $emp->shift_end)`. Replace the rate line + compute call with a single `computeForRecord($record, $settings)`.

**4a.** `PayrollController.php` `daily()` — replace:

```php
                $rate = $record->employee->daily_basic_rate === null ? null : (float) $record->employee->daily_basic_rate;
                $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings, $rate, $record->employee->shift_start, $record->employee->shift_end);
```

with:

```php
                $pay = $this->calculator->computeForRecord($record, $settings);
```

In `weekly()`, replace:

```php
            $employeeForShift = $employeeRecords->first()->employee;
            $rate = $employeeForShift->daily_basic_rate === null ? null : (float) $employeeForShift->daily_basic_rate;
            $pays = $employeeRecords->map(fn (AttendanceRecord $r) => $this->calculator->compute($r->clock_in, $r->clock_out, $settings, $rate, $employeeForShift->shift_start, $employeeForShift->shift_end));
```

with (the records already have `employee` eager-loaded via `with('employee.branch')`):

```php
            $pays = $employeeRecords->map(fn (AttendanceRecord $r) => $this->calculator->computeForRecord($r, $settings));
```

**4b.** `AttendanceDashboardController.php` `today()` — replace:

```php
            $rate = $record->employee->daily_basic_rate === null ? null : (float) $record->employee->daily_basic_rate;
            $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings, $rate, $record->employee->shift_start, $record->employee->shift_end);
```

with:

```php
            $pay = $this->calculator->computeForRecord($record, $settings);
```

**4c.** `PayrollExportController.php` — replace:

```php
                $rate = $record->employee->daily_basic_rate === null ? null : (float) $record->employee->daily_basic_rate;
                $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings, $rate, $record->employee->shift_start, $record->employee->shift_end);
```

with:

```php
                $pay = $this->calculator->computeForRecord($record, $settings);
```

**4d.** `PayrollPdfController.php` — replace the whole `'pay' => $this->calculator->compute(...)` argument with:

```php
            'pay' => $this->calculator->computeForRecord($record, $settings),
```

**4e.** `ThirteenthMonthCalculator.php` `monthlyBreakdown()` — replace:

```php
                    $rate = $employee->daily_basic_rate === null ? null : (float) $employee->daily_basic_rate;
                    $pay = $this->payCalculator->compute($record->clock_in, $record->clock_out, $settings, $rate, $employee->shift_start, $employee->shift_end);
```

with (the `$record` here has no `employee` relation loaded; set it so the wrapper can read the rate/shift):

```php
                    $record->setRelation('employee', $employee);
                    $pay = $this->payCalculator->computeForRecord($record, $settings);
```

**4f.** `StaffDashboardController.php` `show()` — replace the `$rate` line and both compute calls. Replace:

```php
        $rate = $employee->daily_basic_rate === null ? null : (float) $employee->daily_basic_rate;
        $todayPay = $todayRecord ? $this->payCalculator->compute($todayRecord->clock_in, $todayRecord->clock_out, $settings, $rate, $employee->shift_start, $employee->shift_end) : null;
```

with:

```php
        if ($todayRecord) { $todayRecord->setRelation('employee', $employee); }
        $todayPay = $todayRecord ? $this->payCalculator->computeForRecord($todayRecord, $settings) : null;
```

and replace:

```php
        $weekPays = $weekRecords->map(fn (AttendanceRecord $r) => $this->payCalculator->compute($r->clock_in, $r->clock_out, $settings, $rate, $employee->shift_start, $employee->shift_end));
```

with:

```php
        $weekPays = $weekRecords->map(function (AttendanceRecord $r) use ($employee, $settings) {
            $r->setRelation('employee', $employee);
            return $this->payCalculator->computeForRecord($r, $settings);
        });
```

- [ ] **Step 5: Run the full backend suite**

Run: `cd backend && php artisan test`
Expected: all pass (existing payroll/dashboard/13th-month tests unchanged because default records are ordinary worked days with the same shift as before).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/AttendancePayCalculator.php backend/app/Http/Controllers/Admin/PayrollController.php backend/app/Http/Controllers/Admin/PayrollExportController.php backend/app/Http/Controllers/Admin/PayrollPdfController.php backend/app/Http/Controllers/Admin/AttendanceDashboardController.php backend/app/Services/ThirteenthMonthCalculator.php backend/app/Http/Controllers/Kiosk/StaffDashboardController.php backend/tests/Unit/ComputeForRecordTest.php
git commit -m "feat: computeForRecord wrapper reads per-day shift + day context at all pay call sites"
```

---

## Task 4: Admin attendance-adjust accepts day context (API + UI)

**Files:**
- Modify: `backend/app/Http/Controllers/Admin/AttendanceAdminController.php`
- Modify: `backend/tests/Feature/AttendanceAdminControllerTest.php`
- Modify: `frontend/src/components/AdjustAttendanceModal.jsx`

**Interfaces:**
- Consumes: the `PATCH /api/admin/attendance/{record}/adjust` route (exists).
- Produces: the adjust endpoint also persists `shift_start`, `shift_end`, `holiday_type`, `is_rest_day`, `absence_type`, `break_out`, `break_in` when supplied.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/AttendanceAdminControllerTest.php` (inside the class; `use App\Models\AttendanceRecord;` etc. already imported):

```php
    public function test_adjust_persists_day_context_fields(): void {
        $admin = \App\Models\User::factory()->create();
        $employee = \App\Models\Employee::factory()->for(\App\Models\Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create([
            'clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'status' => 'pending',
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/attendance/{$record->id}/adjust", [
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'reason' => 'Special holiday shift',
            'shift_start' => '09:00',
            'shift_end' => '18:00',
            'holiday_type' => 'special',
            'is_rest_day' => true,
            'absence_type' => null,
        ])->assertOk();

        $fresh = $record->fresh();
        $this->assertSame('special', $fresh->holiday_type);
        $this->assertTrue($fresh->is_rest_day);
        $this->assertSame('09:00:00', $fresh->shift_start);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=AttendanceAdminControllerTest`
Expected: FAIL — day-context fields not persisted.

- [ ] **Step 3: Extend the controller**

In `backend/app/Http/Controllers/Admin/AttendanceAdminController.php` `adjust()`, extend the validation and the `$record->update([...])`. Add to the `$request->validate([...])` array:

```php
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'holiday_type' => ['nullable', 'in:special,regular'],
            'is_rest_day' => ['nullable', 'boolean'],
            'absence_type' => ['nullable', 'in:leave,sick_leave,half_day,absent,awol,travel'],
            'break_out' => ['nullable', 'date_format:H:i'],
            'break_in' => ['nullable', 'date_format:H:i'],
```

And in the `$record->update([...])` call, add these keys (using the existing `$data`), only overwriting when present so a bare clock edit still works:

```php
            'shift_start' => $data['shift_start'] ?? $record->shift_start,
            'shift_end' => $data['shift_end'] ?? $record->shift_end,
            'holiday_type' => $data['holiday_type'] ?? null,
            'is_rest_day' => $data['is_rest_day'] ?? false,
            'absence_type' => $data['absence_type'] ?? null,
            'break_out' => $data['break_out'] ?? null,
            'break_in' => $data['break_in'] ?? null,
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php artisan test --filter=AttendanceAdminControllerTest`
Expected: all pass.

- [ ] **Step 5: Add the fields to the adjust modal**

In `frontend/src/components/AdjustAttendanceModal.jsx`, add state and inputs. After the existing `details` state, add:

```jsx
  const [shiftStart, setShiftStart] = useState((row.record.shift_start || "08:00").slice(0, 5));
  const [shiftEnd, setShiftEnd] = useState((row.record.shift_end || "17:00").slice(0, 5));
  const [holidayType, setHolidayType] = useState(row.record.holiday_type || "");
  const [isRestDay, setIsRestDay] = useState(!!row.record.is_rest_day);
  const [absenceType, setAbsenceType] = useState(row.record.absence_type || "");
```

Change `save()` to send the new fields:

```jsx
  async function save() {
    await apiClient.patch(`/api/admin/attendance/${row.record.id}/adjust`, {
      clock_in: clockIn, clock_out: clockOut, reason, details,
      shift_start: shiftStart, shift_end: shiftEnd,
      holiday_type: holidayType || null,
      is_rest_day: isRestDay,
      absence_type: absenceType || null,
    });
    onSaved();
  }
```

Add these inputs before the Reason select (follow the existing two-column style):

```jsx
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12, marginBottom: 14 }}>
        <div>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Shift Start</div>
          <input type="time" value={shiftStart} onChange={(e) => setShiftStart(e.target.value)} style={inputStyle} />
        </div>
        <div>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Shift End</div>
          <input type="time" value={shiftEnd} onChange={(e) => setShiftEnd(e.target.value)} style={inputStyle} />
        </div>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12, marginBottom: 14 }}>
        <div>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Holiday</div>
          <select value={holidayType} onChange={(e) => setHolidayType(e.target.value)} style={inputStyle}>
            <option value="">None</option>
            <option value="special">Special (non-working)</option>
            <option value="regular">Regular holiday</option>
          </select>
        </div>
        <div>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Status</div>
          <select value={absenceType} onChange={(e) => setAbsenceType(e.target.value)} style={inputStyle}>
            <option value="">Worked</option>
            <option value="half_day">Half day</option>
            <option value="leave">Leave</option>
            <option value="sick_leave">Sick leave</option>
            <option value="absent">Absent</option>
            <option value="awol">AWOL</option>
            <option value="travel">Travel</option>
          </select>
        </div>
      </div>
      <label style={{ display: "flex", gap: 8, alignItems: "center", fontSize: 13, marginBottom: 14 }}>
        <input type="checkbox" checked={isRestDay} onChange={(e) => setIsRestDay(e.target.checked)} />
        Rest day (worked)
      </label>
```

- [ ] **Step 6: Build the frontend**

Run: `cd frontend && npm run build`
Expected: compiles, `dist/` produced.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/Admin/AttendanceAdminController.php backend/tests/Feature/AttendanceAdminControllerTest.php frontend/src/components/AdjustAttendanceModal.jsx
git commit -m "feat: attendance-adjust sets per-day shift, holiday, rest-day, and status"
```

---

## Task 5: Show the premium label in the Attendance and Payroll tables

**Files:**
- Modify: `frontend/src/pages/admin/AttendanceView.jsx`
- Modify: `frontend/src/pages/admin/PayrollView.jsx`

**Interfaces:**
- Consumes: the `pay.premium_label` field now returned by the API.
- Produces: a small badge/text next to the total showing "Special Holiday", "Rest Day", etc. when not "Ordinary".

- [ ] **Step 1: Add the label to AttendanceView**

In `frontend/src/pages/admin/AttendanceView.jsx`, in the Status column cell (or a new small cell), after the existing `<Pill>` for status add:

```jsx
                  {row.pay?.premium_label && row.pay.premium_label !== "Ordinary" && (
                    <span style={{ marginLeft: 6, fontSize: 11, color: "#9A6B12" }}>{row.pay.premium_label}</span>
                  )}
```

- [ ] **Step 2: Add the label to PayrollView (daily table)**

In `frontend/src/pages/admin/PayrollView.jsx` daily rows, in the Total Pay cell append:

```jsx
                    {r.pay?.premium_label && r.pay.premium_label !== "Ordinary" && (
                      <div style={{ fontSize: 11, color: "#9A6B12" }}>{r.pay.premium_label}</div>
                    )}
```

- [ ] **Step 3: Build the frontend**

Run: `cd frontend && npm run build`
Expected: compiles clean.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/admin/AttendanceView.jsx frontend/src/pages/admin/PayrollView.jsx
git commit -m "feat: show DOLE premium label next to attendance/payroll totals"
```

---

## Self-Review Notes

- **Spec coverage:** per-day shift columns (Task 1) ✓; holiday_type/is_rest_day/absence_type/break window (Task 1) ✓; DOLE premium matrix incl. combos + OT/night-diff stacking (Task 2, `DoleRates` + `compute`) ✓; no-pay statuses + half-day cap (Task 2) ✓; per-day shift with employee fallback (Task 3, `computeForRecord`) ✓; admin UI to set day fields (Task 4) ✓; premium label surfaced (Task 5) ✓; test cases mirror DTR days (Task 2) ✓; Decision A (leave/travel unpaid), B (regular holiday untested this period), C (half-day = half scheduled) implemented as the approved defaults ✓.
- **Backward compatibility:** `compute()` keeps its positional params; `$day` is a new trailing arg defaulting to ordinary. Existing `AttendancePayCalculator*Test` files pass unchanged, so no rewrite of prior tests.
- **Decimal note:** `daily_basic_rate` cast `decimal:2` returns a string; call sites coerce with `(float)` before passing (unchanged pattern). Expected pay assertions are floats (505.00, 656.50) matching `round(...)` output.
- **Deferred (own specs):** Phase 3 real per-employee rates, Phase 4 DTR bulk import.
