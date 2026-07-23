# Employee Management (Add/Edit + Per-Employee Daily Rate) — Design

**Goal:** Give admins a way to add and edit employees from the Admin UI (currently only possible via the database seeder), and let an individual employee's daily basic rate override the global rate set in Payroll Settings.

## Data model

- `employees` gets a new nullable column: `daily_basic_rate DECIMAL(10,2) NULL`.
  - `NULL` means "use the global `PayrollSetting.daily_basic_rate`" (current behavior, unchanged for every existing employee).
  - A non-null value overrides the global rate for that employee only.
- `audit_logs.type` enum gains a new value: `'employee'`. Since local dev runs MySQL and production runs Postgres, the migration widens the enum with driver-aware raw SQL (MySQL: `MODIFY COLUMN`; Postgres: drop + recreate the check constraint).

## Backend API

New `App\Http\Controllers\Admin\EmployeeController`, registered under the existing `auth:sanctum` middleware group in `routes/api.php`:

- `GET /admin/employees` — list all employees (with `branch` eager-loaded), for the table view. No pagination needed at this roster size (~14-20 employees).
- `GET /admin/branches` — list of `{id, name}` for the branch `<select>` in the form. (Not reusing `/api/kiosk/staff` since that endpoint excludes resigned employees and is shaped for the kiosk name-picker, not admin use.)
- `POST /admin/employees` — create an employee. Validates:
  - `employee_code` required, unique
  - `full_name`, `short_name`, `role` required strings
  - `branch_id` required, must exist
  - `employment_type` required, one of `regular|probationary|fixed_term|seasonal`
  - `hire_date` required date; `resignation_date` optional date
  - `pin` required, 4 digits (hashed via the existing `Employee::setPinAttribute`)
  - `daily_basic_rate` optional decimal
  - If `daily_basic_rate` is present (non-null), `reason` is required and a row is written to `audit_logs` (`type: employee`, `action: rate_override_set`, `new_amount`, `reason`).
- `PUT /admin/employees/{employee}` — update the same fields.
  - `pin` optional; blank/omitted means keep the existing PIN.
  - `reason` is required **only when `daily_basic_rate` changes** (set, changed to a different value, or cleared back to null). Editing any other field (name, role, branch, employment type, dates) never requires a reason.
  - When `daily_basic_rate` changes, write an `audit_logs` row capturing `old_amount`, `new_amount`, and `reason`.

## Pay calculation integration

`AttendancePayCalculator::compute()` gets a new optional 4th parameter:

```php
public function compute(?string $clockIn, ?string $clockOut, PayrollSetting $settings, ?float $dailyRateOverride = null): ?array
```

Internally, `$dailyRate = $dailyRateOverride ?? (float) $settings->daily_basic_rate;` replaces the current direct read of `$settings->daily_basic_rate`.

All existing call sites pass `$record->employee->daily_basic_rate` as the 4th argument (the employee is already eager-loaded at every call site today, so this is a mechanical, low-risk change):
- `App\Http\Controllers\Admin\PayrollController` (`daily()`, `weekly()`)
- `App\Http\Controllers\Admin\PayrollExportController`
- `App\Http\Controllers\Admin\PayrollPdfController`
- `App\Http\Controllers\Admin\AttendanceDashboardController`
- `App\Services\ThirteenthMonthCalculator`
- `App\Http\Controllers\Kiosk\StaffDashboardController` (both call sites)

## Frontend

New `frontend/src/pages/admin/EmployeesView.jsx`, added as a 6th tab in `AdminApp.jsx` (`Attendance | Payroll | 13th Month | Settings | Audit Log | Employees`), matching the existing plain-inline-style / `Button` & `inputStyle` conventions used in `SettingsView.jsx` (no modals anywhere else in this app, so this screen won't introduce one either).

- A table: employee code, full name, branch, employment type, daily rate (renders "— (global)" when null), an Edit action per row.
- "+ Add Employee" button toggles the same form component in "create" mode, blank.
- Form fields: employee code, full name, short name, role, branch (`<select>` populated from `GET /admin/branches`), employment type (`<select>` of the 4 fixed values), hire date, resignation date (optional), PIN (labelled "leave blank to keep current" when editing), daily basic rate (optional, labelled "blank = use global rate").
- A `Reason` input appears (and is required) only when the daily rate field's current value differs from the value the form was loaded with — mirrors the conditional-reason-box pattern already used for attendance adjustments elsewhere in the admin UI.

## Testing

- Feature tests for `EmployeeController`:
  - create validates required fields and `employee_code` uniqueness
  - create with a `daily_basic_rate` requires `reason` and writes an audit log row
  - update that changes `daily_basic_rate` requires `reason` and writes an audit log row (with correct `old_amount`/`new_amount`)
  - update that does **not** touch `daily_basic_rate` succeeds without a `reason`
  - update with a blank `pin` keeps the existing PIN working (`verifyPin` still passes with the old PIN)
- Unit test for `AttendancePayCalculator::compute()`: asserts the override is used when given, and falls back to the settings' global rate when `null`.

## Out of scope (YAGNI)

- No hard delete of employees — offboarding is already handled by setting `resignation_date` via the edit form.
- No employee-level overrides for overtime/night-diff multipliers — only the daily basic rate is per-employee for now.
- No pagination/search on the employee list at this roster size.
