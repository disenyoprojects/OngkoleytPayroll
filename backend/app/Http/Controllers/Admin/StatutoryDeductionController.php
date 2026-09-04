<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Services\LatePenaltyCalculator;
use App\Services\PayslipPeriod;
use App\Services\PeriodEarnings;
use App\Services\PhilHealthPeriodContribution;
use App\Services\SssPeriodContribution;
use Illuminate\Http\Request;

class StatutoryDeductionController extends Controller {
    private const PAGIBIG_PER_CUTOFF = 100.0;
    public const AUTO_REASON = 'Auto-generated statutory deduction';

    public function __construct(
        private PeriodEarnings $earnings,
        private SssPeriodContribution $contribution,
        private PhilHealthPeriodContribution $philhealth,
        private LatePenaltyCalculator $latePenalty,
    ) {}

    /**
     * Auto-generate the period's deductions for every employee in scope:
     * Pag-IBIG (flat ₱100/cutoff, ₱200/whole month), PhilHealth (2.5% of the
     * month's basic, floored at the ₱10,000 income floor), SSS (bracket table,
     * the month settling in the second cutoff) and the late penalty (a flat
     * charge per late day).
     *
     * Re-running is safe and is how a period is corrected: a row this generator
     * wrote earlier is updated in place when the computed amount has changed,
     * and one entered by hand is left alone. That last part is what keeps the
     * late penalty from charging the same days twice — see LatePenaltyCalculator.
     */
    public function generate(Request $request) {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', 'in:first,second,whole'],
        ]);

        $window = PayslipPeriod::resolve($data['month'], $data['period']);
        $settings = PayrollSetting::current();
        $branchId = $this->branchFilter($request);
        $pagibigAmount = $data['period'] === 'whole' ? 200.0 : self::PAGIBIG_PER_CUTOFF;

        $employees = Employee::when($branchId !== null, fn ($q) => $q->whereIn('branch_id', $branchId))->get();

        $blank = ['pagibig' => 0, 'philhealth' => 0, 'sss' => 0, 'penalty_late' => 0];
        $counts = ['generated' => $blank, 'updated' => $blank, 'skipped' => $blank];

        foreach ($employees as $employee) {
            // Late penalty: one row for the cutoff carrying the whole charge,
            // so three lates read as a single 225.00 rather than three 75s.
            $lateDays = $this->latePenalty->lateDaysFor($employee, $window, $settings);
            if ($lateDays > 0) {
                $outcome = $this->upsertLatePenalty($employee, $window, $lateDays, $settings, $request);
                $counts[$outcome]['penalty_late']++;
            }

            $outcome = $this->upsertAuto($employee, 'pagibig', $window, -$pagibigAmount, 'Pag-IBIG', $request);
            $counts[$outcome]['pagibig']++;

            // PhilHealth: 2.5% of basic, but the income floor and ceiling are
            // monthly, so the month settles in the second cutoff. See
            // PhilHealthPeriodContribution.
            if ($this->earnings->sum($employee, $window, $settings, 'base_wage') > 0.0) {
                $philhealthAmount = -$this->philhealth->forPeriod($employee, $data['month'], $data['period'], $settings);
                $outcome = $this->upsertAuto($employee, 'philhealth', $window, $philhealthAmount, 'PhilHealth', $request);
                $counts[$outcome]['philhealth']++;
            }

            // SSS: the first cutoff pays the bracket on its own earnings, the
            // second the balance of the month's bracket. See SssPeriodContribution.
            if ($this->earnings->sssBasis($employee, $window, $settings) > 0.0) {
                $sssAmount = -$this->contribution->forPeriod($employee, $data['month'], $data['period'], $settings);
                $outcome = $this->upsertAuto($employee, 'sss', $window, $sssAmount, 'SSS', $request);
                $counts[$outcome]['sss']++;
            }
        }

        return response()->json($counts);
    }

    /** A row that charges lateness, whichever type it was entered under. */
    private const LATE_LABEL = '/\blate|\bpenalt/i';

    /**
     * The cutoff's late penalty: one row carrying the whole charge, filed as an
     * Authorized Deduction because that is how the office enters it, with the
     * day count in the label. The summary workbook still lands it in the
     * Penalty Lates column — see the label fallback in PayslipController.
     *
     * The guard that matters is the first one. When this generator existed
     * before (3fb2e53) it wrote its figure alongside a lump the office had
     * already typed for the same days and charged them twice. So: if anything
     * in this window already charges lateness and a person put it there, this
     * leaves the period alone entirely.
     */
    private function upsertLatePenalty(Employee $employee, array $window, int $lateDays, PayrollSetting $settings, Request $request): string {
        $rows = PayrollAdjustment::where('employee_id', $employee->id)
            ->whereIn('category', ['penalty_late', 'deduction'])
            ->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to'])->get();

        $charges = fn (PayrollAdjustment $a) => $a->category === 'penalty_late'
            || preg_match(self::LATE_LABEL, (string) $a->label);

        if ($rows->contains(fn ($a) => $charges($a) && $a->reason !== self::AUTO_REASON)) {
            return 'skipped'; // the office has charged these days itself
        }

        $amount = -round($lateDays * (float) ($settings->late_penalty_amount ?? 0), 2);
        $label = sprintf('Penalty Late (%d %s)', $lateDays, $lateDays === 1 ? 'day' : 'days');
        $mine = $rows->first(fn ($a) => $charges($a) && $a->reason === self::AUTO_REASON);

        if ($mine) {
            if (round((float) $mine->amount, 2) === $amount && $mine->label === $label) {
                return 'skipped';
            }
            $mine->update(['amount' => $amount, 'label' => $label]);

            return 'updated';
        }

        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => $window['to'], 'label' => $label,
            'category' => 'deduction', 'amount' => $amount, 'paid' => false,
            'reason' => self::AUTO_REASON, 'created_by' => $request->user()->id,
        ]);

        return 'generated';
    }

    /**
     * Writes the employee/category adjustment for this window. A row this
     * generator wrote earlier is corrected in place when the computed amount
     * has moved (a rate or rule change, say); a hand-entered row is never
     * touched. Returns 'generated', 'updated' or 'skipped'.
     */
    private function upsertAuto(Employee $employee, string $category, array $window, float $amount, string $label, Request $request): string {
        $existing = PayrollAdjustment::where('employee_id', $employee->id)->where('category', $category)
            ->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to'])->first();

        if ($existing) {
            if ($existing->reason !== self::AUTO_REASON) {
                return 'skipped'; // somebody typed this; it is theirs
            }
            if (round((float) $existing->amount, 2) === round($amount, 2) && $existing->label === $label) {
                return 'skipped';
            }

            // The label carries the late penalty's day count, so it has to move
            // with the amount or a re-run leaves the row claiming the wrong
            // number of days.
            $existing->update(['amount' => $amount, 'label' => $label]);

            return 'updated';
        }

        PayrollAdjustment::create([
            'employee_id' => $employee->id, 'date' => $window['to'], 'label' => $label,
            'category' => $category, 'amount' => $amount, 'paid' => false,
            'reason' => self::AUTO_REASON, 'created_by' => $request->user()->id,
        ]);

        return 'generated';
    }
}
