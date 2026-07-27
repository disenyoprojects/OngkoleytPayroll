# Employee Separation (Archive with Proper/Improper Resignation) — Design

**Goal:** Replace the hard-delete of employees with a reversible "separation" (archive): an admin removes an employee by classifying the departure as a **proper** or **improper** resignation (with a reason and optional resignation date). Separated employees are hidden from the active roster but keep all their history, are listed on a separate view filterable by type, and can be restored.

## Data model

Add to the `employees` table:
- `deleted_at` — nullable timestamp, via Laravel's `SoftDeletes` trait (the archive marker).
- `separation_type` — nullable enum: `proper` | `improper`. Set when the employee is separated; cleared on restore.
- `separation_reason` — nullable string. Set when separated; cleared on restore.

Add the `SoftDeletes` trait to `App\Models\Employee`. Consequences:
- `$employee->delete()` now soft-deletes (sets `deleted_at`) instead of removing the row.
- All normal Eloquent queries (`Employee::all()`, the active roster, the kiosk staff list) automatically exclude archived employees — no query changes needed.
- Archived employees are reachable via `Employee::onlyTrashed()` / `withTrashed()`.
- All attendance/earnings/13th-month/audit rows remain intact (the row is not deleted, so no FK cascade fires).

The `separation_type` enum column must be added with a driver-aware default-free migration; no raw enum widening is needed here since it's a new column, but the migration must work on both MySQL (dev/test) and Postgres (production) — a plain `$table->string('separation_type')->nullable()` with app-level validation is simplest and fully portable, so use `string` (not a DB enum) and enforce the allowed values in the controller.

## Backend API

All routes under the existing `auth:sanctum` group.

- `POST /admin/employees/{employee}/separate` — validates:
  - `separation_type` required, in `['proper','improper']`
  - `reason` required, non-empty after trim (consistent with the existing rate-change reason rule)
  - `resignation_date` optional date; defaults to today when omitted
  Sets `separation_type`, `separation_reason` (trimmed), `resignation_date`; writes one `audit_logs` row (`type: employee`, `action: separated`, `detail` naming the type + employee, `reason`); then calls `$employee->delete()` (soft delete). Wrapped in a DB transaction (matches the atomicity pattern used by store/update/destroy).
- `POST /admin/employees/{employee}/restore` — resolves the employee including trashed (`withTrashed`), calls `restore()`, clears `separation_type`, `separation_reason`, and `resignation_date` to null; writes one `audit_logs` row (`type: employee`, `action: restored`). Wrapped in a transaction. Route-model binding must be configured to include trashed rows (use `withTrashed()` on the binding or resolve manually by id).
- `GET /admin/employees/separated` — returns `Employee::onlyTrashed()->with('branch')->orderBy('short_name')->get()`. Optional query param `type=proper|improper` filters by `separation_type`.
- `GET /admin/employees` — unchanged; automatically returns active (non-trashed) employees only.

**Removal of the old hard-delete:** delete the `DELETE /admin/employees/{employee}` route, the `EmployeeController::destroy()` method, and its two feature tests (`test_delete_succeeds_when_employee_has_no_history`, `test_delete_is_refused_when_employee_has_attendance`, and the SET-NULL coverage test `test_deleting_employee_with_prior_audit_row_preserves_it_with_null_employee_id`). The `2026_07_23_100003_set_null_on_delete_for_audit_logs_employee_id` migration stays in place (harmless; reverting a migration already applied to production Postgres is riskier than leaving it).

## Frontend (`EmployeesView.jsx`)

A view switch at the top of the Employees tab: **Active** | **Separated**.

**Active view** (current table, minimal changes):
- Keeps the existing columns, the "+ Add Employee" button, and the "Hide resigned" toggle.
- The per-row red **Delete** button becomes **Remove**. Clicking it opens a `ModalShell` popup (same modal style as the add/edit form) containing:
  - Resignation type: a select/radio with `Proper` / `Improper`.
  - Resignation date: a date input, prefilled to today, optional.
  - Reason: a text input, required.
  - Save → `POST /admin/employees/{id}/separate`; on success close modal + reload; on error show the API message in the modal.

**Separated view:**
- A filter row: `All | Proper | Improper` (calls `GET /admin/employees/separated` with the matching `type`).
- A table: code, name, branch, separation type (badge), removed-on date (from `deleted_at`), reason.
- A per-row **Restore** button → `POST /admin/employees/{id}/restore`; on success reload; on error surface the API message.

No browser `alert()`/`confirm()` — actions and errors are surfaced in-modal or inline.

## Testing

Backend feature tests (`EmployeeControllerTest` or a new `EmployeeSeparationTest`):
- separate archives the employee (excluded from active index / present in `onlyTrashed`), sets `separation_type` + trimmed `separation_reason` + `resignation_date`, and writes one `separated` audit row.
- separate rejects a missing/blank/whitespace reason (422) and an invalid/missing `separation_type` (422).
- `GET /admin/employees` excludes a separated employee; `GET /admin/employees/separated` returns it; `?type=improper` returns only improper ones.
- restore brings the employee back into the active index, nulls `separation_type`/`separation_reason`/`resignation_date`, and writes one `restored` audit row.

Frontend: `npm run build` compiles; live click-through QA by the user.

## Out of scope (YAGNI)

- No permanent/hard purge of archived employees (archive is the only removal; a true purge can be a later feature if needed).
- No bulk separate/restore.
- No change to the "Hide resigned" toggle's existing behavior (kept as-is for employees given a resignation date without being separated).
- No employee-level separation approval workflow.
