# 13th-Month Premium Exclusion — Design

**Goal:** Stop holiday/rest-day premiums (and OT premium stacking) from inflating the 13th-month base. Under PD 851, the 13th-month base is "basic salary" only — it excludes premium pay, overtime, night differential, and holiday pay. Since the DOLE payroll engine now multiplies `basic`/`ot` by holiday/rest premiums, those premium-inflated values must not feed the 13th-month computation.

**Rules decision (approved):** On a holiday/rest day the employee worked, the day's **base wage** (regular hours × ordinary hourly rate, the ×1.0 amount) counts toward the 13th-month base; the holiday/rest **premium** (the +30% etc.), OT premium stacking, and night differential do not.

## Change 1 — `AttendancePayCalculator::compute()` return

Add two un-premiumed fields to the returned array, alongside the existing premium-inclusive `basic`/`ot` (which are unchanged and still drive actual pay/payslip):

- `base_wage` = `round(regularHours × hourlyRate, 2)` — regular hours at the ordinary hourly rate, no holiday/rest premium multiplier.
- `base_ot` = `round(otHours × hourlyRate × overtime_multiplier, 2)` — OT hours at the ordinary OT rate (1.25), with NO holiday/rest premium stacking.

Both are computed from values already in scope (`regularHours`, `otHours`, `hourlyRate`, `$settings->overtime_multiplier`). The `zeroed()` no-pay return must also include `base_wage => 0.0` and `base_ot => 0.0` so the array shape is consistent for all callers.

Note: `base_wage` equals the existing `basic` on ordinary days (premium multiplier 1.0), and is strictly less than `basic` on premium days — that difference is exactly the premium being excluded.

## Change 2 — `ThirteenthMonthCalculator::monthlyBreakdown()`

Currently, for each worked day it sums `$pay['basic']` (when `BASIC` is in `included_earnings`) and `$pay['ot']` (when `OVERTIME` is included). Change these to the un-premiumed fields:

- BASIC sum: `$pay['basic']` → `$pay['base_wage']`.
- OVERTIME sum: `$pay['ot']` → `$pay['base_ot']`.

Everything else in the 13th-month path is unchanged: the `included_earnings` settings, BASIC-mandatory behavior, the `EmployeeEarning` "other pay" sum for opt-in codes (bonuses/allowances etc.), eligibility, and `computedAmount = round(totalIncluded ÷ 12, 2)`.

## Testing

Add to `ThirteenthMonthCalculator` unit/feature tests (whichever the existing 13th-month calc tests use):

- **Premium excluded:** a month where the employee worked a special-holiday day (`holiday_type = special`) plus ordinary days. Assert the month's `basic_pay`/`month_total_included` uses the ×1.0 base wage for the holiday day (e.g. ₱505 base, not ₱656.50) — i.e. the +30% premium is excluded.
- **Ordinary unchanged (regression):** a month of ordinary worked days produces the same 13th-month total as before this change (base_wage == basic on ordinary days), so existing 13th-month expectations still hold.
- **OVERTIME opt-in uses base OT:** if feasible with the existing test setup, a month with OT on a premium day, with `OVERTIME` in `included_earnings`, contributes `base_ot` (ordinary 1.25 rate) not the premium-stacked `ot`.

## Out of scope (YAGNI)

- No change to how actual payroll pay / payslips display `basic`/`ot` (those keep the premiums — that's correct for pay).
- No change to `included_earnings` configuration or the eligibility/lock/release lifecycle.
- Night differential remains outside the 13th-month base (it was never summed there).
