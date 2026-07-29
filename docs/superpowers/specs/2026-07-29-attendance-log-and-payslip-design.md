# Per-Staff Attendance Log & Payslip — Design

**Goal:** Two admin features: (A) view any staff member's monthly attendance log, and (B) generate a per-staff payslip for a semi-monthly (1–15, 16–end) or whole-month period, on screen and as a printable PDF.

Both reuse the existing DOLE pay engine (`AttendancePayCalculator::computeForRecord`) so hours, premiums, and pay are computed exactly as elsewhere. Payslips are **gross pay only** (earnings) — statutory deductions (SSS/PhilHealth/Pag-IBIG/withholding tax) are out of scope, since the system does not track them.

## Feature A — Per-staff attendance log

**Access:** In `EmployeesView` (Active and Separated tables), each row gets a **"Log"** button that opens a `ModalShell` showing that employee's attendance for a selected month, with `‹ / ›` month navigation (defaults to the current Manila month).

**Backend:** `GET /api/admin/employees/{employee}/attendance?month=YYYY-MM` (under `auth:sanctum`).
- Route-model binding resolves the employee **including trashed** (`->withTrashed()` on the route), so a separated employee's history still opens.
- Validates `month` as `date_format:Y-m`; defaults to the current month when omitted.
- Returns the employee's `AttendanceRecord`s for that month (`whereYear`/`whereMonth` on `work_date`), ordered by `work_date`, each with a computed `pay` object from `computeForRecord($record, PayrollSetting::current())` (may be null if the record has no clock in/out).

**Frontend columns:** Date · Shift (`shift_start`–`shift_end`) · Clock In · Clock Out · Hours (`pay.total_hours`, or "—") · Day type (a `Pill` showing `pay.premium_label` when not "Ordinary", plus the `absence_type` when set) · Status. Empty month → "No attendance this month."

## Feature B — Per-staff payslip (semi-monthly / monthly)

**Access:** The Payroll tab's view switch becomes **Daily | Weekly | Payslip**. In Payslip mode: a **staff** `<select>`, a **month** input (`type="month"`), and a **period** toggle (`1–15` / `16–end` / `Whole month`, values `first` / `second` / `whole`).

**Period window** (from `month=YYYY-MM` + `period`):
- `first` → day 01 through 15.
- `second` → day 16 through the last day of the month.
- `whole` → day 01 through the last day of the month.

**Backend:**
- `GET /api/admin/employees/{employee}/payslip?month=YYYY-MM&period=first|second|whole` (auth, `{employee}` withTrashed). Validates `month` (`date_format:Y-m`) and `period` (`in:first,second,whole`). Computes the window, loads the employee's records with `clock_out` set in `[from, to]`, computes each day's pay via `computeForRecord`, and returns:
  - `employee` (id, name, role, branch, daily rate resolved: override or global),
  - `period` (label, from, to),
  - `lines`: one per worked day — `date`, `shift_start`, `shift_end`, `clock_in`, `clock_out`, `hours`, `premium_label`, `day_pay` (that day's `pay.total`),
  - `totals`: `basic` (sum of `pay.basic`), `ot` (sum of `pay.ot`), `night_diff` (sum of `pay.night_diff`), `gross` (sum of `pay.total`).
- `GET /api/admin/employees/{employee}/payslip/pdf?month=…&period=…` → the same computation rendered via `barryvdh/laravel-dompdf` using a new `resources/views/pdf/payslip.blade.php`, streamed as `payslip-{code}-{month}-{period}.pdf`. Mirrors the existing 13th-month payslip PDF controller pattern.

**Frontend payslip view:** header (employee name · role · branch · period dates · daily rate), the per-day lines table (Date · Shift · In · Out · Hours · Day Pay, with a premium tag), and a totals block (Basic / Overtime / Night Differential / **Gross Pay**), plus a **Download PDF** button that `window.open`s the pdf endpoint. A note on the payslip reads "Gross pay — excludes statutory deductions."

## Files (new / changed)

- Backend: new `EmployeeAttendanceController` (log) and `PayslipController` (payslip JSON + PDF); `routes/api.php` (3 routes, withTrashed bindings); new `resources/views/pdf/payslip.blade.php`. A small `PayslipPeriod` helper (or a private method) resolves the `[from, to]` window from `month`+`period` and is unit-tested directly.
- Frontend: `EmployeesView.jsx` (Log button + `AttendanceLogModal`), a new `AttendanceLogModal.jsx`; `PayrollView.jsx` (Payslip view switch) and a new `PayslipView.jsx`.

## Testing

- **Period window (unit):** `first`/`second`/`whole` for a 31-day month (July) and a 30-day month (June) and February (28) — assert exact `from`/`to`.
- **Payslip endpoint:** an employee with a few worked days across a month returns lines only for the selected window and correct summed totals; a separated employee still returns a payslip.
- **Attendance-log endpoint:** returns only the requested month's records for that employee, ordered, with computed pay; a different employee's/month's records are excluded.
- **PDF endpoint:** returns a `application/pdf` response (content-type + non-empty body), mirroring the existing payslip-PDF test.
- Frontend: `npm run build`; manual verification of the log modal and payslip + PDF.

## Out of scope (YAGNI)

- Statutory deductions and net pay (SSS/PhilHealth/Pag-IBIG/tax) — the payslip is gross only.
- Emailing/sending payslips.
- Editing attendance from the log modal (edits stay in the existing Attendance-adjust flow).
- Saving/locking payslips as records (computed on demand from attendance).
