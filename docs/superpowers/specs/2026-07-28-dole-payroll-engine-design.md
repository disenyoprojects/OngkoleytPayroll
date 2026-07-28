# DOLE Payroll Engine — Design (Phase 1: day model + Phase 2: pay rules)

**Goal:** Compute payroll from real DTR data that has a **different scheduled shift per day**, **rest days**, **special/regular holidays**, and non-working statuses (leave, half-day, absent, AWOL, travel) — applying **standard Philippine DOLE premium rates**. This spec covers the foundation (per-day attendance model) and the pay-rule engine. Rates entry (Phase 3) and DTR bulk import (Phase 4) are separate specs.

## Scope of this spec

In: attendance day model, calculator rewrite to apply DOLE premiums, admin UI to set day fields, tests against hand-picked DTR days.
Out (later phases): per-employee real daily rates; a DTR parser/bulk importer.

## Data model — `attendance_records`

The record currently has: `employee_id, work_date, shift_start (time, default 08:00), clock_in, clock_out, status(pending/approved), adjusted, reason, details`.

Add:
- `shift_end` — `time`, default `17:00`. (Per-**day** scheduled shift; `shift_start` already exists. These override the employee's default shift for that day, so the DTR's daily-changing schedule is honored.)
- `holiday_type` — `string` nullable: `null` | `special` | `regular`. The calendar nature of the day.
- `is_rest_day` — `boolean`, default `false`.
- `absence_type` — `string` nullable: `null` (worked) | `leave` | `sick_leave` | `half_day` | `absent` | `awol` | `travel`.
- `break_out` / `break_in` — `time` nullable. Optional actual break window; when both present the real break is deducted, otherwise the flat `unpaid_break_hours` setting is used.

`status` (pending/approved) and the existing columns are unchanged. Driver-aware note: all additions are plain nullable columns / booleans (portable MySQL + Postgres), no enum DDL.

## Pay-rule engine — `AttendancePayCalculator`

The calculator gains the day context (`holiday_type`, `is_rest_day`, `absence_type`, per-day shift, optional break window). Computation:

1. **Non-worked resolution (before any hours math):**
   - `absent`, `awol` → all pay `0`.
   - `travel` → `0` by default (see Decision A).
   - `leave` / `sick_leave` → `0` by default (see Decision A).
   - `half_day` → compute as a worked day, then the **regular** portion is capped at half the scheduled hours (OT/night-diff/premiums still computed on actual worked time beyond that).
   - otherwise (`null` / worked) → proceed to step 2.

2. **Base hours (unchanged logic, now per-day shift + optional real break):**
   - `regular hours` = worked minutes inside `[shift_start, shift_end]` − break (real break window if given, else the flat setting).
   - `ot hours` = worked minutes after `shift_end`.
   - `night-diff hours` = paid hours (shift_start onward) inside 22:00–06:00.
   - hourly = daily ÷ 8.

3. **DOLE premium multipliers** applied on top of the base pay:

   | Day nature | Regular-hours multiplier | OT-hours multiplier (of the OT hour) |
   |---|---|---|
   | Ordinary day | ×1.00 | ×1.25 |
   | Rest day (worked) | ×1.30 | ×1.30 → i.e. hourly ×1.30 ×1.30 |
   | Special non-working holiday (worked) | ×1.30 | ×1.30 of the ×1.30 OT |
   | Special holiday **and** rest day | ×1.50 | ×1.50 base for OT |
   | Regular holiday (worked) | ×2.00 | ×2.60 |
   | Regular holiday **and** rest day | ×2.60 | ×3.38 |

   Night differential is **+10% of the applicable hourly rate** for hours in 22:00–06:00 — i.e. it stacks on whatever premium rate applies that day.

4. Return the existing shape plus a `premium_label` (e.g. "Special Holiday", "Rest Day") and the multiplier used, so the payroll table and payslip can show why an amount is higher.

The multipliers live as named constants (a small `DoleRates` table/config) so they're auditable and changeable in one place, not scattered.

## Admin UI

The attendance-adjust modal (already used for editing clock times) gains: per-day **Shift Start / Shift End**, a **Holiday** selector (None / Special / Regular), a **Rest day** checkbox, an **Absence** selector (Worked / Leave / Sick Leave / Half day / Absent / AWOL / Travel), and optional **Break out / Break in**. The Payroll table shows the premium label next to affected rows.

## Testing (hand-picked DTR days, standard rates)

Unit tests on the calculator, each mirroring a real row from the July 1–15 DTR:
- **Ordinary worked day** (e.g. Kendrick, Jul 1, 9AM–6PM shift) — regular + any OT, ×1.00 / ×1.25.
- **Rest day worked** (e.g. someone clocked in on a `REST DAY` with hours) — ×1.30.
- **Special holiday worked** (Jul 15, everyone) — regular ×1.30, plus OT and night-diff stacking.
- **Special holiday + rest day** — ×1.50.
- **Half day** (e.g. Genalyn, Jul 5) — regular capped at half.
- **Absent / AWOL** (e.g. Marjorie Ped AWOL) — pay 0.
- **Night differential on a late-out day** (clock-out past 22:00) — +10% on night hours at the day's premium rate.
- Fallback: no per-day shift set → uses the employee's default shift (back-compat with existing records/tests).

## Decisions to confirm (in spec review)

- **Decision A — paid vs unpaid leave/travel:** By default this spec treats `leave`, `sick_leave`, and `travel` as **unpaid** (₱0) because payment depends on company policy and SIL balances the system doesn't track yet. If the client pays service-incentive leave or official travel, we add a "paid" flag per record (or a policy setting) — flag if needed.
- **Decision B — regular holidays in this period:** The Jul 1–15 DTR only marks **Special Holiday (Jul 15)**; no regular holiday appears. The regular-holiday rows in the matrix are implemented for completeness but untested against real data this period.
- **Decision C — half-day definition:** "Regular capped at half the scheduled hours" — confirm this matches how the client counts a half day (vs. actual-hours-worked, which for a short day may already be < half).

## Out of scope (YAGNI, deferred)

- 13th-month interaction with premiums (existing 13th-month logic already sums included earnings; unaffected here).
- Automatic holiday calendar (holidays are set per-record for now; a shared holiday calendar could auto-fill later).
- Bulk DTR import (Phase 4).
