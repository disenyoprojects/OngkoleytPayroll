# Attendance Tab — View Any Date — Design

**Goal:** Let the admin Attendance tab show attendance for any chosen date, not just today, via a date picker with day steppers. Everything else (stat cards, table, adjust/approve) works as it does today, but for the selected date.

## Backend

`AttendanceDashboardController::today()` (route `GET /api/admin/attendance/today`, under `auth:sanctum`) gains an optional `date` query param:

- Read `date` from `$request->query('date', now()->toDateString())`; validate `date` as `date_format:Y-m-d`.
- Query `AttendanceRecord` for that date instead of the hardcoded `now()->toDateString()`.
- The four aggregates (`clocked_in`, `pending`, `approved`, `total_pay_today`) recompute for the selected date. Response shape is unchanged, plus the resolved `date` is echoed back so the frontend can confirm.
- The employee eager-load stays `withTrashed` (already in place), so a past date that includes since-separated staff still resolves their name/branch and pay.

Route path is unchanged (kept as `/attendance/today` to avoid breaking the existing frontend contract; it now simply defaults to today when no date is given).

## Frontend (`AttendanceView.jsx`)

- Add a `date` state defaulting to today (built from local getters, not `toISOString`, so Manila's date is correct).
- Next to the "Attendance" heading, add a **date input** (`type="date"`) and **‹ / ›** buttons that step one day back/forward.
- Fetch `/api/admin/attendance/today?date=${date}` whenever `date` changes.
- Stat-card label and empty-state generalize for non-today: "Total Pay Today" → "Total Pay" when the date isn't today; the empty row reads "No one clocked in on this date."
- The existing Edit Times / Approve actions and the premium label stay as-is (they operate on the record, independent of which date is shown).

## Testing

- Backend feature test: seeding records on two dates, `GET /api/admin/attendance/today?date=<dateA>` returns only dateA's rows; omitting `date` returns today's rows (default). Assert an aggregate (e.g. `clocked_in`) reflects the selected date.

## Out of scope (YAGNI)

- No date-range view (single day only, per the chosen approach).
- No change to the kiosk/clock-in flow or the per-staff monthly log (Employees → Log already covers per-person history).
- No new route; the existing dashboard endpoint is reused.
