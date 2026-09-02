<?php

namespace Tests\Unit;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * COMPANY POLICY, NOT DOLE — see AttendancePayCalculator::holidayForfeitedFor.
 *
 * Management directed that an unpaid absence on the calendar day before a
 * regular holiday forfeits that holiday's premium even when the employee
 * worked it. DOLE Handbook p.18 §E.1 and Nippon Paint v. NIPPEA (G.R. No.
 * 229396) both withhold holiday pay only "if he/she has not worked on such
 * regular holiday". These tests pin the override so it cannot drift silently,
 * and so its blast radius stays visible if it is ever revisited.
 */
class HolidayForfeitureTest extends TestCase {
    use RefreshDatabase;

    /** Ruby Rose's Aug 2026 shape: 11:00-20:00 shift at the ₱505 default rate. */
    private function employee(): Employee {
        return Employee::factory()->for(Branch::factory())->create([
            'shift_start' => '11:00:00', 'shift_end' => '20:00:00', 'daily_basic_rate' => null,
        ]);
    }

    private function record(Employee $employee, string $date, array $attributes = []): AttendanceRecord {
        $record = AttendanceRecord::create(array_merge([
            'employee_id' => $employee->id,
            'work_date' => $date,
            'shift_start' => '11:00:00', 'shift_end' => '20:00:00',
            'clock_in' => '10:07:00', 'clock_out' => '20:36:00',
        ], $attributes));
        $record->setRelation('employee', $employee);

        return $record;
    }

    /** The prior day as the DTR template records a non-working day. */
    private function priorDay(Employee $employee, ?string $absenceType): AttendanceRecord {
        return $this->record($employee, '2026-08-30', [
            'absence_type' => $absenceType, 'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);
    }

    private function pay(AttendanceRecord $record): array {
        return (new AttendancePayCalculator())->computeForRecord($record, PayrollSetting::current());
    }

    public function test_absent_the_day_before_forfeits_the_worked_regular_holiday_premium(): void {
        $employee = $this->employee();
        $this->priorDay($employee, 'absent');
        $pay = $this->pay($this->record($employee, '2026-08-31', ['holiday_type' => 'regular']));

        // Rates as an ordinary day: 8h x 63.125, plus 0.6h OT x 63.125 x 1.25.
        $this->assertTrue($pay['holiday_forfeited']);
        $this->assertSame(505.00, $pay['basic']);
        $this->assertSame(47.34, $pay['ot']);
        $this->assertSame(552.34, $pay['total']);
        $this->assertSame('Holiday Premium Forfeited', $pay['premium_label']);
    }

    public function test_present_the_day_before_keeps_the_full_two_hundred_percent(): void {
        $employee = $this->employee();
        $this->record($employee, '2026-08-30', ['clock_in' => '10:30:00', 'clock_out' => '20:00:00']);
        $pay = $this->pay($this->record($employee, '2026-08-31', ['holiday_type' => 'regular']));

        $this->assertFalse($pay['holiday_forfeited']);
        $this->assertSame(1010.00, $pay['basic']);
        $this->assertSame(1108.48, $pay['total']);
        $this->assertSame('Regular Holiday', $pay['premium_label']);
    }

    /** Leave, sick leave and travel are still outside the trigger. */
    public function test_leave_sick_leave_and_travel_keep_the_premium(): void {
        foreach (['leave', 'sick_leave', 'travel'] as $type) {
            $employee = $this->employee();
            $this->priorDay($employee, $type);
            $pay = $this->pay($this->record($employee, '2026-08-31', ['holiday_type' => 'regular']));

            $this->assertFalse($pay['holiday_forfeited'], "{$type} must not forfeit the premium");
            $this->assertSame(1108.48, $pay['total'], "{$type} must keep the 200%");
        }
    }

    public function test_absent_awol_and_rest_day_forfeit_the_premium(): void {
        foreach (['absent', 'awol', 'rest_day'] as $type) {
            $employee = $this->employee();
            $this->priorDay($employee, $type);
            $pay = $this->pay($this->record($employee, '2026-08-31', ['holiday_type' => 'regular']));

            $this->assertTrue($pay['holiday_forfeited'], "{$type} must forfeit the premium");
            $this->assertSame(552.34, $pay['total']);
        }
    }

    /**
     * Ruby Rose's actual Aug 2026 record, and the reason the rule was widened:
     * Aug 30 is a Sunday carrying the "Rest Day" status, so under management's
     * instruction of 2026-09-02 the Aug 31 premium is forfeited. DOLE Handbook
     * p.18 §E.3 says the opposite — a rest day is not an absence — so this test
     * exists to make the divergence explicit and greppable.
     */
    public function test_a_rest_day_before_the_holiday_now_forfeits_the_premium(): void {
        $employee = $this->employee();
        $this->priorDay($employee, 'rest_day');
        $pay = $this->pay($this->record($employee, '2026-08-31', ['holiday_type' => 'regular']));

        $this->assertTrue($pay['holiday_forfeited']);
        $this->assertSame(552.34, $pay['total']);
        $this->assertSame('Holiday Premium Forfeited', $pay['premium_label']);
    }

    /** Special days are premium pay under Art. 93, outside the holiday-pay rule. */
    public function test_special_holidays_are_untouched_by_the_rule(): void {
        $employee = $this->employee();
        $this->priorDay($employee, 'absent');
        $pay = $this->pay($this->record($employee, '2026-08-31', ['holiday_type' => 'special']));

        $this->assertFalse($pay['holiday_forfeited']);
        $this->assertSame('Special Holiday', $pay['premium_label']);
    }

    /** With no record at all for the prior day there is nothing to forfeit on. */
    public function test_a_missing_prior_day_record_keeps_the_premium(): void {
        $pay = $this->pay($this->record($this->employee(), '2026-08-31', ['holiday_type' => 'regular']));

        $this->assertFalse($pay['holiday_forfeited']);
        $this->assertSame(1108.48, $pay['total']);
    }

    /** One employee's absence must not reach another employee's holiday. */
    public function test_the_absence_is_scoped_to_the_same_employee(): void {
        $this->priorDay($this->employee(), 'absent');
        $pay = $this->pay($this->record($this->employee(), '2026-08-31', ['holiday_type' => 'regular']));

        $this->assertFalse($pay['holiday_forfeited']);
        $this->assertSame(1108.48, $pay['total']);
    }

    /** A rest-day holiday keeps its Art. 93 premium; only the holiday half goes. */
    public function test_rest_day_premium_survives_the_forfeiture(): void {
        $employee = $this->employee();
        $this->priorDay($employee, 'absent');
        $pay = $this->pay($this->record($employee, '2026-08-31', [
            'holiday_type' => 'regular', 'is_rest_day' => true,
        ]));

        $this->assertTrue($pay['holiday_forfeited']);
        $this->assertSame(1.30, $pay['premium_multiplier']);
        $this->assertSame('Rest Day + Holiday Premium Forfeited', $pay['premium_label']);
    }
}
