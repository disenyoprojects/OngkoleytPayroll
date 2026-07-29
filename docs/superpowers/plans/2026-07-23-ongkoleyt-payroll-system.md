# Ongkoleyt Payroll System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the real Staff Attendance, Timesheet & Payroll System for Ongkoleyt (5 branches) — a Laravel API backend plus a React SPA frontend — replacing the mocked, in-memory `ongkoleyt-system-prototype.jsx` with a persisted, tested, audit-logged production system covering kiosk clock-in/out, staff self-service, admin attendance/payroll/13th-month management, and a unified audit log, per `Ongkoleyt-Proposal.docx` and `Ongkoleyt-Client-Handover.docx`.

**Architecture:** A Laravel 11 API (`backend/`) owns all business logic and persistence (MySQL). A Vite + React SPA (`frontend/`) consumes the API over JSON, reusing the visual design (colors, typography, layout) already validated in the prototype. Admins authenticate via Laravel Sanctum SPA cookie auth; kiosk/staff PIN sessions use short-lived signed tokens (no separate user accounts per employee).

**Tech Stack:** PHP 8.2+, Laravel 11, MySQL 8, Laravel Sanctum, barryvdh/laravel-dompdf (PDF export), PHPUnit (Laravel's default test runner). React 18, Vite, React Router, Axios. Node 18+.

## Global Constraints

- All money values: stored as `decimal(12,2)` in MySQL, computed server-side only — the frontend never re-derives pay, it only displays what the API returns. Source of truth mirrors the prototype's `computeDailyPay` / `computeThirteenthMonthRecord` formulas in `ongkoleyt-system-prototype.jsx`.
- 13th month formula (PD 851): `computedAmount = round(totalBasicEarned / 12, 2)`, where `totalBasicEarned` sums each worked month's included-earnings total (Basic Salary is mandatory and always included; other earning codes are opt-in via settings).
- Overtime multiplier default `1.25` (125%), night differential default `0.10` (+10%) applied to hours worked between 22:00–06:00, both configurable in Settings and applied everywhere (attendance, payroll, 13th month) — never hardcode these once Settings exists.
- Every attendance edit and every 13th-month adjustment/lock/unlock/release requires a non-empty `reason` and must write one `audit_logs` row. No silent corrections.
- Employment types: `regular`, `probationary`, `fixed_term`, `seasonal` (exact strings, matches prototype's `EMPLOYMENT_TYPES`).
- Branches (exact names, matches prototype's `BRANCHES`): `General Luna`, `Bonifacio`, `Diego Silang`, `La Trinidad`, `La Union`.
- Earning codes (matches prototype's `EARNING_CODES`): `BASIC`, `OVERTIME`, `NIGHT_DIFF`, `HOLIDAY_PREMIUM`, `ALLOWANCE`, `BONUS`, `INCENTIVE`, `COMMISSION`, `LEAVE_CONVERSION`.
- All API routes are under `/api`, JSON only, versioned implicitly (no `/v1` needed at this scale — YAGNI).
- Every backend task follows TDD: write the failing PHPUnit test, run it, implement, run again, commit.
- Project root for both apps is `ONGkoleyt Payroll-20260723T064142Z-1-001/` (this repo's top-level working directory). Backend lives in `backend/`, frontend in `frontend/`, existing source docs stay in `ONGkoleyt Payroll/`.

---

## Phase 0 — Scaffolding

### Task 1: Scaffold Laravel backend

**Files:**
- Create: `backend/` (via `composer create-project`)
- Modify: `backend/.env`
- Modify: `backend/config/cors.php`
- Modify: `backend/config/sanctum.php`

**Interfaces:**
- Produces: a running Laravel 11 app at `backend/`, MySQL connection configured, Sanctum installed, CORS configured to allow the frontend origin with credentials — every later backend task builds on this.

- [ ] **Step 1: Create the Laravel project**

```bash
cd "ONGkoleyt Payroll-20260723T064142Z-1-001"
composer create-project laravel/laravel backend "^11.0"
```

- [ ] **Step 2: Configure MySQL connection**

Edit `backend/.env`, set:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ongkoleyt_payroll
DB_USERNAME=root
DB_PASSWORD=
```

Create the database:

```bash
mysql -u root -e "CREATE DATABASE ongkoleyt_payroll CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

- [ ] **Step 3: Install Sanctum**

```bash
cd backend
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

- [ ] **Step 4: Configure CORS + Sanctum stateful domains**

Edit `backend/config/cors.php`:

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => ['http://localhost:5173'],
'allowed_origins_patterns' => [],
'allowed_headers' => ['*'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => true,
```

Edit `backend/config/sanctum.php`, set `stateful` to include the frontend dev origin:

```php
'stateful' => explode(',', env(
    'SANCTUM_STATEFUL_DOMAINS',
    'localhost,localhost:5173,127.0.0.1,127.0.0.1:5173'
)),
```

- [ ] **Step 5: Verify the server boots**

```bash
php artisan serve
```

Expected: `Server running on [http://127.0.0.1:8000]`, `curl http://127.0.0.1:8000/api/user` returns a 401 JSON response (proves API + Sanctum middleware are wired).

- [ ] **Step 6: Commit**

```bash
cd ..
git init
git add backend
git commit -m "chore: scaffold Laravel backend with Sanctum and MySQL"
```

### Task 2: Scaffold React frontend

**Files:**
- Create: `frontend/` (via `npm create vite@latest`)
- Create: `frontend/src/theme.js`
- Create: `frontend/src/api/client.js`
- Modify: `frontend/vite.config.js`

**Interfaces:**
- Consumes: nothing yet (backend Task 1 only needs to be running for manual smoke test).
- Produces: `THEME` (color/font tokens ported from the prototype's `COLOR`/`FONT_*` constants) and `apiClient` (configured Axios instance with `withCredentials: true`) — every later frontend task imports both.

- [ ] **Step 1: Create the Vite React project**

```bash
cd "ONGkoleyt Payroll-20260723T064142Z-1-001"
npm create vite@latest frontend -- --template react
cd frontend
npm install
npm install react-router-dom axios
```

- [ ] **Step 2: Port design tokens from the prototype**

Create `frontend/src/theme.js`:

```javascript
export const COLOR = {
  espresso: "#2E2118",
  espressoSoft: "#4A3728",
  gold: "#C9A227",
  cream: "#FAF6EC",
  parchment: "#F3EAD3",
  ink: "#221A13",
  inkSoft: "#7A6A57",
  rust: "#C1521F",
  rustSoft: "#F7E2D0",
  green: "#3F6B45",
  greenSoft: "#DDEBDD",
  amber: "#9A6B12",
  amberSoft: "#F3E7C8",
  line: "#E7DCC6",
};
export const FONT_DISPLAY = "'Fraunces', serif";
export const FONT_BODY = "'Inter', sans-serif";
export const FONT_MONO = "'IBM Plex Mono', monospace";

export function formatPHP(amount) {
  if (amount == null) return "—";
  return "₱" + Number(amount).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
export function formatTime12(hhmm24) {
  if (!hhmm24) return "—";
  let [h, m] = hhmm24.split(":").map(Number);
  const ampm = h >= 12 ? "PM" : "AM";
  let h12 = h % 12; if (h12 === 0) h12 = 12;
  return `${String(h12).padStart(2, "0")}:${String(m).padStart(2, "0")} ${ampm}`;
}
export function formatHoursLabel(totalHours) {
  const h = Math.floor(totalHours); const m = Math.round((totalHours - h) * 60);
  return `${h}h ${m}m`;
}
```

- [ ] **Step 3: Configure the API client**

Create `frontend/src/api/client.js`:

```javascript
import axios from "axios";

export const apiClient = axios.create({
  baseURL: "http://127.0.0.1:8000",
  withCredentials: true,
  headers: { Accept: "application/json" },
});

export async function ensureCsrf() {
  await apiClient.get("/sanctum/csrf-cookie");
}
```

- [ ] **Step 4: Verify the dev server boots**

```bash
npm run dev
```

Expected: Vite prints `Local: http://localhost:5173/`, browser shows the default Vite+React starter page with no console errors.

- [ ] **Step 5: Commit**

```bash
cd ..
git add frontend
git commit -m "chore: scaffold Vite React frontend with theme tokens and API client"
```

---

## Phase 1 — Data Layer

### Task 3: Branches, Employees, Payroll Settings

**Files:**
- Create: `backend/database/migrations/2026_07_23_000001_create_branches_table.php`
- Create: `backend/database/migrations/2026_07_23_000002_create_employees_table.php`
- Create: `backend/database/migrations/2026_07_23_000003_create_payroll_settings_table.php`
- Create: `backend/app/Models/Branch.php`
- Create: `backend/app/Models/Employee.php`
- Create: `backend/app/Models/PayrollSetting.php`
- Test: `backend/tests/Unit/PayrollSettingModelTest.php`

**Interfaces:**
- Produces: `Employee::branch()` relation, `Employee->pin` stored hashed, `PayrollSetting::current()` static accessor returning the single settings row (creating a default one if none exists) — every payroll/attendance/13th-month task reads settings through this.

- [ ] **Step 1: Write the migrations**

`backend/database/migrations/2026_07_23_000001_create_branches_table.php`:

```php
<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('branches');
    }
};
```

`backend/database/migrations/2026_07_23_000002_create_employees_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code')->unique();
            $table->string('full_name');
            $table->string('short_name');
            $table->string('role');
            $table->foreignId('branch_id')->constrained();
            $table->enum('employment_type', ['regular', 'probationary', 'fixed_term', 'seasonal']);
            $table->date('hire_date');
            $table->date('resignation_date')->nullable();
            $table->string('pin_hash');
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('employees');
    }
};
```

`backend/database/migrations/2026_07_23_000003_create_payroll_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('daily_basic_rate', 10, 2)->default(505.00);
            $table->unsignedTinyInteger('standard_working_days_per_month')->default(26);
            $table->decimal('overtime_multiplier', 4, 2)->default(1.25);
            $table->decimal('night_diff_multiplier', 4, 2)->default(0.10);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('release_date');
            $table->unsignedTinyInteger('minimum_months')->default(1);
            $table->json('included_earnings')->default(new \Illuminate\Database\Query\Expression("(JSON_ARRAY('BASIC'))"));
            $table->json('employment_types_included')->default(new \Illuminate\Database\Query\Expression("(JSON_ARRAY('regular','probationary','fixed_term','seasonal'))"));
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('payroll_settings');
    }
};
```

- [ ] **Step 2: Write the models**

`backend/app/Models/Branch.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model {
    protected $fillable = ['name'];

    public function employees() {
        return $this->hasMany(Employee::class);
    }
}
```

`backend/app/Models/Employee.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class Employee extends Model {
    protected $fillable = [
        'employee_code', 'full_name', 'short_name', 'role', 'branch_id',
        'employment_type', 'hire_date', 'resignation_date', 'pin_hash',
    ];
    protected $hidden = ['pin_hash'];
    protected $casts = [
        'hire_date' => 'date',
        'resignation_date' => 'date',
    ];

    public function branch() {
        return $this->belongsTo(Branch::class);
    }

    public function attendanceRecords() {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function earnings() {
        return $this->hasMany(EmployeeEarning::class);
    }

    public function thirteenthMonthRecords() {
        return $this->hasMany(ThirteenthMonthRecord::class);
    }

    public function setPinAttribute(string $pin): void {
        $this->attributes['pin_hash'] = Hash::make($pin);
    }

    public function verifyPin(string $pin): bool {
        return Hash::check($pin, $this->pin_hash);
    }

    public function isActiveDuring(int $month, int $year): bool {
        $hireMonth = (int) $this->hire_date->format('n');
        $hireYear = (int) $this->hire_date->format('Y');
        if ($year < $hireYear || ($year === $hireYear && $month < $hireMonth)) {
            return false;
        }
        if ($this->resignation_date) {
            $endMonth = (int) $this->resignation_date->format('n');
            $endYear = (int) $this->resignation_date->format('Y');
            if ($year > $endYear || ($year === $endYear && $month > $endMonth)) {
                return false;
            }
        }
        return true;
    }
}
```

`backend/app/Models/PayrollSetting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model {
    protected $fillable = [
        'daily_basic_rate', 'standard_working_days_per_month', 'overtime_multiplier',
        'night_diff_multiplier', 'period_start', 'period_end', 'release_date',
        'minimum_months', 'included_earnings', 'employment_types_included',
    ];
    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'release_date' => 'date',
        'included_earnings' => 'array',
        'employment_types_included' => 'array',
    ];

    public static function current(): self {
        $existing = static::first();
        if ($existing) {
            return $existing;
        }
        return static::create([
            'period_start' => now()->startOfYear(),
            'period_end' => now()->endOfYear(),
            'release_date' => now()->endOfYear(),
            'included_earnings' => ['BASIC'],
            'employment_types_included' => ['regular', 'probationary', 'fixed_term', 'seasonal'],
        ]);
    }
}
```

- [ ] **Step 3: Write the failing test**

`backend/tests/Unit/PayrollSettingModelTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\PayrollSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollSettingModelTest extends TestCase {
    use RefreshDatabase;

    public function test_current_creates_a_default_row_when_none_exists(): void {
        $this->assertSame(0, PayrollSetting::count());

        $settings = PayrollSetting::current();

        $this->assertSame(1, PayrollSetting::count());
        $this->assertSame(['BASIC'], $settings->included_earnings);
        $this->assertSame(505.0, (float) $settings->daily_basic_rate);
    }

    public function test_current_returns_the_existing_row_on_subsequent_calls(): void {
        $first = PayrollSetting::current();
        $second = PayrollSetting::current();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PayrollSetting::count());
    }
}
```

- [ ] **Step 4: Run migrations and the test**

```bash
cd backend
php artisan migrate
php artisan test --filter=PayrollSettingModelTest
```

Expected: `Tests: 2 passed`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Models tests/Unit/PayrollSettingModelTest.php
git commit -m "feat: add branches, employees, and payroll settings"
```

### Task 4: Attendance, employee earnings, 13th-month, and audit-log tables

**Files:**
- Create: `backend/database/migrations/2026_07_23_000004_create_attendance_records_table.php`
- Create: `backend/database/migrations/2026_07_23_000005_create_employee_earnings_table.php`
- Create: `backend/database/migrations/2026_07_23_000006_create_thirteenth_month_records_table.php`
- Create: `backend/database/migrations/2026_07_23_000007_create_audit_logs_table.php`
- Create: `backend/app/Models/AttendanceRecord.php`
- Create: `backend/app/Models/EmployeeEarning.php`
- Create: `backend/app/Models/ThirteenthMonthRecord.php`
- Create: `backend/app/Models/AuditLog.php`

**Interfaces:**
- Consumes: `Employee` model from Task 3.
- Produces: `AttendanceRecord` (one row per employee per work_date, `status` enum `pending`/`approved`), `EmployeeEarning` (one row per employee/month/year/code for non-attendance-derived pay like bonuses/allowances), `ThirteenthMonthRecord` (one row per employee per payroll year, `status` enum `pending`/`computed`/`locked`/`released`), `AuditLog` (polymorphic-by-`type` log row) — Phase 3–6 read/write all four.

- [ ] **Step 1: Write the migrations**

`backend/database/migrations/2026_07_23_000004_create_attendance_records_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->date('work_date');
            $table->time('shift_start')->default('08:00:00');
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->enum('status', ['pending', 'approved'])->default('pending');
            $table->boolean('adjusted')->default(false);
            $table->string('reason')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('attendance_records');
    }
};
```

`backend/database/migrations/2026_07_23_000005_create_employee_earnings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('employee_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->enum('code', [
                'HOLIDAY_PREMIUM', 'ALLOWANCE', 'BONUS', 'INCENTIVE', 'COMMISSION', 'LEAVE_CONVERSION',
            ]);
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('employee_earnings');
    }
};
```

`backend/database/migrations/2026_07_23_000006_create_thirteenth_month_records_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('thirteenth_month_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->unsignedSmallInteger('payroll_year');
            $table->decimal('computed_amount', 12, 2)->default(0);
            $table->decimal('manual_adjustment', 12, 2)->default(0);
            $table->enum('status', ['pending', 'computed', 'locked', 'released'])->default('pending');
            $table->date('released_on')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'payroll_year']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('thirteenth_month_records');
    }
};
```

`backend/database/migrations/2026_07_23_000007_create_audit_logs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['attendance', '13th_month']);
            $table->foreignId('employee_id')->nullable()->constrained();
            $table->foreignId('performed_by')->nullable()->constrained('users');
            $table->string('action');
            $table->text('detail')->nullable();
            $table->decimal('old_amount', 12, 2)->nullable();
            $table->decimal('new_amount', 12, 2)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('audit_logs');
    }
};
```

- [ ] **Step 2: Write the models**

`backend/app/Models/AttendanceRecord.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model {
    protected $fillable = [
        'employee_id', 'work_date', 'shift_start', 'clock_in', 'clock_out',
        'status', 'adjusted', 'reason', 'details',
    ];
    protected $casts = [
        'work_date' => 'date',
        'adjusted' => 'boolean',
    ];

    public function employee() {
        return $this->belongsTo(Employee::class);
    }
}
```

`backend/app/Models/EmployeeEarning.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeEarning extends Model {
    protected $fillable = ['employee_id', 'year', 'month', 'code', 'amount'];

    public function employee() {
        return $this->belongsTo(Employee::class);
    }
}
```

`backend/app/Models/ThirteenthMonthRecord.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThirteenthMonthRecord extends Model {
    protected $fillable = [
        'employee_id', 'payroll_year', 'computed_amount', 'manual_adjustment',
        'status', 'released_on', 'payment_method',
    ];
    protected $casts = [
        'released_on' => 'date',
    ];

    public function employee() {
        return $this->belongsTo(Employee::class);
    }

    public function getAdjustedAmountAttribute(): float {
        return round((float) $this->computed_amount + (float) $this->manual_adjustment, 2);
    }
}
```

`backend/app/Models/AuditLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model {
    protected $fillable = [
        'type', 'employee_id', 'performed_by', 'action', 'detail',
        'old_amount', 'new_amount', 'reason',
    ];

    public function employee() {
        return $this->belongsTo(Employee::class);
    }

    public function performedBy() {
        return $this->belongsTo(\App\Models\User::class, 'performed_by');
    }
}
```

- [ ] **Step 3: Run migrations**

```bash
php artisan migrate
```

Expected: all four new tables created, no errors.

- [ ] **Step 4: Commit**

```bash
git add database/migrations app/Models
git commit -m "feat: add attendance, employee earnings, 13th month, and audit log tables"
```

### Task 5: Factories and seeders (roster ported from the prototype)

**Files:**
- Create: `backend/database/factories/EmployeeFactory.php`
- Create: `backend/database/seeders/BranchSeeder.php`
- Create: `backend/database/seeders/EmployeeSeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Test: `backend/tests/Feature/SeederTest.php`

**Interfaces:**
- Consumes: `Branch`, `Employee`, `PayrollSetting` models from Task 3.
- Produces: a seeded database with the 5 branches and the 16-employee roster from the prototype's `STAFF_BASE`, every employee's demo PIN set to `1234` — used by every later feature test's `RefreshDatabase` + manual QA.

- [ ] **Step 1: Write the branch seeder**

`backend/database/seeders/BranchSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder {
    public function run(): void {
        foreach (['General Luna', 'Bonifacio', 'Diego Silang', 'La Trinidad', 'La Union'] as $name) {
            Branch::firstOrCreate(['name' => $name]);
        }
    }
}
```

- [ ] **Step 2: Write the employee seeder (roster ported from `STAFF_BASE` in the prototype)**

`backend/database/seeders/EmployeeSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder {
    private const ROSTER = [
        ['code' => 'ONG-1001', 'short' => 'Joshua Bacuyag', 'full' => 'Joshua Bacuyag', 'role' => 'Operations Manager', 'branch' => 'General Luna', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1002', 'short' => 'Kristen', 'full' => 'Kristen Nicole Urbano', 'role' => 'Brand Manager', 'branch' => 'General Luna', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1003', 'short' => 'Kyle', 'full' => 'Kyle Antazo', 'role' => 'Marketing & Investor Relations Officer', 'branch' => 'General Luna', 'type' => 'probationary', 'hireMonth' => 5, 'lastMonth' => 12],
        ['code' => 'ONG-1004', 'short' => 'Khirby', 'full' => 'Khirby Domingo', 'role' => 'Driver (Manual, knows Baguio & La Trinidad roads)', 'branch' => 'La Trinidad', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1005', 'short' => 'Summer', 'full' => 'Summer Dizon', 'role' => 'Barista (Female)', 'branch' => 'Bonifacio', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1006', 'short' => 'Jhon', 'full' => 'Jhon Alonzo', 'role' => 'Male Counter Staff', 'branch' => 'Diego Silang', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1007', 'short' => 'Jhon', 'full' => 'Jhon Ancheta', 'role' => 'Male Kitchen Staff', 'branch' => 'Diego Silang', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 9],
        ['code' => 'ONG-1008', 'short' => 'Jamiel Miclat', 'full' => 'Jamiel Miclat', 'role' => 'Marketing & Investor Relations Manager', 'branch' => 'General Luna', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1009', 'short' => 'Jhen', 'full' => 'Jhen Aquino', 'role' => 'Female Kitchen Staff', 'branch' => 'Bonifacio', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1010', 'short' => 'Jhen', 'full' => 'Jhen Navarro', 'role' => 'Female Counter Staff', 'branch' => 'La Trinidad', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1011', 'short' => 'Michiko', 'full' => 'Michiko Reyes', 'role' => 'Sales Agent (Knows how to drive)', 'branch' => 'La Union', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1012', 'short' => 'Michiko', 'full' => 'Michiko Ramos', 'role' => 'Market Coordinator', 'branch' => 'La Union', 'type' => 'seasonal', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1013', 'short' => 'Glenn', 'full' => 'Glenn Aspiras', 'role' => 'Male Kitchen Staff', 'branch' => 'Bonifacio', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
        ['code' => 'ONG-1014', 'short' => 'Rhea', 'full' => 'Rhea Ibarra', 'role' => 'Female Counter Staff', 'branch' => 'Diego Silang', 'type' => 'regular', 'hireMonth' => 1, 'lastMonth' => 12],
    ];

    public function run(): void {
        $year = now()->year;
        foreach (self::ROSTER as $row) {
            $branch = Branch::where('name', $row['branch'])->firstOrFail();
            $employee = Employee::firstOrNew(['employee_code' => $row['code']]);
            $employee->fill([
                'full_name' => $row['full'],
                'short_name' => $row['short'],
                'role' => $row['role'],
                'branch_id' => $branch->id,
                'employment_type' => $row['type'],
                'hire_date' => sprintf('%d-%02d-01', $year, $row['hireMonth']),
                'resignation_date' => $row['lastMonth'] < 12 ? sprintf('%d-%02d-28', $year, $row['lastMonth']) : null,
            ]);
            $employee->pin = '1234';
            $employee->save();
        }
    }
}
```

- [ ] **Step 3: Wire up `DatabaseSeeder`**

`backend/database/seeders/DatabaseSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\PayrollSetting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder {
    public function run(): void {
        $this->call([
            BranchSeeder::class,
            EmployeeSeeder::class,
        ]);
        PayrollSetting::current();
    }
}
```

- [ ] **Step 4: Write the failing test**

`backend/tests/Feature/SeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase {
    use RefreshDatabase;

    public function test_database_seeder_creates_branches_and_employees(): void {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, Branch::count());
        $this->assertSame(14, Employee::count());

        $employee = Employee::where('employee_code', 'ONG-1001')->firstOrFail();
        $this->assertTrue($employee->verifyPin('1234'));
        $this->assertFalse($employee->verifyPin('9999'));
    }
}
```

- [ ] **Step 5: Run the test**

```bash
php artisan test --filter=SeederTest
```

Expected: `Tests: 1 passed`.

- [ ] **Step 6: Commit**

```bash
git add database/factories database/seeders tests/Feature/SeederTest.php
git commit -m "feat: seed branches and employee roster with demo PIN"
```

---

## Phase 2 — Auth

### Task 6: Admin authentication (Sanctum SPA)

**Files:**
- Create: `backend/app/Http/Controllers/Auth/AdminSessionController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AdminSessionControllerTest.php`

**Interfaces:**
- Consumes: Laravel's default `User` model/migration (already present from `composer create-project`) and Sanctum's `EnsureFrontendRequestsAreStateful`.
- Produces: `POST /api/admin/login`, `POST /api/admin/logout`, `GET /api/admin/me` — every admin-only route in later phases is protected by the `auth:sanctum` middleware group established here.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/AdminSessionControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSessionControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_admin_can_log_in_with_correct_credentials(): void {
        $admin = User::factory()->create(['password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'secret123',
        ]);

        $response->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_login_fails_with_wrong_password(): void {
        $admin = User::factory()->create(['password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'wrong',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_me_endpoint_requires_authentication(): void {
        $this->getJson('/api/admin/me')->assertStatus(401);
    }

    public function test_me_endpoint_returns_the_authenticated_admin(): void {
        $admin = User::factory()->create();

        $this->actingAs($admin)->getJson('/api/admin/me')
            ->assertOk()
            ->assertJsonPath('email', $admin->email);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=AdminSessionControllerTest
```

Expected: FAIL — route `/api/admin/login` not found.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Auth/AdminSessionController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AdminSessionController extends Controller {
    public function login(Request $request) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['message' => 'Logged in.']);
    }

    public function logout(Request $request) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request) {
        return response()->json($request->user());
    }
}
```

- [ ] **Step 4: Wire the routes**

`backend/routes/api.php` — add:

```php
use App\Http\Controllers\Auth\AdminSessionController;
use Illuminate\Support\Facades\Route;

Route::post('/admin/login', [AdminSessionController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/admin/logout', [AdminSessionController::class, 'logout']);
    Route::get('/admin/me', [AdminSessionController::class, 'me']);
});
```

- [ ] **Step 5: Run the tests and make sure they pass**

```bash
php artisan test --filter=AdminSessionControllerTest
```

Expected: `Tests: 4 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Auth routes/api.php tests/Feature/AdminSessionControllerTest.php
git commit -m "feat: add admin login/logout/me via Sanctum SPA auth"
```

### Task 7: Kiosk employee lookup and PIN verification

**Files:**
- Create: `backend/app/Services/KioskTokenService.php`
- Create: `backend/app/Http/Controllers/Kiosk/KioskAuthController.php`
- Create: `backend/app/Http/Middleware/EnsureValidKioskToken.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/bootstrap/app.php`
- Test: `backend/tests/Feature/KioskAuthControllerTest.php`
- Test: `backend/tests/Unit/KioskTokenServiceTest.php`

**Interfaces:**
- Consumes: `Employee::verifyPin()` from Task 3.
- Produces: `GET /api/kiosk/staff` (public roster for the name-picker), `POST /api/kiosk/verify-pin` (returns a signed, 10-minute kiosk token), `KioskTokenService::issue(Employee $employee): string` / `resolve(string $token): ?Employee`, and the `kiosk.token` middleware alias — Phase 3's staff-dashboard and clock endpoints require this token.

- [ ] **Step 1: Write the failing unit test for the token service**

`backend/tests/Unit/KioskTokenServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Employee;
use App\Services\KioskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskTokenServiceTest extends TestCase {
    use RefreshDatabase;

    public function test_issued_token_resolves_back_to_the_same_employee(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $service = new KioskTokenService();

        $token = $service->issue($employee);
        $resolved = $service->resolve($token);

        $this->assertSame($employee->id, $resolved->id);
    }

    public function test_a_tampered_token_does_not_resolve(): void {
        $service = new KioskTokenService();

        $this->assertNull($service->resolve('not-a-real-token'));
    }

    public function test_an_expired_token_does_not_resolve(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $service = new KioskTokenService();

        $this->travelTo(now()->subMinutes(11));
        $token = $service->issue($employee);
        $this->travelBack();

        $this->assertNull($service->resolve($token));
    }
}
```

Note: this requires `EmployeeFactory` and `BranchFactory`. Add minimal factories:

`backend/database/factories/BranchFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchFactory extends Factory {
    protected $model = Branch::class;

    public function definition(): array {
        return ['name' => $this->faker->unique()->city()];
    }
}
```

`backend/database/factories/EmployeeFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory {
    protected $model = Employee::class;

    public function definition(): array {
        return [
            'employee_code' => 'EMP-' . $this->faker->unique()->numberBetween(1000, 9999),
            'full_name' => $this->faker->name(),
            'short_name' => $this->faker->firstName(),
            'role' => 'Counter Staff',
            'branch_id' => Branch::factory(),
            'employment_type' => 'regular',
            'hire_date' => now()->startOfYear(),
            'resignation_date' => null,
            'pin' => '1234',
        ];
    }
}
```

Add `use Illuminate\Database\Eloquent\Factories\HasFactory;` and `use HasFactory;` to `Employee` and `Branch` models.

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=KioskTokenServiceTest
```

Expected: FAIL — class `App\Services\KioskTokenService` not found.

- [ ] **Step 3: Write the token service**

`backend/app/Services/KioskTokenService.php`:

```php
<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

class KioskTokenService {
    private const TTL_MINUTES = 10;

    public function issue(Employee $employee): string {
        return Crypt::encryptString(json_encode([
            'employee_id' => $employee->id,
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES)->timestamp,
        ]));
    }

    public function resolve(string $token): ?Employee {
        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload) || Carbon::now()->timestamp > ($payload['expires_at'] ?? 0)) {
            return null;
        }

        return Employee::find($payload['employee_id'] ?? null);
    }
}
```

- [ ] **Step 4: Run the unit test again**

```bash
php artisan test --filter=KioskTokenServiceTest
```

Expected: `Tests: 3 passed`.

- [ ] **Step 5: Write the failing feature test for the controller**

`backend/tests/Feature/KioskAuthControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskAuthControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_staff_list_returns_names_without_pin_hashes(): void {
        Employee::factory()->for(Branch::factory())->create(['short_name' => 'Summer']);

        $response = $this->getJson('/api/kiosk/staff');

        $response->assertOk();
        $response->assertJsonMissingPath('0.pin_hash');
        $this->assertSame('Summer', $response->json('0.short_name'));
    }

    public function test_correct_pin_returns_a_kiosk_token(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $response = $this->postJson('/api/kiosk/verify-pin', [
            'employee_id' => $employee->id,
            'pin' => '1234',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_incorrect_pin_is_rejected(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $response = $this->postJson('/api/kiosk/verify-pin', [
            'employee_id' => $employee->id,
            'pin' => '0000',
        ]);

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 6: Run it to verify it fails**

```bash
php artisan test --filter=KioskAuthControllerTest
```

Expected: FAIL — route `/api/kiosk/staff` not found.

- [ ] **Step 7: Write the controller and middleware**

`backend/app/Http/Controllers/Kiosk/KioskAuthController.php`:

```php
<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\KioskTokenService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KioskAuthController extends Controller {
    public function __construct(private KioskTokenService $tokens) {}

    public function staff() {
        return response()->json(
            Employee::whereNull('resignation_date')
                ->orWhere('resignation_date', '>=', now())
                ->with('branch')
                ->orderBy('short_name')
                ->get(['id', 'short_name', 'full_name', 'role', 'branch_id'])
        );
    }

    public function verifyPin(Request $request) {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'pin' => ['required', 'string', 'size:4'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);

        if (! $employee->verifyPin($data['pin'])) {
            throw ValidationException::withMessages(['pin' => ['Incorrect PIN.']]);
        }

        return response()->json(['token' => $this->tokens->issue($employee)]);
    }
}
```

`backend/app/Http/Middleware/EnsureValidKioskToken.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Services\KioskTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidKioskToken {
    public function __construct(private KioskTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response {
        $token = $request->bearerToken();
        $employee = $token ? $this->tokens->resolve($token) : null;

        if (! $employee) {
            return response()->json(['message' => 'Invalid or expired kiosk session.'], 401);
        }

        $request->attributes->set('kiosk_employee', $employee);

        return $next($request);
    }
}
```

Register the middleware alias in `backend/bootstrap/app.php`, inside `->withMiddleware(function (Middleware $middleware) { ... })`:

```php
$middleware->alias([
    'kiosk.token' => \App\Http\Middleware\EnsureValidKioskToken::class,
]);
```

- [ ] **Step 8: Wire the routes**

`backend/routes/api.php` — add:

```php
use App\Http\Controllers\Kiosk\KioskAuthController;

Route::get('/kiosk/staff', [KioskAuthController::class, 'staff']);
Route::post('/kiosk/verify-pin', [KioskAuthController::class, 'verifyPin']);
```

- [ ] **Step 9: Run the tests and make sure they pass**

```bash
php artisan test --filter=KioskAuthControllerTest
```

Expected: `Tests: 3 passed`.

- [ ] **Step 10: Commit**

```bash
git add app/Services app/Http/Controllers/Kiosk app/Http/Middleware database/factories routes/api.php bootstrap/app.php tests
git commit -m "feat: add kiosk staff list, PIN verification, and signed kiosk tokens"
```

---

## Phase 3 — Attendance Core Service & API

### Task 8: `AttendancePayCalculator` service

**Files:**
- Create: `backend/app/Services/AttendancePayCalculator.php`
- Test: `backend/tests/Unit/AttendancePayCalculatorTest.php`

**Interfaces:**
- Consumes: `PayrollSetting` (daily_basic_rate, overtime_multiplier, night_diff_multiplier).
- Produces: `AttendancePayCalculator::compute(?string $clockIn, ?string $clockOut, PayrollSetting $settings): ?array` returning `['total_hours', 'regular_hours', 'ot_hours', 'night_diff_hours', 'basic', 'ot', 'night_diff', 'total']` — this is the single source of truth for daily pay math, consumed by Task 9 (clock actions), Task 11 (attendance dashboard), and Task 12 (payroll views). Mirrors `computeDailyPay` in the prototype.

- [ ] **Step 1: Write the failing test**

`backend/tests/Unit/AttendancePayCalculatorTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendancePayCalculatorTest extends TestCase {
    use RefreshDatabase;

    private function settings(): PayrollSetting {
        return PayrollSetting::current(); // daily_basic_rate 505, OT 1.25, night diff 0.10
    }

    public function test_returns_null_when_clock_out_is_missing(): void {
        $result = (new AttendancePayCalculator())->compute('08:00', null, $this->settings());

        $this->assertNull($result);
    }

    public function test_computes_a_standard_eight_hour_shift(): void {
        $result = (new AttendancePayCalculator())->compute('08:00', '17:00', $this->settings());

        $this->assertSame(9.0, $result['total_hours']);
        $this->assertSame(8.0, $result['regular_hours']);
        $this->assertSame(1.0, $result['ot_hours']);
        $hourlyRate = 505 / 8;
        $this->assertEqualsWithDelta(round($hourlyRate * 8, 2), $result['basic'], 0.01);
        $this->assertEqualsWithDelta(round($hourlyRate * 1 * 1.25, 2), $result['ot'], 0.01);
    }

    public function test_computes_night_differential_for_hours_between_10pm_and_6am(): void {
        $result = (new AttendancePayCalculator())->compute('20:00', '23:00', $this->settings());

        $this->assertEqualsWithDelta(1.0, $result['night_diff_hours'], 0.01);
        $hourlyRate = 505 / 8;
        $this->assertEqualsWithDelta(round($hourlyRate * 1 * 0.10, 2), $result['night_diff'], 0.01);
    }

    public function test_handles_a_shift_that_crosses_midnight(): void {
        $result = (new AttendancePayCalculator())->compute('22:00', '02:00', $this->settings());

        $this->assertSame(4.0, $result['total_hours']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=AttendancePayCalculatorTest
```

Expected: FAIL — class `App\Services\AttendancePayCalculator` not found.

- [ ] **Step 3: Write the implementation**

`backend/app/Services/AttendancePayCalculator.php`:

```php
<?php

namespace App\Services;

use App\Models\PayrollSetting;

class AttendancePayCalculator {
    public function compute(?string $clockIn, ?string $clockOut, PayrollSetting $settings): ?array {
        if (! $clockIn || ! $clockOut) {
            return null;
        }

        $start = $this->minutesOf($clockIn);
        $end = $this->minutesOf($clockOut);
        if ($end <= $start) {
            $end += 24 * 60;
        }

        $totalHours = ($end - $start) / 60;
        $hourlyRate = (float) $settings->daily_basic_rate / 8;
        $regularHours = min($totalHours, 8);
        $otHours = max(0, $totalHours - 8);

        $nightStart = 22 * 60;
        $nightEnd = 24 * 60 + 6 * 60;
        $overlapStart = max($start, $nightStart);
        $overlapEnd = min($end, $nightEnd);
        $nightDiffHours = max(0, ($overlapEnd - $overlapStart) / 60);

        $basic = round($regularHours * $hourlyRate, 2);
        $ot = round($otHours * $hourlyRate * (float) $settings->overtime_multiplier, 2);
        $nightDiff = round($nightDiffHours * $hourlyRate * (float) $settings->night_diff_multiplier, 2);
        $total = round($basic + $ot + $nightDiff, 2);

        return [
            'total_hours' => $totalHours,
            'regular_hours' => $regularHours,
            'ot_hours' => $otHours,
            'night_diff_hours' => $nightDiffHours,
            'basic' => $basic,
            'ot' => $ot,
            'night_diff' => $nightDiff,
            'total' => $total,
        ];
    }

    private function minutesOf(string $hhmm): int {
        [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
        return $h * 60 + $m;
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

```bash
php artisan test --filter=AttendancePayCalculatorTest
```

Expected: `Tests: 4 passed`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AttendancePayCalculator.php tests/Unit/AttendancePayCalculatorTest.php
git commit -m "feat: add AttendancePayCalculator (basic, OT, night differential)"
```

### Task 9: Kiosk clock in/out endpoints

**Files:**
- Create: `backend/app/Http/Controllers/Kiosk/AttendanceClockController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AttendanceClockControllerTest.php`

**Interfaces:**
- Consumes: `kiosk.token` middleware (Task 7) supplying `$request->attributes->get('kiosk_employee')`, `AttendanceRecord` model (Task 4).
- Produces: `POST /api/kiosk/clock-in`, `POST /api/kiosk/clock-out`, `GET /api/kiosk/today` (staff dashboard's "Today" card reads this in Task 21).

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/AttendanceClockControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\KioskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceClockControllerTest extends TestCase {
    use RefreshDatabase;

    private function tokenFor(Employee $employee): string {
        return app(KioskTokenService::class)->issue($employee);
    }

    public function test_clock_in_creates_a_pending_record_for_today(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $response = $this->withToken($this->tokenFor($employee))
            ->postJson('/api/kiosk/clock-in');

        $response->assertOk();
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'status' => 'pending',
        ]);
    }

    public function test_clock_in_twice_in_a_day_is_rejected(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $token = $this->tokenFor($employee);

        $this->withToken($token)->postJson('/api/kiosk/clock-in')->assertOk();
        $response = $this->withToken($token)->postJson('/api/kiosk/clock-in');

        $response->assertStatus(422);
        $this->assertSame(1, AttendanceRecord::where('employee_id', $employee->id)->count());
    }

    public function test_clock_out_fills_in_clock_out_time(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $token = $this->tokenFor($employee);
        $this->withToken($token)->postJson('/api/kiosk/clock-in');

        $response = $this->withToken($token)->postJson('/api/kiosk/clock-out');

        $response->assertOk();
        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($record->clock_out);
    }

    public function test_clock_out_without_clocking_in_is_rejected(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();

        $response = $this->withToken($this->tokenFor($employee))->postJson('/api/kiosk/clock-out');

        $response->assertStatus(422);
    }

    public function test_endpoints_reject_missing_or_invalid_kiosk_token(): void {
        $this->postJson('/api/kiosk/clock-in')->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=AttendanceClockControllerTest
```

Expected: FAIL — route `/api/kiosk/clock-in` not found.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Kiosk/AttendanceClockController.php`:

```php
<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AttendanceClockController extends Controller {
    public function clockIn(Request $request) {
        /** @var Employee $employee */
        $employee = $request->attributes->get('kiosk_employee');
        $today = now()->toDateString();

        if (AttendanceRecord::where('employee_id', $employee->id)->where('work_date', $today)->exists()) {
            throw ValidationException::withMessages(['clock_in' => ['Already clocked in today.']]);
        }

        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => $today,
            'clock_in' => now()->format('H:i:s'),
            'status' => 'pending',
        ]);

        return response()->json($record);
    }

    public function clockOut(Request $request) {
        /** @var Employee $employee */
        $employee = $request->attributes->get('kiosk_employee');
        $today = now()->toDateString();

        $record = AttendanceRecord::where('employee_id', $employee->id)->where('work_date', $today)->first();

        if (! $record || $record->clock_out) {
            throw ValidationException::withMessages(['clock_out' => ['No open clock-in found for today.']]);
        }

        $record->update(['clock_out' => now()->format('H:i:s'), 'status' => 'pending']);

        return response()->json($record);
    }

    public function today(Request $request) {
        /** @var Employee $employee */
        $employee = $request->attributes->get('kiosk_employee');
        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->where('work_date', now()->toDateString())
            ->first();

        return response()->json($record);
    }
}
```

- [ ] **Step 4: Wire the routes**

`backend/routes/api.php` — add:

```php
use App\Http\Controllers\Kiosk\AttendanceClockController;

Route::middleware('kiosk.token')->group(function () {
    Route::post('/kiosk/clock-in', [AttendanceClockController::class, 'clockIn']);
    Route::post('/kiosk/clock-out', [AttendanceClockController::class, 'clockOut']);
    Route::get('/kiosk/today', [AttendanceClockController::class, 'today']);
});
```

- [ ] **Step 5: Run the tests and make sure they pass**

```bash
php artisan test --filter=AttendanceClockControllerTest
```

Expected: `Tests: 5 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Kiosk/AttendanceClockController.php routes/api.php tests/Feature/AttendanceClockControllerTest.php
git commit -m "feat: add kiosk clock-in/clock-out endpoints"
```

### Task 10: Attendance adjustment and approval (with audit log)

**Files:**
- Create: `backend/app/Http/Controllers/Admin/AttendanceAdminController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AttendanceAdminControllerTest.php`

**Interfaces:**
- Consumes: `auth:sanctum` middleware (Task 6), `AttendanceRecord`, `AuditLog` models.
- Produces: `PATCH /api/admin/attendance/{record}/adjust`, `POST /api/admin/attendance/{record}/approve` — every adjustment writes one `AuditLog` row with `type = 'attendance'`, `action = 'adjust'`, before/after detail string, and the submitted `reason`. Task 20's unified audit log view reads these rows.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/AttendanceAdminControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAdminControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_admin_can_adjust_attendance_with_a_reason(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create([
            'clock_in' => '08:10:00', 'clock_out' => '17:00:00', 'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->patchJson("/api/admin/attendance/{$record->id}/adjust", [
            'clock_in' => '08:00',
            'clock_out' => '17:00',
            'reason' => 'Forgot to Clock In/Out',
            'details' => 'Confirmed via CCTV',
        ]);

        $response->assertOk();
        $record->refresh();
        $this->assertSame('08:00:00', $record->clock_in);
        $this->assertTrue($record->adjusted);
        $this->assertSame('approved', $record->status);
        $this->assertSame(1, AuditLog::where('type', 'attendance')->where('action', 'adjust')->count());
    }

    public function test_adjustment_without_a_reason_is_rejected(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create();

        $response = $this->actingAs($admin)->patchJson("/api/admin/attendance/{$record->id}/adjust", [
            'clock_in' => '08:00',
            'clock_out' => '17:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_approve_a_pending_record(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create(['status' => 'pending']);

        $response = $this->actingAs($admin)->postJson("/api/admin/attendance/{$record->id}/approve");

        $response->assertOk();
        $this->assertSame('approved', $record->fresh()->status);
    }

    public function test_endpoints_require_admin_authentication(): void {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = AttendanceRecord::factory()->for($employee)->create();

        $this->patchJson("/api/admin/attendance/{$record->id}/adjust", ['clock_in' => '08:00', 'clock_out' => '17:00', 'reason' => 'x'])
            ->assertStatus(401);
    }
}
```

Add `backend/database/factories/AttendanceRecordFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceRecordFactory extends Factory {
    protected $model = AttendanceRecord::class;

    public function definition(): array {
        return [
            'employee_id' => Employee::factory(),
            'work_date' => now()->toDateString(),
            'shift_start' => '08:00:00',
            'clock_in' => '08:00:00',
            'clock_out' => null,
            'status' => 'pending',
        ];
    }
}
```

Add `use HasFactory;` to `AttendanceRecord`.

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=AttendanceAdminControllerTest
```

Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Admin/AttendanceAdminController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AttendanceAdminController extends Controller {
    public function adjust(Request $request, AttendanceRecord $record) {
        $data = $request->validate([
            'clock_in' => ['required', 'date_format:H:i'],
            'clock_out' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string'],
            'details' => ['nullable', 'string'],
        ]);

        $before = sprintf('%s → %s', $record->clock_in ?? '—', $record->clock_out ?? '—');

        $record->update([
            'clock_in' => $data['clock_in'],
            'clock_out' => $data['clock_out'],
            'status' => 'approved',
            'adjusted' => true,
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
        ]);

        AuditLog::create([
            'type' => 'attendance',
            'employee_id' => $record->employee_id,
            'performed_by' => $request->user()->id,
            'action' => 'adjust',
            'detail' => "{$before} became {$data['clock_in']} → {$data['clock_out']}",
            'reason' => $data['reason'],
        ]);

        return response()->json($record);
    }

    public function approve(Request $request, AttendanceRecord $record) {
        $record->update(['status' => 'approved']);

        return response()->json($record);
    }
}
```

- [ ] **Step 4: Wire the routes**

`backend/routes/api.php` — add inside the `auth:sanctum` group from Task 6:

```php
use App\Http\Controllers\Admin\AttendanceAdminController;

Route::patch('/admin/attendance/{record}/adjust', [AttendanceAdminController::class, 'adjust']);
Route::post('/admin/attendance/{record}/approve', [AttendanceAdminController::class, 'approve']);
```

- [ ] **Step 5: Run the tests and make sure they pass**

```bash
php artisan test --filter=AttendanceAdminControllerTest
```

Expected: `Tests: 4 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/AttendanceAdminController.php database/factories/AttendanceRecordFactory.php routes/api.php tests/Feature/AttendanceAdminControllerTest.php
git commit -m "feat: add attendance adjustment and approval with audit logging"
```

### Task 11: Admin attendance dashboard endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Admin/AttendanceDashboardController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AttendanceDashboardControllerTest.php`

**Interfaces:**
- Consumes: `AttendancePayCalculator` (Task 8), `AttendanceRecord`, `PayrollSetting::current()`.
- Produces: `GET /api/admin/attendance/today` returning `{ clocked_in, pending, approved, total_pay_today, rows: [...] }` where each row includes the employee, record, and computed pay — Task 24's admin attendance view renders this directly.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/AttendanceDashboardControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceDashboardControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_returns_todays_stats_and_rows(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();
        $approved = Employee::factory()->for($branch)->create();
        $pending = Employee::factory()->for($branch)->create();

        AttendanceRecord::factory()->for($approved)->create(['clock_in' => '08:00:00', 'clock_out' => '17:00:00', 'status' => 'approved']);
        AttendanceRecord::factory()->for($pending)->create(['clock_in' => '08:00:00', 'clock_out' => null, 'status' => 'pending']);

        $response = $this->actingAs($admin)->getJson('/api/admin/attendance/today');

        $response->assertOk();
        $response->assertJsonPath('clocked_in', 2);
        $response->assertJsonPath('pending', 1);
        $response->assertJsonPath('approved', 1);
        $this->assertCount(2, $response->json('rows'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=AttendanceDashboardControllerTest
```

Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Admin/AttendanceDashboardController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;

class AttendanceDashboardController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function today() {
        $settings = PayrollSetting::current();
        $records = AttendanceRecord::with('employee.branch')
            ->where('work_date', now()->toDateString())
            ->get();

        $rows = $records->map(function (AttendanceRecord $record) use ($settings) {
            $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings);
            return [
                'record' => $record,
                'employee' => $record->employee,
                'pay' => $pay,
            ];
        });

        return response()->json([
            'clocked_in' => $records->count(),
            'pending' => $records->where('status', 'pending')->count(),
            'approved' => $records->where('status', 'approved')->count(),
            'total_pay_today' => round($rows->sum(fn ($r) => $r['pay']['total'] ?? 0), 2),
            'rows' => $rows->values(),
        ]);
    }
}
```

- [ ] **Step 4: Wire the route**

`backend/routes/api.php` — add inside the `auth:sanctum` group:

```php
use App\Http\Controllers\Admin\AttendanceDashboardController;

Route::get('/admin/attendance/today', [AttendanceDashboardController::class, 'today']);
```

- [ ] **Step 5: Run the tests and make sure they pass**

```bash
php artisan test --filter=AttendanceDashboardControllerTest
```

Expected: `Tests: 1 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/AttendanceDashboardController.php routes/api.php tests/Feature/AttendanceDashboardControllerTest.php
git commit -m "feat: add admin attendance dashboard endpoint"
```

---

## Phase 4 — Payroll

### Task 12: Daily & weekly payroll endpoints

**Files:**
- Create: `backend/app/Http/Controllers/Admin/PayrollController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/PayrollControllerTest.php`

**Interfaces:**
- Consumes: `AttendancePayCalculator` (Task 8), `AttendanceRecord`, `PayrollSetting::current()`.
- Produces: `GET /api/admin/payroll/daily?date=YYYY-MM-DD`, `GET /api/admin/payroll/weekly?start=YYYY-MM-DD` (7-day window starting `start`) — both return `{ rows: [...], total }`. Task 25's admin payroll view and Task 13's CSV export consume these.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/PayrollControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_daily_payroll_only_includes_completed_shifts(): void {
        $admin = User::factory()->create();
        $branch = Branch::factory()->create();
        $complete = Employee::factory()->for($branch)->create();
        $incomplete = Employee::factory()->for($branch)->create();

        AttendanceRecord::factory()->for($complete)->create(['work_date' => '2026-07-21', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        AttendanceRecord::factory()->for($incomplete)->create(['work_date' => '2026-07-21', 'clock_in' => '08:00:00', 'clock_out' => null]);

        $response = $this->actingAs($admin)->getJson('/api/admin/payroll/daily?date=2026-07-21');

        $response->assertOk();
        $this->assertCount(1, $response->json('rows'));
    }

    public function test_weekly_payroll_aggregates_seven_days(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();

        foreach (['2026-07-15', '2026-07-16', '2026-07-17'] as $date) {
            AttendanceRecord::factory()->for($employee)->create(['work_date' => $date, 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        }

        $response = $this->actingAs($admin)->getJson('/api/admin/payroll/weekly?start=2026-07-15');

        $response->assertOk();
        $row = collect($response->json('rows'))->firstWhere('employee_id', $employee->id);
        $this->assertSame(3, $row['days_worked']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=PayrollControllerTest
```

Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Admin/PayrollController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PayrollController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function daily(Request $request) {
        $date = $request->query('date', now()->toDateString());
        $settings = PayrollSetting::current();

        $rows = AttendanceRecord::with('employee.branch')
            ->where('work_date', $date)
            ->whereNotNull('clock_out')
            ->get()
            ->map(function (AttendanceRecord $record) use ($settings) {
                $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings);
                return [
                    'employee_id' => $record->employee_id,
                    'employee' => $record->employee,
                    'record' => $record,
                    'pay' => $pay,
                ];
            });

        return response()->json([
            'rows' => $rows->values(),
            'total' => round($rows->sum(fn ($r) => $r['pay']['total']), 2),
        ]);
    }

    public function weekly(Request $request) {
        $start = Carbon::parse($request->query('start', now()->startOfWeek()->toDateString()));
        $end = $start->copy()->addDays(6);
        $settings = PayrollSetting::current();

        $records = AttendanceRecord::with('employee.branch')
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('clock_out')
            ->get()
            ->groupBy('employee_id');

        $rows = $records->map(function ($employeeRecords) use ($settings) {
            $pays = $employeeRecords->map(fn (AttendanceRecord $r) => $this->calculator->compute($r->clock_in, $r->clock_out, $settings));
            return [
                'employee_id' => $employeeRecords->first()->employee_id,
                'employee' => $employeeRecords->first()->employee,
                'days_worked' => $employeeRecords->count(),
                'total_hours' => round($pays->sum('total_hours'), 2),
                'basic' => round($pays->sum('basic'), 2),
                'ot' => round($pays->sum('ot'), 2),
                'total' => round($pays->sum('total'), 2),
            ];
        })->values();

        return response()->json([
            'rows' => $rows,
            'total' => round($rows->sum('total'), 2),
        ]);
    }
}
```

- [ ] **Step 4: Wire the routes**

`backend/routes/api.php` — add inside the `auth:sanctum` group:

```php
use App\Http\Controllers\Admin\PayrollController;

Route::get('/admin/payroll/daily', [PayrollController::class, 'daily']);
Route::get('/admin/payroll/weekly', [PayrollController::class, 'weekly']);
```

- [ ] **Step 5: Run the tests and make sure they pass**

```bash
php artisan test --filter=PayrollControllerTest
```

Expected: `Tests: 2 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/PayrollController.php routes/api.php tests/Feature/PayrollControllerTest.php
git commit -m "feat: add daily and weekly payroll endpoints"
```

### Task 13: CSV export endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Admin/PayrollExportController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/PayrollExportControllerTest.php`

**Interfaces:**
- Consumes: the same query logic as Task 12 (`PayrollController`) — refactor is unnecessary at this scale (YAGNI); this controller re-queries directly.
- Produces: `GET /api/admin/payroll/export?range=daily|weekly&date=...` streaming a `text/csv` download. Task 25's "⬇ CSV" button hits this.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/PayrollExportControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollExportControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_daily_csv_export_contains_a_header_and_one_row_per_employee(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['short_name' => 'Summer']);
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-21', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        $response = $this->actingAs($admin)->get('/api/admin/payroll/export?range=daily&date=2026-07-21');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Summer', $response->streamedContent());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=PayrollExportControllerTest
```

Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Admin/PayrollExportController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollExportController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function export(Request $request): StreamedResponse {
        $range = $request->query('range', 'daily');
        $date = $request->query('date', now()->toDateString());
        $settings = PayrollSetting::current();

        $records = $range === 'daily'
            ? AttendanceRecord::with('employee.branch')->where('work_date', $date)->whereNotNull('clock_out')->get()
            : AttendanceRecord::with('employee.branch')->whereBetween('work_date', [$date, now()->parse($date)->addDays(6)->toDateString()])->whereNotNull('clock_out')->get();

        $filename = "payroll-{$range}-{$date}.csv";

        return response()->streamDownload(function () use ($records, $settings) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Staff', 'Role', 'Branch', 'Clock In', 'Clock Out', 'Basic', 'OT', 'Night Diff', 'Total Pay', 'Status']);
            foreach ($records as $record) {
                $pay = $this->calculator->compute($record->clock_in, $record->clock_out, $settings);
                fputcsv($handle, [
                    $record->employee->short_name,
                    $record->employee->role,
                    $record->employee->branch->name,
                    $record->clock_in,
                    $record->clock_out,
                    $pay['basic'] ?? 0,
                    $pay['ot'] ?? 0,
                    $pay['night_diff'] ?? 0,
                    $pay['total'] ?? 0,
                    $record->status,
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
```

- [ ] **Step 4: Wire the route**

`backend/routes/api.php` — add inside the `auth:sanctum` group:

```php
use App\Http\Controllers\Admin\PayrollExportController;

Route::get('/admin/payroll/export', [PayrollExportController::class, 'export']);
```

- [ ] **Step 5: Run the tests and make sure they pass**

```bash
php artisan test --filter=PayrollExportControllerTest
```

Expected: `Tests: 1 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/PayrollExportController.php routes/api.php tests/Feature/PayrollExportControllerTest.php
git commit -m "feat: add payroll CSV export"
```

### Task 14: PDF export (payroll and payslips)

**Files:**
- Modify: `backend/composer.json` (via composer require)
- Create: `backend/resources/views/pdf/payroll.blade.php`
- Create: `backend/app/Http/Controllers/Admin/PayrollPdfController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/PayrollPdfControllerTest.php`

**Interfaces:**
- Consumes: same query as Task 12.
- Produces: `GET /api/admin/payroll/pdf?range=daily&date=...` returning `application/pdf`. Task 18 reuses the same `barryvdh/laravel-dompdf` dependency for 13th-month payslips.

- [ ] **Step 1: Install the PDF package**

```bash
cd backend
composer require barryvdh/laravel-dompdf
```

- [ ] **Step 2: Write the failing test**

`backend/tests/Feature/PayrollPdfControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollPdfControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_daily_pdf_export_returns_a_pdf_document(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-07-21', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        $response = $this->actingAs($admin)->get('/api/admin/payroll/pdf?range=daily&date=2026-07-21');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

```bash
php artisan test --filter=PayrollPdfControllerTest
```

Expected: FAIL — route not found.

- [ ] **Step 4: Write the Blade template**

`backend/resources/views/pdf/payroll.blade.php`:

```blade
<html>
<head>
<style>
body { font-family: sans-serif; font-size: 12px; }
table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
</style>
</head>
<body>
<h2>Ongkoleyt Payroll — {{ ucfirst($range) }} — {{ $date }}</h2>
<table>
<thead>
<tr><th>Staff</th><th>Role</th><th>Branch</th><th>Total Pay</th></tr>
</thead>
<tbody>
@foreach ($rows as $row)
<tr>
<td>{{ $row['employee']->short_name }}</td>
<td>{{ $row['employee']->role }}</td>
<td>{{ $row['employee']->branch->name }}</td>
<td>{{ number_format($row['pay']['total'], 2) }}</td>
</tr>
@endforeach
</tbody>
</table>
<p><strong>Total: {{ number_format($total, 2) }}</strong></p>
</body>
</html>
```

- [ ] **Step 5: Write the controller**

`backend/app/Http/Controllers/Admin/PayrollPdfController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class PayrollPdfController extends Controller {
    public function __construct(private AttendancePayCalculator $calculator) {}

    public function export(Request $request) {
        $range = $request->query('range', 'daily');
        $date = $request->query('date', now()->toDateString());
        $settings = PayrollSetting::current();

        $records = $range === 'daily'
            ? AttendanceRecord::with('employee.branch')->where('work_date', $date)->whereNotNull('clock_out')->get()
            : AttendanceRecord::with('employee.branch')->whereBetween('work_date', [$date, now()->parse($date)->addDays(6)->toDateString()])->whereNotNull('clock_out')->get();

        $rows = $records->map(fn (AttendanceRecord $record) => [
            'employee' => $record->employee,
            'pay' => $this->calculator->compute($record->clock_in, $record->clock_out, $settings),
        ]);

        $pdf = Pdf::loadView('pdf.payroll', [
            'range' => $range,
            'date' => $date,
            'rows' => $rows,
            'total' => round($rows->sum(fn ($r) => $r['pay']['total']), 2),
        ]);

        return $pdf->stream("payroll-{$range}-{$date}.pdf");
    }
}
```

- [ ] **Step 6: Wire the route**

`backend/routes/api.php` — add inside the `auth:sanctum` group:

```php
use App\Http\Controllers\Admin\PayrollPdfController;

Route::get('/admin/payroll/pdf', [PayrollPdfController::class, 'export']);
```

- [ ] **Step 7: Run the tests and make sure they pass**

```bash
php artisan test --filter=PayrollPdfControllerTest
```

Expected: `Tests: 1 passed`.

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock resources/views/pdf app/Http/Controllers/Admin/PayrollPdfController.php routes/api.php tests/Feature/PayrollPdfControllerTest.php
git commit -m "feat: add payroll PDF export via dompdf"
```

---

## Phase 5 — 13th Month Pay (PD 851)

### Task 15: `ThirteenthMonthCalculator` service

**Files:**
- Create: `backend/app/Services/ThirteenthMonthCalculator.php`
- Test: `backend/tests/Unit/ThirteenthMonthCalculatorTest.php`

**Interfaces:**
- Consumes: `AttendancePayCalculator` (Task 8), `AttendanceRecord`, `EmployeeEarning`, `PayrollSetting`, `Employee::isActiveDuring()` (Task 3).
- Produces: `ThirteenthMonthCalculator::monthlyBreakdown(Employee $employee, PayrollSetting $settings, int $year): array` (12 rows, one per month, each with `worked`, `basic_pay`, `ot_pay`, `other_pay`, `month_total_included`) and `::isEligible(Employee $employee, PayrollSetting $settings, int $year): bool` — Task 16 (compute) and Task 18 (payslip) both depend on this. Mirrors `buildMonthlyBreakdown` / `isEligibleForThirteenthMonth` in the prototype, but sources Basic/OT from real `attendance_records` instead of mock `otHoursByMonth`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Unit/ThirteenthMonthCalculatorTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeEarning;
use App\Models\PayrollSetting;
use App\Services\ThirteenthMonthCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThirteenthMonthCalculatorTest extends TestCase {
    use RefreshDatabase;

    public function test_monthly_breakdown_sums_basic_pay_from_attendance_for_worked_months(): void {
        $settings = PayrollSetting::current(); // daily_basic_rate 505, includes only BASIC by default
        $employee = Employee::factory()->for(Branch::factory())->create([
            'hire_date' => '2026-01-01',
            'resignation_date' => null,
        ]);
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-01-05', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);
        AttendanceRecord::factory()->for($employee)->create(['work_date' => '2026-01-06', 'clock_in' => '08:00:00', 'clock_out' => '17:00:00']);

        $breakdown = (new ThirteenthMonthCalculator())->monthlyBreakdown($employee, $settings, 2026);

        $january = collect($breakdown)->firstWhere('month', 1);
        $this->assertTrue($january['worked']);
        $this->assertGreaterThan(0, $january['basic_pay']);
        $february = collect($breakdown)->firstWhere('month', 2);
        $this->assertSame(0.0, $february['basic_pay']);
    }

    public function test_other_earnings_are_only_included_when_settings_include_the_code(): void {
        $settings = PayrollSetting::current();
        $settings->update(['included_earnings' => ['BASIC', 'BONUS']]);
        $employee = Employee::factory()->for(Branch::factory())->create(['hire_date' => '2026-01-01']);
        EmployeeEarning::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 6, 'code' => 'BONUS', 'amount' => 3000]);
        EmployeeEarning::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 6, 'code' => 'ALLOWANCE', 'amount' => 1500]);

        $breakdown = (new ThirteenthMonthCalculator())->monthlyBreakdown($employee, $settings, 2026);

        $june = collect($breakdown)->firstWhere('month', 6);
        $this->assertSame(3000.0, $june['month_total_included']);
    }

    public function test_eligibility_requires_minimum_months_and_included_employment_type(): void {
        $settings = PayrollSetting::current();
        $settings->update(['minimum_months' => 3, 'employment_types_included' => ['regular']]);

        $tooShort = Employee::factory()->for(Branch::factory())->create([
            'employment_type' => 'regular', 'hire_date' => '2026-11-01', 'resignation_date' => null,
        ]);
        $wrongType = Employee::factory()->for(Branch::factory())->create([
            'employment_type' => 'seasonal', 'hire_date' => '2026-01-01', 'resignation_date' => null,
        ]);
        $eligible = Employee::factory()->for(Branch::factory())->create([
            'employment_type' => 'regular', 'hire_date' => '2026-01-01', 'resignation_date' => null,
        ]);

        $calculator = new ThirteenthMonthCalculator();
        $this->assertFalse($calculator->isEligible($tooShort, $settings, 2026));
        $this->assertFalse($calculator->isEligible($wrongType, $settings, 2026));
        $this->assertTrue($calculator->isEligible($eligible, $settings, 2026));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=ThirteenthMonthCalculatorTest
```

Expected: FAIL — class `App\Services\ThirteenthMonthCalculator` not found.

- [ ] **Step 3: Write the implementation**

`backend/app/Services/ThirteenthMonthCalculator.php`:

```php
<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeEarning;
use App\Models\PayrollSetting;

class ThirteenthMonthCalculator {
    public function __construct(private AttendancePayCalculator $payCalculator = new AttendancePayCalculator()) {}

    public function monthlyBreakdown(Employee $employee, PayrollSetting $settings, int $year): array {
        $included = $settings->included_earnings;
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $worked = $employee->isActiveDuring($month, $year);

            $basicPay = 0.0;
            $otPay = 0.0;
            if ($worked) {
                $records = AttendanceRecord::where('employee_id', $employee->id)
                    ->whereYear('work_date', $year)
                    ->whereMonth('work_date', $month)
                    ->whereNotNull('clock_out')
                    ->get();

                foreach ($records as $record) {
                    $pay = $this->payCalculator->compute($record->clock_in, $record->clock_out, $settings);
                    if (in_array('BASIC', $included, true)) {
                        $basicPay += $pay['basic'];
                    }
                    if (in_array('OVERTIME', $included, true)) {
                        $otPay += $pay['ot'];
                    }
                }
            }

            $otherPay = $worked
                ? (float) EmployeeEarning::where('employee_id', $employee->id)
                    ->where('year', $year)->where('month', $month)
                    ->whereIn('code', $included)
                    ->sum('amount')
                : 0.0;

            $months[] = [
                'month' => $month,
                'worked' => $worked,
                'basic_pay' => round($basicPay, 2),
                'ot_pay' => round($otPay, 2),
                'other_pay' => round($otherPay, 2),
                'month_total_included' => round($basicPay + $otPay + $otherPay, 2),
            ];
        }

        return $months;
    }

    public function isEligible(Employee $employee, PayrollSetting $settings, int $year): bool {
        if (! in_array($employee->employment_type, $settings->employment_types_included, true)) {
            return false;
        }

        $monthsWorked = collect($this->monthlyBreakdown($employee, $settings, $year))
            ->where('worked', true)
            ->count();

        return $monthsWorked >= $settings->minimum_months;
    }

    public function computedAmount(Employee $employee, PayrollSetting $settings, int $year): float {
        $total = collect($this->monthlyBreakdown($employee, $settings, $year))
            ->sum('month_total_included');

        return round($total / 12, 2);
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

```bash
php artisan test --filter=ThirteenthMonthCalculatorTest
```

Expected: `Tests: 3 passed`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ThirteenthMonthCalculator.php tests/Unit/ThirteenthMonthCalculatorTest.php
git commit -m "feat: add ThirteenthMonthCalculator (PD 851 monthly breakdown and eligibility)"
```

### Task 16: 13th month compute / compute-all / recompute endpoints

**Files:**
- Create: `backend/app/Http/Controllers/Admin/ThirteenthMonthController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ThirteenthMonthControllerTest.php`

**Interfaces:**
- Consumes: `ThirteenthMonthCalculator` (Task 15), `ThirteenthMonthRecord`, `AuditLog`.
- Produces: `GET /api/admin/thirteenth-month?year=2026` (list, computing eligibility on the fly and upserting `pending` rows for newly-eligible employees), `POST /api/admin/thirteenth-month/{employee}/compute`, `POST /api/admin/thirteenth-month/compute-all`, `POST /api/admin/thirteenth-month/{employee}/recompute` — Task 26's dashboard/employees tabs render the list; Task 17 adds lock/unlock/release/adjust on top of the same record.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/ThirteenthMonthControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\ThirteenthMonthRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThirteenthMonthControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_index_lists_eligible_employees_as_pending_by_default(): void {
        $admin = User::factory()->create();
        Employee::factory()->for(Branch::factory())->create(['hire_date' => '2026-01-01', 'employment_type' => 'regular']);

        $response = $this->actingAs($admin)->getJson('/api/admin/thirteenth-month?year=2026');

        $response->assertOk();
        $this->assertCount(1, $response->json('records'));
        $this->assertSame('pending', $response->json('records.0.status'));
    }

    public function test_compute_moves_a_record_to_computed_and_sets_the_amount(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['hire_date' => '2026-01-01', 'employment_type' => 'regular']);

        $response = $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/compute?year=2026");

        $response->assertOk();
        $this->assertDatabaseHas('thirteenth_month_records', [
            'employee_id' => $employee->id, 'payroll_year' => 2026, 'status' => 'computed',
        ]);
    }

    public function test_compute_all_computes_every_pending_eligible_employee(): void {
        $admin = User::factory()->create();
        Employee::factory()->count(3)->for(Branch::factory())->create(['hire_date' => '2026-01-01', 'employment_type' => 'regular']);

        $response = $this->actingAs($admin)->postJson('/api/admin/thirteenth-month/compute-all?year=2026');

        $response->assertOk();
        $this->assertSame(3, ThirteenthMonthRecord::where('status', 'computed')->count());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=ThirteenthMonthControllerTest
```

Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Admin/ThirteenthMonthController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Models\ThirteenthMonthRecord;
use App\Services\ThirteenthMonthCalculator;
use Illuminate\Http\Request;

class ThirteenthMonthController extends Controller {
    public function __construct(private ThirteenthMonthCalculator $calculator) {}

    public function index(Request $request) {
        $year = (int) $request->query('year', now()->year);
        $settings = PayrollSetting::current();

        $eligible = Employee::with('branch')->get()->filter(
            fn (Employee $e) => $this->calculator->isEligible($e, $settings, $year)
        );

        $records = $eligible->map(function (Employee $employee) use ($settings, $year) {
            $record = ThirteenthMonthRecord::firstOrCreate(
                ['employee_id' => $employee->id, 'payroll_year' => $year],
                ['computed_amount' => 0, 'status' => 'pending']
            );
            return [
                'id' => $record->id,
                'employee' => $employee,
                'status' => $record->status,
                'computed_amount' => (float) $record->computed_amount,
                'manual_adjustment' => (float) $record->manual_adjustment,
                'adjusted_amount' => $record->adjusted_amount,
                'released_on' => $record->released_on,
                'payment_method' => $record->payment_method,
            ];
        })->values();

        return response()->json(['records' => $records]);
    }

    public function compute(Request $request, Employee $employee) {
        $year = (int) $request->query('year', now()->year);
        $this->computeOne($employee, $year, $request->user()->id, 'compute');

        return response()->json(['message' => 'Computed.']);
    }

    public function computeAll(Request $request) {
        $year = (int) $request->query('year', now()->year);
        $settings = PayrollSetting::current();

        $pending = ThirteenthMonthRecord::where('payroll_year', $year)->where('status', 'pending')->get();
        foreach ($pending as $record) {
            $this->computeOne($record->employee, $year, $request->user()->id, 'compute');
        }

        AuditLog::create([
            'type' => '13th_month', 'employee_id' => null, 'performed_by' => $request->user()->id,
            'action' => 'bulk_compute', 'reason' => 'Bulk computation run',
        ]);

        return response()->json(['message' => "Computed {$pending->count()} record(s)."]);
    }

    public function recompute(Request $request, Employee $employee) {
        $year = (int) $request->query('year', now()->year);
        $this->computeOne($employee, $year, $request->user()->id, 'recompute');

        return response()->json(['message' => 'Recomputed.']);
    }

    private function computeOne(Employee $employee, int $year, int $adminId, string $action): void {
        $settings = PayrollSetting::current();
        $amount = $this->calculator->computedAmount($employee, $settings, $year);

        $record = ThirteenthMonthRecord::firstOrCreate(
            ['employee_id' => $employee->id, 'payroll_year' => $year],
            ['status' => 'pending']
        );
        $oldAmount = $record->adjusted_amount;
        $record->update(['computed_amount' => $amount, 'status' => 'computed']);

        AuditLog::create([
            'type' => '13th_month', 'employee_id' => $employee->id, 'performed_by' => $adminId,
            'action' => $action, 'old_amount' => $action === 'recompute' ? $oldAmount : null,
            'new_amount' => $record->adjusted_amount,
            'reason' => $action === 'compute' ? 'Initial computation' : 'Manual recomputation triggered',
        ]);
    }
}
```

- [ ] **Step 4: Wire the routes**

`backend/routes/api.php` — add inside the `auth:sanctum` group:

```php
use App\Http\Controllers\Admin\ThirteenthMonthController;

Route::get('/admin/thirteenth-month', [ThirteenthMonthController::class, 'index']);
Route::post('/admin/thirteenth-month/compute-all', [ThirteenthMonthController::class, 'computeAll']);
Route::post('/admin/thirteenth-month/{employee}/compute', [ThirteenthMonthController::class, 'compute']);
Route::post('/admin/thirteenth-month/{employee}/recompute', [ThirteenthMonthController::class, 'recompute']);
```

Note the order: `compute-all` must be registered before `/{employee}/compute` would not conflict since paths differ, but keep `compute-all` first for readability.

- [ ] **Step 5: Run the tests and make sure they pass**

```bash
php artisan test --filter=ThirteenthMonthControllerTest
```

Expected: `Tests: 3 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/ThirteenthMonthController.php routes/api.php tests/Feature/ThirteenthMonthControllerTest.php
git commit -m "feat: add 13th month compute/compute-all/recompute endpoints"
```

### Task 17: 13th month adjust / lock / unlock / release (with audit log)

**Files:**
- Modify: `backend/app/Http/Controllers/Admin/ThirteenthMonthController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ThirteenthMonthLifecycleTest.php`

**Interfaces:**
- Consumes: `ThirteenthMonthRecord`, `AuditLog` (same as Task 16).
- Produces: `POST /api/admin/thirteenth-month/{employee}/adjust` (body: `amount`, `reason`), `POST /api/admin/thirteenth-month/{employee}/lock`, `POST /api/admin/thirteenth-month/{employee}/unlock` (body: `reason`), `POST /api/admin/thirteenth-month/{employee}/release`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/ThirteenthMonthLifecycleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\ThirteenthMonthRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThirteenthMonthLifecycleTest extends TestCase {
    use RefreshDatabase;

    private function computedRecord(): array {
        $employee = Employee::factory()->for(Branch::factory())->create();
        $record = ThirteenthMonthRecord::factory()->for($employee)->create([
            'payroll_year' => 2026, 'computed_amount' => 5000, 'status' => 'computed',
        ]);
        return [$employee, $record];
    }

    public function test_adjust_requires_a_reason_and_updates_the_amount(): void {
        $admin = User::factory()->create();
        [$employee, $record] = $this->computedRecord();

        $response = $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/adjust?year=2026", [
            'amount' => 500, 'reason' => 'Correction after payroll review',
        ]);

        $response->assertOk();
        $this->assertSame(500.0, (float) $record->fresh()->manual_adjustment);
    }

    public function test_lock_then_release_then_unlock_requires_a_reason(): void {
        $admin = User::factory()->create();
        [$employee, $record] = $this->computedRecord();

        $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/release?year=2026")->assertOk();
        $this->assertSame('released', $record->fresh()->status);

        $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/lock?year=2026")->assertOk();
        $this->assertSame('locked', $record->fresh()->status);

        $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/unlock?year=2026", [])
            ->assertStatus(422);

        $this->actingAs($admin)->postJson("/api/admin/thirteenth-month/{$employee->id}/unlock?year=2026", [
            'reason' => 'Corrected basic salary after payroll error, approved by HR Manager',
        ])->assertOk();
        $this->assertSame('released', $record->fresh()->status);
    }
}
```

Add `backend/database/factories/ThirteenthMonthRecordFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\ThirteenthMonthRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirteenthMonthRecordFactory extends Factory {
    protected $model = ThirteenthMonthRecord::class;

    public function definition(): array {
        return [
            'employee_id' => Employee::factory(),
            'payroll_year' => now()->year,
            'computed_amount' => 0,
            'manual_adjustment' => 0,
            'status' => 'pending',
        ];
    }
}
```

Add `use HasFactory;` to `ThirteenthMonthRecord`.

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=ThirteenthMonthLifecycleTest
```

Expected: FAIL — route not found.

- [ ] **Step 3: Add the lifecycle methods to the controller**

Append to `backend/app/Http/Controllers/Admin/ThirteenthMonthController.php` (inside the class, alongside `compute`/`recompute`):

```php
    public function adjust(Request $request, Employee $employee) {
        $year = (int) $request->query('year', now()->year);
        $data = $request->validate(['amount' => ['required', 'numeric'], 'reason' => ['required', 'string', 'min:5']]);

        $record = ThirteenthMonthRecord::where('employee_id', $employee->id)->where('payroll_year', $year)->firstOrFail();
        $oldAmount = $record->adjusted_amount;
        $record->update(['manual_adjustment' => $record->manual_adjustment + $data['amount']]);

        AuditLog::create([
            'type' => '13th_month', 'employee_id' => $employee->id, 'performed_by' => $request->user()->id,
            'action' => 'manual_adjustment', 'old_amount' => $oldAmount, 'new_amount' => $record->adjusted_amount,
            'reason' => $data['reason'],
        ]);

        return response()->json(['message' => 'Adjustment applied.']);
    }

    public function lock(Request $request, Employee $employee) {
        $year = (int) $request->query('year', now()->year);
        $record = ThirteenthMonthRecord::where('employee_id', $employee->id)->where('payroll_year', $year)->firstOrFail();
        $record->update(['status' => 'locked']);

        AuditLog::create([
            'type' => '13th_month', 'employee_id' => $employee->id, 'performed_by' => $request->user()->id,
            'action' => 'lock', 'old_amount' => $record->adjusted_amount, 'new_amount' => $record->adjusted_amount,
            'reason' => 'Record locked after release',
        ]);

        return response()->json(['message' => 'Locked.']);
    }

    public function unlock(Request $request, Employee $employee) {
        $year = (int) $request->query('year', now()->year);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5']]);

        $record = ThirteenthMonthRecord::where('employee_id', $employee->id)->where('payroll_year', $year)->firstOrFail();
        $record->update(['status' => $record->released_on ? 'released' : 'computed']);

        AuditLog::create([
            'type' => '13th_month', 'employee_id' => $employee->id, 'performed_by' => $request->user()->id,
            'action' => 'unlock', 'old_amount' => $record->adjusted_amount, 'new_amount' => $record->adjusted_amount,
            'reason' => $data['reason'],
        ]);

        return response()->json(['message' => 'Unlocked.']);
    }

    public function release(Request $request, Employee $employee) {
        $year = (int) $request->query('year', now()->year);
        $settings = PayrollSetting::current();
        $record = ThirteenthMonthRecord::where('employee_id', $employee->id)->where('payroll_year', $year)->firstOrFail();
        $record->update(['status' => 'released', 'released_on' => $settings->release_date, 'payment_method' => 'Bank Transfer']);

        AuditLog::create([
            'type' => '13th_month', 'employee_id' => $employee->id, 'performed_by' => $request->user()->id,
            'action' => 'release', 'old_amount' => $record->adjusted_amount, 'new_amount' => $record->adjusted_amount,
            'reason' => 'Released via Bank Transfer',
        ]);

        return response()->json(['message' => 'Released.']);
    }
```

- [ ] **Step 4: Wire the routes**

`backend/routes/api.php` — add inside the `auth:sanctum` group:

```php
Route::post('/admin/thirteenth-month/{employee}/adjust', [ThirteenthMonthController::class, 'adjust']);
Route::post('/admin/thirteenth-month/{employee}/lock', [ThirteenthMonthController::class, 'lock']);
Route::post('/admin/thirteenth-month/{employee}/unlock', [ThirteenthMonthController::class, 'unlock']);
Route::post('/admin/thirteenth-month/{employee}/release', [ThirteenthMonthController::class, 'release']);
```

- [ ] **Step 5: Run the tests and make sure they pass**

```bash
php artisan test --filter=ThirteenthMonthLifecycleTest
```

Expected: `Tests: 2 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/ThirteenthMonthController.php database/factories/ThirteenthMonthRecordFactory.php routes/api.php tests/Feature/ThirteenthMonthLifecycleTest.php
git commit -m "feat: add 13th month adjust/lock/unlock/release with audit logging"
```

### Task 18: 13th month payslip PDF

**Files:**
- Create: `backend/resources/views/pdf/thirteenth-month-payslip.blade.php`
- Create: `backend/app/Http/Controllers/Admin/ThirteenthMonthPayslipController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ThirteenthMonthPayslipControllerTest.php`

**Interfaces:**
- Consumes: `ThirteenthMonthCalculator::monthlyBreakdown()` (Task 15), `ThirteenthMonthRecord`, the `barryvdh/laravel-dompdf` package installed in Task 14.
- Produces: `GET /api/admin/thirteenth-month/{employee}/payslip?year=2026` returning `application/pdf`. Task 26's "Payslip" button hits this.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/ThirteenthMonthPayslipControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\ThirteenthMonthRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThirteenthMonthPayslipControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_payslip_returns_a_pdf_for_a_computed_record(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create(['hire_date' => '2026-01-01']);
        ThirteenthMonthRecord::factory()->for($employee)->create(['payroll_year' => 2026, 'computed_amount' => 5000, 'status' => 'computed']);

        $response = $this->actingAs($admin)->get("/api/admin/thirteenth-month/{$employee->id}/payslip?year=2026");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=ThirteenthMonthPayslipControllerTest
```

Expected: FAIL — route not found.

- [ ] **Step 3: Write the Blade template**

`backend/resources/views/pdf/thirteenth-month-payslip.blade.php`:

```blade
<html>
<head>
<style>
body { font-family: sans-serif; font-size: 12px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; }
.total { font-weight: bold; }
</style>
</head>
<body>
<h2>13th Month Pay Payslip — {{ $record->payroll_year }}</h2>
<p><strong>{{ $employee->full_name }}</strong> — {{ $employee->role }} — {{ $employee->branch->name }}</p>
<table>
<thead><tr><th>Month</th><th>Basic Pay</th><th>OT Pay</th><th>Other</th><th>Month Total</th></tr></thead>
<tbody>
@foreach ($breakdown as $row)
<tr>
<td>{{ \Carbon\Carbon::create()->month($row['month'])->format('M') }}</td>
<td>{{ $row['worked'] ? number_format($row['basic_pay'], 2) : '—' }}</td>
<td>{{ $row['worked'] ? number_format($row['ot_pay'], 2) : '—' }}</td>
<td>{{ $row['worked'] ? number_format($row['other_pay'], 2) : '—' }}</td>
<td>{{ $row['worked'] ? number_format($row['month_total_included'], 2) : '—' }}</td>
</tr>
@endforeach
</tbody>
</table>
<p>Computed Amount: {{ number_format($record->computed_amount, 2) }}</p>
<p>Manual Adjustment: {{ number_format($record->manual_adjustment, 2) }}</p>
<p class="total">13th Month Amount: {{ number_format($record->adjusted_amount, 2) }}</p>
<p>Status: {{ $record->status }}</p>
</body>
</html>
```

- [ ] **Step 4: Write the controller**

`backend/app/Http/Controllers/Admin/ThirteenthMonthPayslipController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Models\ThirteenthMonthRecord;
use App\Services\ThirteenthMonthCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ThirteenthMonthPayslipController extends Controller {
    public function __construct(private ThirteenthMonthCalculator $calculator) {}

    public function show(Request $request, Employee $employee) {
        $year = (int) $request->query('year', now()->year);
        $settings = PayrollSetting::current();
        $record = ThirteenthMonthRecord::where('employee_id', $employee->id)->where('payroll_year', $year)->firstOrFail();
        $breakdown = $this->calculator->monthlyBreakdown($employee, $settings, $year);

        $pdf = Pdf::loadView('pdf.thirteenth-month-payslip', [
            'employee' => $employee, 'record' => $record, 'breakdown' => $breakdown,
        ]);

        return $pdf->stream("13th-month-payslip-{$employee->employee_code}-{$year}.pdf");
    }
}
```

- [ ] **Step 5: Wire the route**

`backend/routes/api.php` — add inside the `auth:sanctum` group:

```php
use App\Http\Controllers\Admin\ThirteenthMonthPayslipController;

Route::get('/admin/thirteenth-month/{employee}/payslip', [ThirteenthMonthPayslipController::class, 'show']);
```

- [ ] **Step 6: Run the tests and make sure they pass**

```bash
php artisan test --filter=ThirteenthMonthPayslipControllerTest
```

Expected: `Tests: 1 passed`.

- [ ] **Step 7: Commit**

```bash
git add resources/views/pdf/thirteenth-month-payslip.blade.php app/Http/Controllers/Admin/ThirteenthMonthPayslipController.php routes/api.php tests/Feature/ThirteenthMonthPayslipControllerTest.php
git commit -m "feat: add 13th month payslip PDF"
```

---

## Phase 6 — Settings & Audit Log

### Task 19: Payroll settings get/update endpoints

**Files:**
- Create: `backend/app/Http/Controllers/Admin/PayrollSettingController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/PayrollSettingControllerTest.php`

**Interfaces:**
- Consumes: `PayrollSetting::current()` (Task 3).
- Produces: `GET /api/admin/settings`, `PUT /api/admin/settings` — Task 27's settings view reads/writes this; every other module (attendance, payroll, 13th month) already reads `PayrollSetting::current()` directly, so changes here apply everywhere immediately, satisfying the "One Shared Pay Rate Configuration" requirement from the handover doc.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/PayrollSettingControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollSettingControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_get_returns_the_current_settings(): void {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/settings');

        $response->assertOk();
        $response->assertJsonPath('daily_basic_rate', '505.00');
    }

    public function test_put_updates_the_settings_and_applies_immediately(): void {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->putJson('/api/admin/settings', [
            'daily_basic_rate' => 600,
            'standard_working_days_per_month' => 26,
            'overtime_multiplier' => 1.25,
            'night_diff_multiplier' => 0.10,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'release_date' => '2026-12-24',
            'minimum_months' => 1,
            'included_earnings' => ['BASIC'],
            'employment_types_included' => ['regular', 'probationary', 'fixed_term', 'seasonal'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('payroll_settings', ['daily_basic_rate' => 600.00]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=PayrollSettingControllerTest
```

Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Admin/PayrollSettingController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollSetting;
use Illuminate\Http\Request;

class PayrollSettingController extends Controller {
    public function show() {
        return response()->json(PayrollSetting::current());
    }

    public function update(Request $request) {
        $data = $request->validate([
            'daily_basic_rate' => ['required', 'numeric', 'min:0'],
            'standard_working_days_per_month' => ['required', 'integer', 'min:1', 'max:31'],
            'overtime_multiplier' => ['required', 'numeric', 'min:1'],
            'night_diff_multiplier' => ['required', 'numeric', 'min:0'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'release_date' => ['required', 'date'],
            'minimum_months' => ['required', 'integer', 'min:0', 'max:12'],
            'included_earnings' => ['required', 'array'],
            'included_earnings.*' => ['string'],
            'employment_types_included' => ['required', 'array'],
            'employment_types_included.*' => ['string'],
        ]);

        $settings = PayrollSetting::current();
        $settings->update($data);

        return response()->json($settings);
    }
}
```

- [ ] **Step 4: Wire the routes**

`backend/routes/api.php` — add inside the `auth:sanctum` group:

```php
use App\Http\Controllers\Admin\PayrollSettingController;

Route::get('/admin/settings', [PayrollSettingController::class, 'show']);
Route::put('/admin/settings', [PayrollSettingController::class, 'update']);
```

- [ ] **Step 5: Run the tests and make sure they pass**

```bash
php artisan test --filter=PayrollSettingControllerTest
```

Expected: `Tests: 2 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/PayrollSettingController.php routes/api.php tests/Feature/PayrollSettingControllerTest.php
git commit -m "feat: add payroll settings get/update endpoints"
```

### Task 20: Unified audit log endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Admin/AuditLogController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AuditLogControllerTest.php`

**Interfaces:**
- Consumes: `AuditLog` model (Task 4), populated by Task 10 (attendance) and Tasks 16–17 (13th month).
- Produces: `GET /api/admin/audit-log` returning all entries newest-first, with `employee` and `performedBy` eager-loaded — Task 27's audit log view renders this directly.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/AuditLogControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_returns_entries_newest_first(): void {
        $admin = User::factory()->create();
        $employee = Employee::factory()->for(Branch::factory())->create();
        $older = AuditLog::create(['type' => 'attendance', 'employee_id' => $employee->id, 'performed_by' => $admin->id, 'action' => 'adjust', 'reason' => 'x']);
        sleep(1);
        $newer = AuditLog::create(['type' => '13th_month', 'employee_id' => $employee->id, 'performed_by' => $admin->id, 'action' => 'lock', 'reason' => 'y']);

        $response = $this->actingAs($admin)->getJson('/api/admin/audit-log');

        $response->assertOk();
        $this->assertSame($newer->id, $response->json('0.id'));
        $this->assertSame($older->id, $response->json('1.id'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=AuditLogControllerTest
```

Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Admin/AuditLogController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;

class AuditLogController extends Controller {
    public function index() {
        return response()->json(
            AuditLog::with(['employee', 'performedBy'])->latest()->get()
        );
    }
}
```

- [ ] **Step 4: Wire the route**

`backend/routes/api.php` — add inside the `auth:sanctum` group:

```php
use App\Http\Controllers\Admin\AuditLogController;

Route::get('/admin/audit-log', [AuditLogController::class, 'index']);
```

- [ ] **Step 5: Run the tests and make sure they pass**

```bash
php artisan test --filter=AuditLogControllerTest
```

Expected: `Tests: 1 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/AuditLogController.php routes/api.php tests/Feature/AuditLogControllerTest.php
git commit -m "feat: add unified audit log endpoint"
```

---

## Phase 7 — Staff Self-Service

### Task 21: Staff dashboard endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Kiosk/StaffDashboardController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/StaffDashboardControllerTest.php`

**Interfaces:**
- Consumes: `kiosk.token` middleware (Task 7), `AttendancePayCalculator` (Task 8), `ThirteenthMonthCalculator` (Task 15).
- Produces: `GET /api/kiosk/dashboard` returning `{ today: {...}|null, week: {...}|null, thirteenth_month: {...}|null }` — Task 23's staff dashboard renders this in one call.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/StaffDashboardControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\KioskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffDashboardControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_dashboard_includes_today_week_and_thirteenth_month_sections(): void {
        $employee = Employee::factory()->for(Branch::factory())->create(['hire_date' => now()->startOfYear()]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => now()->toDateString(), 'clock_in' => '08:00:00', 'clock_out' => '17:00:00',
        ]);
        $token = app(KioskTokenService::class)->issue($employee);

        $response = $this->withToken($token)->getJson('/api/kiosk/dashboard');

        $response->assertOk();
        $this->assertNotNull($response->json('today'));
        $this->assertNotNull($response->json('week'));
        $this->assertArrayHasKey('thirteenth_month', $response->json());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=StaffDashboardControllerTest
```

Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Kiosk/StaffDashboardController.php`:

```php
<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Services\AttendancePayCalculator;
use App\Services\ThirteenthMonthCalculator;
use Illuminate\Http\Request;

class StaffDashboardController extends Controller {
    public function __construct(
        private AttendancePayCalculator $payCalculator,
        private ThirteenthMonthCalculator $thirteenthMonthCalculator,
    ) {}

    public function show(Request $request) {
        /** @var Employee $employee */
        $employee = $request->attributes->get('kiosk_employee');
        $settings = PayrollSetting::current();
        $today = now()->toDateString();
        $weekStart = now()->startOfWeek();

        $todayRecord = AttendanceRecord::where('employee_id', $employee->id)->where('work_date', $today)->first();
        $todayPay = $todayRecord ? $this->payCalculator->compute($todayRecord->clock_in, $todayRecord->clock_out, $settings) : null;

        $weekRecords = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
            ->whereNotNull('clock_out')
            ->get();
        $weekPays = $weekRecords->map(fn (AttendanceRecord $r) => $this->payCalculator->compute($r->clock_in, $r->clock_out, $settings));

        $eligible = $this->thirteenthMonthCalculator->isEligible($employee, $settings, now()->year);
        $thirteenthMonth = $eligible ? [
            'total_basic_earned' => collect($this->thirteenthMonthCalculator->monthlyBreakdown($employee, $settings, now()->year))->sum('month_total_included'),
            'estimated_amount' => $this->thirteenthMonthCalculator->computedAmount($employee, $settings, now()->year),
        ] : null;

        return response()->json([
            'today' => $todayRecord ? ['record' => $todayRecord, 'pay' => $todayPay] : null,
            'week' => $weekRecords->isEmpty() ? null : [
                'days_worked' => $weekRecords->count(),
                'total_hours' => round($weekPays->sum('total_hours'), 2),
                'basic' => round($weekPays->sum('basic'), 2),
                'ot' => round($weekPays->sum('ot'), 2),
                'total' => round($weekPays->sum('total'), 2),
            ],
            'thirteenth_month' => $thirteenthMonth,
        ]);
    }
}
```

- [ ] **Step 4: Wire the route**

`backend/routes/api.php` — add inside the `kiosk.token` middleware group from Task 9:

```php
use App\Http\Controllers\Kiosk\StaffDashboardController;

Route::get('/kiosk/dashboard', [StaffDashboardController::class, 'show']);
```

- [ ] **Step 5: Run the tests and make sure they pass**

```bash
php artisan test --filter=StaffDashboardControllerTest
```

Expected: `Tests: 1 passed`.

- [ ] **Step 6: Run the full backend suite before moving to the frontend**

```bash
php artisan test
```

Expected: all tests across every prior task pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Kiosk/StaffDashboardController.php routes/api.php tests/Feature/StaffDashboardControllerTest.php
git commit -m "feat: add staff self-service dashboard endpoint"
```

---

## Phase 8 — Frontend

### Task 22: App shell and routing

**Files:**
- Modify: `frontend/src/main.jsx`
- Create: `frontend/src/App.jsx`
- Create: `frontend/src/components/Shell.jsx`

**Interfaces:**
- Consumes: `THEME` tokens from `frontend/src/theme.js` (Task 2).
- Produces: three top-level routes (`/kiosk`, `/staff-login`, `/admin/*`) inside a shared header nav matching the prototype's mode switcher — every remaining frontend task plugs a page into one of these routes.

- [ ] **Step 1: Write the router entry point**

`frontend/src/main.jsx`:

```jsx
import React from "react";
import ReactDOM from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import App from "./App.jsx";

ReactDOM.createRoot(document.getElementById("root")).render(
  <React.StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </React.StrictMode>
);
```

- [ ] **Step 2: Write the shell (header nav)**

`frontend/src/components/Shell.jsx`:

```jsx
import { Link, useLocation } from "react-router-dom";
import { COLOR, FONT_DISPLAY } from "../theme";

const TABS = [
  ["/kiosk", "Kiosk · Clock In/Out"],
  ["/staff-login", "Staff Timesheet Login"],
  ["/admin", "Admin"],
];

export default function Shell({ children }) {
  const location = useLocation();
  return (
    <div style={{ fontFamily: "'Inter', sans-serif", background: COLOR.cream, minHeight: "100vh", color: COLOR.ink }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "12px 24px", borderBottom: `1px solid ${COLOR.line}`, background: "white" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <div style={{ width: 22, height: 22, borderRadius: 4, background: COLOR.gold }} />
          <div style={{ fontFamily: FONT_DISPLAY, fontWeight: 700, fontSize: 15 }}>Ongkoleyt</div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          {TABS.map(([path, label]) => {
            const active = location.pathname.startsWith(path);
            return (
              <Link key={path} to={path} style={{ padding: "7px 14px", borderRadius: 999, border: `1px solid ${active ? COLOR.espresso : COLOR.line}`, background: active ? COLOR.espresso : "white", color: active ? COLOR.cream : COLOR.ink, fontSize: 12.5, fontWeight: 600, textDecoration: "none" }}>
                {label}
              </Link>
            );
          })}
        </div>
      </div>
      {children}
    </div>
  );
}
```

- [ ] **Step 3: Write the app root**

`frontend/src/App.jsx`:

```jsx
import { Navigate, Route, Routes } from "react-router-dom";
import Shell from "./components/Shell.jsx";
import KioskClockPage from "./pages/KioskClockPage.jsx";
import StaffLoginPage from "./pages/StaffLoginPage.jsx";
import AdminApp from "./pages/admin/AdminApp.jsx";

export default function App() {
  return (
    <Shell>
      <Routes>
        <Route path="/" element={<Navigate to="/kiosk" replace />} />
        <Route path="/kiosk" element={<KioskClockPage />} />
        <Route path="/staff-login" element={<StaffLoginPage />} />
        <Route path="/admin/*" element={<AdminApp />} />
      </Routes>
    </Shell>
  );
}
```

- [ ] **Step 4: Verify it boots**

```bash
cd frontend
npm run dev
```

Expected: the app compiles (it will error on missing page imports until Tasks 23–27 create them — that's expected at this point; note it and continue).

- [ ] **Step 5: Commit**

```bash
cd ..
git add frontend/src/main.jsx frontend/src/App.jsx frontend/src/components/Shell.jsx
git commit -m "feat(frontend): add app shell and routing"
```

### Task 23: Kiosk clock-in/out flow

**Files:**
- Create: `frontend/src/pages/KioskClockPage.jsx`
- Create: `frontend/src/components/PinPad.jsx`

**Interfaces:**
- Consumes: `apiClient` (Task 2) hitting `GET /api/kiosk/staff`, `POST /api/kiosk/verify-pin`, `POST /api/kiosk/clock-in`, `POST /api/kiosk/clock-out` (Tasks 7, 9); `COLOR`/`FONT_*`/`formatTime12` from `theme.js`.
- Produces: `PinPad` component (`onSubmit(pin)`, `onBack()` props) reused by Task 24's staff login page.

- [ ] **Step 1: Write the reusable PIN pad**

`frontend/src/components/PinPad.jsx`:

```jsx
import { useState } from "react";
import { COLOR } from "../theme";

export default function PinPad({ onSubmit, onBack, error }) {
  const [pin, setPin] = useState("");

  function pressDigit(d) {
    if (pin.length >= 4) return;
    const next = pin + d;
    setPin(next);
    if (next.length === 4) {
      setTimeout(() => {
        onSubmit(next);
        setPin("");
      }, 150);
    }
  }

  return (
    <div>
      <div style={{ display: "flex", gap: 10, marginBottom: 22, justifyContent: "center" }}>
        {[0, 1, 2, 3].map((i) => (
          <div key={i} style={{ width: 14, height: 14, borderRadius: "50%", background: i < pin.length ? (error ? COLOR.rust : COLOR.espresso) : COLOR.line }} />
        ))}
      </div>
      {error && <div style={{ color: COLOR.rust, fontSize: 12, textAlign: "center", marginBottom: 14 }}>Incorrect PIN — try again</div>}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 90px)", gap: 12 }}>
        {["1", "2", "3", "4", "5", "6", "7", "8", "9"].map((d) => (
          <button key={d} onClick={() => pressDigit(d)} style={{ height: 64, borderRadius: 10, border: `1px solid ${COLOR.line}`, background: "white", fontSize: 20, fontWeight: 700, cursor: "pointer" }}>{d}</button>
        ))}
        <button onClick={() => setPin(pin.slice(0, -1))} style={{ height: 64, borderRadius: 10, border: `1px solid ${COLOR.line}`, background: COLOR.parchment, fontSize: 13, fontWeight: 700, color: COLOR.inkSoft, cursor: "pointer" }}>DEL</button>
        <button onClick={() => pressDigit("0")} style={{ height: 64, borderRadius: 10, border: `1px solid ${COLOR.line}`, background: "white", fontSize: 20, fontWeight: 700, cursor: "pointer" }}>0</button>
        <button onClick={() => pin.length === 4 && onSubmit(pin)} style={{ height: 64, borderRadius: 10, border: "none", background: COLOR.greenSoft, fontSize: 20, color: COLOR.green, cursor: "pointer" }}>✓</button>
      </div>
      <button onClick={onBack} style={{ marginTop: 18, background: "none", border: "none", color: COLOR.inkSoft, fontSize: 13, textDecoration: "underline", cursor: "pointer" }}>← Back</button>
    </div>
  );
}
```

- [ ] **Step 2: Write the kiosk clock page**

`frontend/src/pages/KioskClockPage.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../api/client";
import PinPad from "../components/PinPad";
import { COLOR, FONT_DISPLAY } from "../theme";

function initialsOf(name) {
  const parts = name.trim().split(/\s+/);
  return parts.length === 1 ? parts[0].slice(0, 2).toUpperCase() : (parts[0][0] + parts[1][0]).toUpperCase();
}

export default function KioskClockPage() {
  const [staff, setStaff] = useState([]);
  const [search, setSearch] = useState("");
  const [selected, setSelected] = useState(null);
  const [error, setError] = useState(false);
  const [toast, setToast] = useState(null);

  useEffect(() => {
    apiClient.get("/api/kiosk/staff").then((res) => setStaff(res.data));
  }, []);

  function showToast(msg) {
    setToast(msg);
    setTimeout(() => setToast(null), 2400);
  }

  async function submitPin(pin) {
    try {
      const { data } = await apiClient.post("/api/kiosk/verify-pin", { employee_id: selected.id, pin });
      const todayRes = await apiClient.get("/api/kiosk/today", { headers: { Authorization: `Bearer ${data.token}` } });
      const record = todayRes.data;
      const action = !record || record.clock_out ? "clock-in" : "clock-out";
      await apiClient.post(`/api/kiosk/${action}`, {}, { headers: { Authorization: `Bearer ${data.token}` } });
      showToast(`${selected.short_name} clocked ${action === "clock-in" ? "in" : "out"}.`);
      setSelected(null);
      setError(false);
    } catch {
      setError(true);
      setTimeout(() => setError(false), 700);
    }
  }

  const filtered = staff.filter((s) => s.short_name.toLowerCase().includes(search.toLowerCase()));

  if (selected) {
    return (
      <div style={{ minHeight: "80vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 20 }}>
          <div style={{ width: 48, height: 48, borderRadius: "50%", background: COLOR.rust, color: "white", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700 }}>{initialsOf(selected.full_name)}</div>
          <div>
            <div style={{ fontWeight: 700, fontSize: 17 }}>{selected.full_name}</div>
            <div style={{ fontSize: 13, color: COLOR.inkSoft }}>{selected.role}</div>
          </div>
        </div>
        <PinPad onSubmit={submitPin} onBack={() => setSelected(null)} error={error} />
      </div>
    );
  }

  return (
    <div style={{ minHeight: "80vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
      <div style={{ fontWeight: 700, fontSize: 19, marginBottom: 4, fontFamily: FONT_DISPLAY }}>Who are you?</div>
      <div style={{ fontSize: 13, color: COLOR.inkSoft, marginBottom: 20 }}>Tap your name to continue</div>
      <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name..." style={{ width: "min(900px, 92vw)", padding: "12px 16px", borderRadius: 10, border: `1px solid ${COLOR.line}`, fontSize: 14, marginBottom: 22 }} />
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, minmax(220px, 1fr))", gap: 16, width: "min(900px, 92vw)" }}>
        {filtered.map((s) => (
          <div key={s.id} onClick={() => setSelected(s)} style={{ display: "flex", alignItems: "center", gap: 12, background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: "14px 16px", cursor: "pointer" }}>
            <div style={{ width: 42, height: 42, borderRadius: "50%", background: COLOR.rust, color: "white", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, flexShrink: 0 }}>{initialsOf(s.full_name)}</div>
            <div>
              <div style={{ fontWeight: 700, fontSize: 14.5 }}>{s.short_name}</div>
              <div style={{ fontSize: 12.5, color: COLOR.inkSoft }}>{s.role}</div>
            </div>
          </div>
        ))}
      </div>
      {toast && <div style={{ position: "fixed", bottom: 24, right: 24, background: COLOR.espresso, color: COLOR.cream, padding: "12px 18px", borderRadius: 8, fontSize: 13 }}>{toast}</div>}
    </div>
  );
}
```

- [ ] **Step 3: Manually verify against the running backend**

```bash
# backend (separate terminal)
cd backend && php artisan serve
# frontend
cd frontend && npm run dev
```

Open `http://localhost:5173/kiosk`, pick a seeded employee, enter PIN `1234`. Expected: a toast confirms clock-in; repeating the flow for the same employee clocks them out.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/KioskClockPage.jsx frontend/src/components/PinPad.jsx
git commit -m "feat(frontend): add kiosk clock in/out flow"
```

### Task 24: Staff self-service dashboard

**Files:**
- Create: `frontend/src/pages/StaffLoginPage.jsx`
- Create: `frontend/src/pages/StaffDashboardPage.jsx`

**Interfaces:**
- Consumes: `PinPad` (Task 23), `GET /api/kiosk/dashboard` (Task 21), `formatPHP`/`formatTime12`/`formatHoursLabel` from `theme.js`.
- Produces: the staff self-service view — no downstream consumers, this is a leaf page.

- [ ] **Step 1: Write the staff login page (name + PIN, same pattern as kiosk)**

`frontend/src/pages/StaffLoginPage.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../api/client";
import PinPad from "../components/PinPad";
import StaffDashboardPage from "./StaffDashboardPage";
import { COLOR, FONT_DISPLAY } from "../theme";

export default function StaffLoginPage() {
  const [staff, setStaff] = useState([]);
  const [search, setSearch] = useState("");
  const [selected, setSelected] = useState(null);
  const [token, setToken] = useState(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    apiClient.get("/api/kiosk/staff").then((res) => setStaff(res.data));
  }, []);

  async function submitPin(pin) {
    try {
      const { data } = await apiClient.post("/api/kiosk/verify-pin", { employee_id: selected.id, pin });
      setToken(data.token);
      setError(false);
    } catch {
      setError(true);
      setTimeout(() => setError(false), 700);
    }
  }

  if (token && selected) {
    return <StaffDashboardPage staff={selected} token={token} onLogout={() => { setToken(null); setSelected(null); }} />;
  }

  if (selected) {
    return (
      <div style={{ minHeight: "80vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
        <div style={{ fontWeight: 700, fontSize: 17, marginBottom: 20 }}>{selected.full_name}</div>
        <PinPad onSubmit={submitPin} onBack={() => setSelected(null)} error={error} />
      </div>
    );
  }

  const filtered = staff.filter((s) => s.short_name.toLowerCase().includes(search.toLowerCase()));
  return (
    <div style={{ minHeight: "80vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
      <div style={{ fontWeight: 700, fontSize: 19, marginBottom: 4, fontFamily: FONT_DISPLAY }}>Staff Timesheet Login</div>
      <div style={{ fontSize: 13, color: COLOR.inkSoft, marginBottom: 20 }}>Tap your name to view your timesheet</div>
      <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name..." style={{ width: "min(900px, 92vw)", padding: "12px 16px", borderRadius: 10, border: `1px solid ${COLOR.line}`, fontSize: 14, marginBottom: 22 }} />
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, minmax(220px, 1fr))", gap: 16, width: "min(900px, 92vw)" }}>
        {filtered.map((s) => (
          <div key={s.id} onClick={() => setSelected(s)} style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: "14px 16px", cursor: "pointer" }}>
            <div style={{ fontWeight: 700, fontSize: 14.5 }}>{s.short_name}</div>
            <div style={{ fontSize: 12.5, color: COLOR.inkSoft }}>{s.role}</div>
          </div>
        ))}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Write the dashboard**

`frontend/src/pages/StaffDashboardPage.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../api/client";
import { COLOR, FONT_DISPLAY, formatHoursLabel, formatPHP, formatTime12 } from "../theme";

function MiniStat({ label, value }) {
  return (
    <div>
      <div style={{ fontSize: 11, color: COLOR.inkSoft, textTransform: "uppercase", marginBottom: 4 }}>{label}</div>
      <div style={{ fontWeight: 700, fontSize: 15 }}>{value}</div>
    </div>
  );
}

export default function StaffDashboardPage({ staff, token, onLogout }) {
  const [data, setData] = useState(null);

  useEffect(() => {
    apiClient.get("/api/kiosk/dashboard", { headers: { Authorization: `Bearer ${token}` } }).then((res) => setData(res.data));
  }, [token]);

  if (!data) return <div style={{ padding: 32 }}>Loading...</div>;

  return (
    <div style={{ maxWidth: 900, margin: "0 auto", padding: "32px 24px" }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 20 }}>
        <h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 24, margin: 0 }}>Hi, {staff.full_name.split(" ")[0]}</h1>
        <button onClick={onLogout} style={{ padding: "9px 18px", borderRadius: 7, border: `1px solid ${COLOR.line}`, background: "white", cursor: "pointer" }}>Log Out</button>
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20, marginBottom: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 12px" }}>Today</h3>
        {!data.today ? <div style={{ fontSize: 13, color: COLOR.inkSoft }}>You haven't clocked in yet today.</div> : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 16 }}>
            <MiniStat label="Clock In" value={formatTime12(data.today.record.clock_in)} />
            <MiniStat label="Clock Out" value={data.today.record.clock_out ? formatTime12(data.today.record.clock_out) : "Still working"} />
            <MiniStat label="Hours" value={data.today.pay ? formatHoursLabel(data.today.pay.total_hours) : "—"} />
            <MiniStat label="Total Pay" value={data.today.pay ? formatPHP(data.today.pay.total) : "—"} />
          </div>
        )}
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20, marginBottom: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 12px" }}>This Week</h3>
        {!data.week ? <div style={{ fontSize: 13, color: COLOR.inkSoft }}>No hours logged yet this week.</div> : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 16 }}>
            <MiniStat label="Days Worked" value={data.week.days_worked} />
            <MiniStat label="Total Hours" value={`${data.week.total_hours}h`} />
            <MiniStat label="Overtime" value={formatPHP(data.week.ot)} />
            <MiniStat label="Total Pay" value={formatPHP(data.week.total)} />
          </div>
        )}
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 12px" }}>13th Month Pay (estimated to date)</h3>
        {!data.thirteenth_month ? <div style={{ fontSize: 13, color: COLOR.inkSoft }}>Not currently eligible under this year's settings.</div> : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(2, 1fr)", gap: 16 }}>
            <MiniStat label="Basic Earned (Annual)" value={formatPHP(data.thirteenth_month.total_basic_earned)} />
            <MiniStat label="Estimated 13th Month" value={formatPHP(data.thirteenth_month.estimated_amount)} />
          </div>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 3: Manually verify**

With both dev servers running, open `http://localhost:5173/staff-login`, log in as a seeded employee with PIN `1234`. Expected: Today/This Week/13th Month cards render using live API data (no more mock `useState`).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/StaffLoginPage.jsx frontend/src/pages/StaffDashboardPage.jsx
git commit -m "feat(frontend): add staff self-service dashboard"
```

---

### Task 25: Admin login gate and attendance view

**Files:**
- Create: `frontend/src/pages/admin/AdminApp.jsx`
- Create: `frontend/src/pages/admin/AdminLoginPage.jsx`
- Create: `frontend/src/pages/admin/AttendanceView.jsx`
- Create: `frontend/src/components/AdjustAttendanceModal.jsx`
- Create: `frontend/src/components/ui.jsx`

**Interfaces:**
- Consumes: `POST /api/admin/login`, `GET /api/admin/me` (Task 6), `GET /api/admin/attendance/today` (Task 11), `PATCH /api/admin/attendance/{id}/adjust`, `POST /api/admin/attendance/{id}/approve` (Task 10).
- Produces: `AdminApp` (tab shell — Attendance/Payroll/13th Month/Settings/Audit Log — placeholders for Tasks 26–28 to fill in), `Button`/`Pill`/`StatCard` shared primitives in `ui.jsx` reused by every remaining admin task.

- [ ] **Step 1: Write the shared UI primitives (ported from the prototype)**

`frontend/src/components/ui.jsx`:

```jsx
import { COLOR, FONT_DISPLAY } from "../theme";

export function Button({ children, onClick, variant = "primary", disabled, small }) {
  const base = { fontWeight: 600, fontSize: small ? 12 : 13, padding: small ? "5px 10px" : "9px 18px", borderRadius: 7, border: "1px solid transparent", cursor: disabled ? "not-allowed" : "pointer", opacity: disabled ? 0.45 : 1 };
  const variants = {
    primary: { background: COLOR.espresso, color: COLOR.cream },
    gold: { background: COLOR.gold, color: COLOR.espresso },
    outline: { background: "white", color: COLOR.espresso, border: `1px solid ${COLOR.line}` },
    ghost: { background: "transparent", color: COLOR.inkSoft },
    danger: { background: "transparent", color: COLOR.rust, border: `1px solid ${COLOR.rust}` },
  };
  return <button style={{ ...base, ...variants[variant] }} onClick={disabled ? undefined : onClick} disabled={disabled}>{children}</button>;
}

export function Pill({ children, tone = "neutral" }) {
  const tones = {
    neutral: { bg: COLOR.parchment, fg: COLOR.ink },
    approved: { bg: COLOR.greenSoft, fg: COLOR.green },
    pending: { bg: COLOR.amberSoft, fg: COLOR.amber },
    computed: { bg: "#DCE7F5", fg: "#2C5384" },
    released: { bg: COLOR.greenSoft, fg: COLOR.green },
    locked: { bg: COLOR.rustSoft, fg: COLOR.rust },
  };
  const t = tones[tone] || tones.neutral;
  return <span style={{ display: "inline-block", padding: "3px 10px", borderRadius: 999, fontSize: 11, fontWeight: 600, textTransform: "uppercase", background: t.bg, color: t.fg }}>{children}</span>;
}

export function StatCard({ label, value }) {
  return (
    <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: "16px 20px", flex: "1 1 180px" }}>
      <div style={{ fontSize: 11, textTransform: "uppercase", color: COLOR.inkSoft, marginBottom: 6 }}>{label}</div>
      <div style={{ fontSize: 24, fontWeight: 700 }}>{value}</div>
    </div>
  );
}

export function ModalShell({ children, onClose, width = 460 }) {
  return (
    <div style={{ position: "fixed", inset: 0, background: "rgba(46,33,24,0.5)", display: "flex", alignItems: "center", justifyContent: "center", zIndex: 50 }} onClick={onClose}>
      <div style={{ background: "white", borderRadius: 12, width, maxWidth: "90vw", maxHeight: "88vh", overflow: "auto", padding: 24 }} onClick={(e) => e.stopPropagation()}>{children}</div>
    </div>
  );
}

export const inputStyle = { width: "100%", padding: "9px 10px", border: `1px solid ${COLOR.line}`, borderRadius: 6, fontSize: 13 };

export function tabBtnStyle(active) {
  return { padding: "8px 16px", borderRadius: 8, border: `1px solid ${active ? COLOR.espresso : COLOR.line}`, background: active ? COLOR.espresso : "white", color: active ? COLOR.cream : COLOR.ink, fontSize: 13, fontWeight: 600, cursor: "pointer" };
}
```

- [ ] **Step 2: Write the admin login page**

`frontend/src/pages/admin/AdminLoginPage.jsx`:

```jsx
import { useState } from "react";
import { apiClient, ensureCsrf } from "../../api/client";
import { COLOR, FONT_DISPLAY } from "../../theme";
import { Button, inputStyle } from "../../components/ui";

export default function AdminLoginPage({ onLoggedIn }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState(null);

  async function submit(e) {
    e.preventDefault();
    setError(null);
    try {
      await ensureCsrf();
      await apiClient.post("/api/admin/login", { email, password });
      onLoggedIn();
    } catch {
      setError("Invalid email or password.");
    }
  }

  return (
    <div style={{ maxWidth: 360, margin: "80px auto", padding: 24 }}>
      <h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 22, marginBottom: 20 }}>Admin Login</h1>
      <form onSubmit={submit}>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, color: COLOR.inkSoft, marginBottom: 4 }}>Email</div>
          <input value={email} onChange={(e) => setEmail(e.target.value)} style={inputStyle} type="email" required />
        </div>
        <div style={{ marginBottom: 16 }}>
          <div style={{ fontSize: 12, color: COLOR.inkSoft, marginBottom: 4 }}>Password</div>
          <input value={password} onChange={(e) => setPassword(e.target.value)} style={inputStyle} type="password" required />
        </div>
        {error && <div style={{ color: COLOR.rust, fontSize: 12, marginBottom: 12 }}>{error}</div>}
        <Button variant="gold">Log In</Button>
      </form>
    </div>
  );
}
```

- [ ] **Step 3: Write the admin app shell (login gate + tabs)**

`frontend/src/pages/admin/AdminApp.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { tabBtnStyle } from "../../components/ui";
import AdminLoginPage from "./AdminLoginPage";
import AttendanceView from "./AttendanceView";
import PayrollView from "./PayrollView";
import ThirteenthMonthView from "./ThirteenthMonthView";
import SettingsView from "./SettingsView";
import AuditLogView from "./AuditLogView";

const TABS = [
  ["attendance", "Attendance"],
  ["payroll", "Payroll"],
  ["thirteenth-month", "13th Month"],
  ["settings", "Settings"],
  ["audit", "Audit Log"],
];

export default function AdminApp() {
  const [admin, setAdmin] = useState(undefined); // undefined = loading, null = logged out
  const [tab, setTab] = useState("attendance");

  useEffect(() => {
    apiClient.get("/api/admin/me").then((res) => setAdmin(res.data)).catch(() => setAdmin(null));
  }, []);

  if (admin === undefined) return <div style={{ padding: 32 }}>Loading...</div>;
  if (admin === null) return <AdminLoginPage onLoggedIn={() => apiClient.get("/api/admin/me").then((res) => setAdmin(res.data))} />;

  return (
    <div style={{ maxWidth: 1180, margin: "0 auto", padding: "28px 32px" }}>
      <div style={{ display: "flex", gap: 8, marginBottom: 20, flexWrap: "wrap" }}>
        {TABS.map(([key, label]) => (
          <button key={key} onClick={() => setTab(key)} style={tabBtnStyle(tab === key)}>{label}</button>
        ))}
      </div>
      {tab === "attendance" && <AttendanceView />}
      {tab === "payroll" && <PayrollView />}
      {tab === "thirteenth-month" && <ThirteenthMonthView />}
      {tab === "settings" && <SettingsView />}
      {tab === "audit" && <AuditLogView />}
    </div>
  );
}
```

- [ ] **Step 4: Write the attendance view and adjust modal**

`frontend/src/components/AdjustAttendanceModal.jsx`:

```jsx
import { useState } from "react";
import { apiClient } from "../api/client";
import { formatTime12 } from "../theme";
import { Button, ModalShell, inputStyle } from "./ui";

const REASONS = ["Late Arrival", "Early Departure", "Forgot to Clock In/Out", "System Error", "Power / Internet Outage", "Client / Supplier Errand", "Other"];

export default function AdjustAttendanceModal({ row, onCancel, onSaved }) {
  const [clockIn, setClockIn] = useState((row.record.clock_in || "08:00").slice(0, 5));
  const [clockOut, setClockOut] = useState((row.record.clock_out || "17:00").slice(0, 5));
  const [reason, setReason] = useState("");
  const [details, setDetails] = useState("");

  async function save() {
    await apiClient.patch(`/api/admin/attendance/${row.record.id}/adjust`, { clock_in: clockIn, clock_out: clockOut, reason, details });
    onSaved();
  }

  return (
    <ModalShell onClose={onCancel}>
      <h3 style={{ margin: "0 0 2px" }}>Adjust Attendance</h3>
      <div style={{ fontSize: 12.5, color: "#7A6A57", marginBottom: 16 }}>
        {row.employee.short_name} · original: {formatTime12(row.record.clock_in)} → {row.record.clock_out ? formatTime12(row.record.clock_out) : "still clocked in"}
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12, marginBottom: 14 }}>
        <div>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Clock In</div>
          <input type="time" value={clockIn} onChange={(e) => setClockIn(e.target.value)} style={inputStyle} />
        </div>
        <div>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Clock Out</div>
          <input type="time" value={clockOut} onChange={(e) => setClockOut(e.target.value)} style={inputStyle} />
        </div>
      </div>
      <div style={{ fontSize: 12, marginBottom: 4 }}>Reason for Adjustment *</div>
      <select value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, marginBottom: 14 }}>
        <option value="">Select a reason...</option>
        {REASONS.map((r) => <option key={r} value={r}>{r}</option>)}
      </select>
      <div style={{ fontSize: 12, marginBottom: 4 }}>Additional Details</div>
      <textarea value={details} onChange={(e) => setDetails(e.target.value)} style={{ ...inputStyle, minHeight: 64, marginBottom: 18 }} />
      <div style={{ display: "flex", gap: 8 }}>
        <Button variant="gold" disabled={!reason} onClick={save}>Save Adjustment</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </ModalShell>
  );
}
```

`frontend/src/pages/admin/AttendanceView.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP, formatTime12 } from "../../theme";
import { Button, Pill, StatCard } from "../../components/ui";
import AdjustAttendanceModal from "../../components/AdjustAttendanceModal";

export default function AttendanceView() {
  const [data, setData] = useState(null);
  const [adjustRow, setAdjustRow] = useState(null);

  function load() {
    apiClient.get("/api/admin/attendance/today").then((res) => setData(res.data));
  }
  useEffect(load, []);

  async function approve(recordId) {
    await apiClient.post(`/api/admin/attendance/${recordId}/approve`);
    load();
  }

  if (!data) return <div>Loading...</div>;

  return (
    <div>
      <h1>Attendance</h1>
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard label="Clocked In Today" value={data.clocked_in} />
        <StatCard label="Pending Approval" value={data.pending} />
        <StatCard label="Total Pay Today" value={formatPHP(data.total_pay_today)} />
        <StatCard label="Approved" value={data.approved} />
      </div>
      <table style={{ width: "100%", borderCollapse: "collapse" }}>
        <thead><tr><th>Staff</th><th>Role</th><th>Branch</th><th>Clock In</th><th>Clock Out</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          {data.rows.map((row) => (
            <tr key={row.record.id}>
              <td>{row.employee.short_name}</td>
              <td>{row.employee.role}</td>
              <td>{row.employee.branch.name}</td>
              <td>{formatTime12(row.record.clock_in)}</td>
              <td>{row.record.clock_out ? formatTime12(row.record.clock_out) : "—"}</td>
              <td><Pill tone={row.record.status === "approved" ? "approved" : "pending"}>{row.record.status}</Pill></td>
              <td>
                <Button small variant="outline" onClick={() => setAdjustRow(row)}>Edit Times</Button>{" "}
                {row.record.status === "pending" && <Button small variant="gold" onClick={() => approve(row.record.id)}>Approve</Button>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {adjustRow && <AdjustAttendanceModal row={adjustRow} onCancel={() => setAdjustRow(null)} onSaved={() => { setAdjustRow(null); load(); }} />}
    </div>
  );
}
```

- [ ] **Step 5: Manually verify**

Create an admin user first: `php artisan tinker --execute="\App\Models\User::factory()->create(['email'=>'admin@ongkoleyt.test','password'=>bcrypt('password')]);"`. Log in at `http://localhost:5173/admin` with those credentials. Expected: stats + today's log render from the live API; "Edit Times" opens the modal, saving requires a reason and immediately reflects in the table.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/admin/AdminApp.jsx frontend/src/pages/admin/AdminLoginPage.jsx frontend/src/pages/admin/AttendanceView.jsx frontend/src/components/AdjustAttendanceModal.jsx frontend/src/components/ui.jsx
git commit -m "feat(frontend): add admin login gate and attendance view"
```

---

### Task 26: Admin payroll view

**Files:**
- Create: `frontend/src/pages/admin/PayrollView.jsx`

**Interfaces:**
- Consumes: `GET /api/admin/payroll/daily`, `GET /api/admin/payroll/weekly` (Task 12), `GET /api/admin/payroll/export` (Task 13), `GET /api/admin/payroll/pdf` (Task 14); `Button`/`StatCard`/`tabBtnStyle` from `ui.jsx`.
- Produces: the payroll tab plugged into `AdminApp` (Task 25) — no downstream consumers.

- [ ] **Step 1: Write the view**

`frontend/src/pages/admin/PayrollView.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP, formatHoursLabel, formatTime12 } from "../../theme";
import { Button, StatCard, tabBtnStyle } from "../../components/ui";

export default function PayrollView() {
  const [range, setRange] = useState("daily");
  const [data, setData] = useState(null);

  useEffect(() => {
    const endpoint = range === "daily" ? "/api/admin/payroll/daily" : "/api/admin/payroll/weekly";
    apiClient.get(endpoint).then((res) => setData(res.data));
  }, [range]);

  function download(kind) {
    const url = `${apiClient.defaults.baseURL}/api/admin/payroll/${kind}?range=${range}`;
    window.open(url, "_blank");
  }

  if (!data) return <div>Loading...</div>;

  return (
    <div>
      <h1>Payroll</h1>
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard label={`Total ${range === "daily" ? "Today" : "This Week"}`} value={formatPHP(data.total)} />
      </div>
      <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 14 }}>
        <div style={{ display: "flex", gap: 8 }}>
          <button onClick={() => setRange("daily")} style={tabBtnStyle(range === "daily")}>Daily</button>
          <button onClick={() => setRange("weekly")} style={tabBtnStyle(range === "weekly")}>Weekly</button>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <Button variant="outline" onClick={() => download("export")}>⬇ CSV</Button>
          <Button variant="outline" onClick={() => download("pdf")}>⬇ PDF</Button>
        </div>
      </div>
      <table style={{ width: "100%", borderCollapse: "collapse" }}>
        {range === "daily" ? (
          <>
            <thead><tr><th>Staff</th><th>Role</th><th>Branch</th><th>Clock In</th><th>Clock Out</th><th>Total Pay</th></tr></thead>
            <tbody>
              {data.rows.map((r) => (
                <tr key={r.record.id}>
                  <td>{r.employee.short_name}</td><td>{r.employee.role}</td><td>{r.employee.branch.name}</td>
                  <td>{formatTime12(r.record.clock_in)}</td><td>{formatTime12(r.record.clock_out)}</td>
                  <td>{formatPHP(r.pay.total)}</td>
                </tr>
              ))}
            </tbody>
          </>
        ) : (
          <>
            <thead><tr><th>Staff</th><th>Role</th><th>Branch</th><th>Days Worked</th><th>Total Hours</th><th>Total Pay</th></tr></thead>
            <tbody>
              {data.rows.map((r) => (
                <tr key={r.employee_id}>
                  <td>{r.employee.short_name}</td><td>{r.employee.role}</td><td>{r.employee.branch.name}</td>
                  <td>{r.days_worked}</td><td>{r.total_hours}h</td><td>{formatPHP(r.total)}</td>
                </tr>
              ))}
            </tbody>
          </>
        )}
      </table>
    </div>
  );
}
```

- [ ] **Step 2: Manually verify**

Open the Payroll tab in the admin app. Expected: daily/weekly toggle re-fetches; CSV/PDF buttons open a download in a new tab with real data.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/admin/PayrollView.jsx
git commit -m "feat(frontend): add admin payroll view with CSV/PDF export"
```

### Task 27: Admin 13th month view and modals

**Files:**
- Create: `frontend/src/pages/admin/ThirteenthMonthView.jsx`
- Create: `frontend/src/components/TmAdjustModal.jsx`
- Create: `frontend/src/components/TmUnlockModal.jsx`

**Interfaces:**
- Consumes: `GET /api/admin/thirteenth-month`, `POST .../compute`, `.../compute-all`, `.../recompute`, `.../adjust`, `.../lock`, `.../unlock`, `.../release` (Tasks 16–17), `GET /api/admin/thirteenth-month/{id}/payslip` (Task 18).
- Produces: the 13th month tab plugged into `AdminApp` — no downstream consumers.

- [ ] **Step 1: Write the adjust and unlock modals**

`frontend/src/components/TmAdjustModal.jsx`:

```jsx
import { useState } from "react";
import { apiClient } from "../api/client";
import { formatPHP } from "../theme";
import { Button, ModalShell, inputStyle } from "./ui";

export default function TmAdjustModal({ row, onCancel, onSaved }) {
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");

  async function confirm() {
    await apiClient.post(`/api/admin/thirteenth-month/${row.employee.id}/adjust`, { amount: Number(amount), reason });
    onSaved();
  }

  return (
    <ModalShell onClose={onCancel}>
      <h3>Manual Adjustment</h3>
      <p style={{ fontSize: 12, color: "#7A6A57" }}>{row.employee.short_name} · Current amount {formatPHP(row.adjusted_amount)}</p>
      <div style={{ marginBottom: 12 }}>
        <div style={{ fontSize: 12, marginBottom: 4 }}>Adjustment Amount (negative for deduction)</div>
        <input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} style={inputStyle} />
      </div>
      <div style={{ marginBottom: 12 }}>
        <div style={{ fontSize: 12, marginBottom: 4 }}>Reason</div>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </div>
      <div style={{ display: "flex", gap: 8 }}>
        <Button variant="gold" disabled={!amount || reason.length < 5} onClick={confirm}>Apply Adjustment</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </ModalShell>
  );
}
```

`frontend/src/components/TmUnlockModal.jsx`:

```jsx
import { useState } from "react";
import { apiClient } from "../api/client";
import { Button, ModalShell, inputStyle } from "./ui";

export default function TmUnlockModal({ row, onCancel, onSaved }) {
  const [reason, setReason] = useState("");

  async function confirm() {
    await apiClient.post(`/api/admin/thirteenth-month/${row.employee.id}/unlock`, { reason });
    onSaved();
  }

  return (
    <ModalShell onClose={onCancel}>
      <h3>Unlock Record</h3>
      <p style={{ fontSize: 12, color: "#7A6A57" }}>{row.employee.short_name} — unlocking a released record requires an approval reason.</p>
      <div style={{ marginBottom: 12 }}>
        <div style={{ fontSize: 12, marginBottom: 4 }}>Reason for unlock</div>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </div>
      <div style={{ display: "flex", gap: 8 }}>
        <Button variant="danger" disabled={reason.length < 5} onClick={confirm}>Confirm Unlock</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </ModalShell>
  );
}
```

- [ ] **Step 2: Write the view**

`frontend/src/pages/admin/ThirteenthMonthView.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP } from "../../theme";
import { Button, Pill, StatCard } from "../../components/ui";
import TmAdjustModal from "../../components/TmAdjustModal";
import TmUnlockModal from "../../components/TmUnlockModal";

export default function ThirteenthMonthView() {
  const [records, setRecords] = useState([]);
  const [adjustRow, setAdjustRow] = useState(null);
  const [unlockRow, setUnlockRow] = useState(null);

  function load() {
    apiClient.get("/api/admin/thirteenth-month").then((res) => setRecords(res.data.records));
  }
  useEffect(load, []);

  async function act(employeeId, action) {
    await apiClient.post(`/api/admin/thirteenth-month/${employeeId}/${action}`);
    load();
  }

  function downloadPayslip(employeeId) {
    window.open(`${apiClient.defaults.baseURL}/api/admin/thirteenth-month/${employeeId}/payslip`, "_blank");
  }

  const pending = records.filter((r) => r.status === "pending").length;
  const totalLiability = records.reduce((s, r) => s + r.adjusted_amount, 0);

  return (
    <div>
      <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 18 }}>
        <h1>13th Month Pay</h1>
        <Button variant="gold" disabled={pending === 0} onClick={() => act(0, "compute-all").then(load)}>Compute All Pending ({pending})</Button>
      </div>
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard label="Eligible Employees" value={records.length} />
        <StatCard label="Total Liability" value={formatPHP(totalLiability)} />
        <StatCard label="Pending" value={pending} />
      </div>
      <table style={{ width: "100%", borderCollapse: "collapse" }}>
        <thead><tr><th>Employee</th><th>Role</th><th>Branch</th><th>13th Month Amount</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          {records.map((r) => (
            <tr key={r.employee.id}>
              <td>{r.employee.short_name}</td>
              <td>{r.employee.role}</td>
              <td>{r.employee.branch.name}</td>
              <td>{formatPHP(r.adjusted_amount)}</td>
              <td><Pill tone={r.status}>{r.status}</Pill></td>
              <td>
                {r.status === "pending" && <Button small variant="gold" onClick={() => act(r.employee.id, "compute")}>Compute</Button>}
                {r.status !== "pending" && r.status !== "locked" && <Button small variant="outline" onClick={() => act(r.employee.id, "recompute")}>Recompute</Button>}
                {r.status !== "pending" && r.status !== "locked" && <Button small variant="outline" onClick={() => setAdjustRow(r)}>Adjust</Button>}
                {r.status === "computed" && <Button small variant="primary" onClick={() => act(r.employee.id, "release")}>Release</Button>}
                {(r.status === "computed" || r.status === "released") && <Button small variant="danger" onClick={() => act(r.employee.id, "lock")}>Lock</Button>}
                {r.status === "locked" && <Button small variant="outline" onClick={() => setUnlockRow(r)}>Unlock</Button>}
                {r.status !== "pending" && <Button small variant="ghost" onClick={() => downloadPayslip(r.employee.id)}>Payslip</Button>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {adjustRow && <TmAdjustModal row={adjustRow} onCancel={() => setAdjustRow(null)} onSaved={() => { setAdjustRow(null); load(); }} />}
      {unlockRow && <TmUnlockModal row={unlockRow} onCancel={() => setUnlockRow(null)} onSaved={() => { setUnlockRow(null); load(); }} />}
    </div>
  );
}
```

Note: `compute-all` posts to `/api/admin/thirteenth-month/0/compute-all`; fix by calling `apiClient.post('/api/admin/thirteenth-month/compute-all')` directly instead of routing through `act()`'s per-employee URL pattern — replace the button's `onClick` with:

```jsx
<Button variant="gold" disabled={pending === 0} onClick={() => apiClient.post("/api/admin/thirteenth-month/compute-all").then(load)}>Compute All Pending ({pending})</Button>
```

- [ ] **Step 3: Manually verify**

Open the 13th Month tab. Expected: eligible employees list as `pending`; "Compute All Pending" moves them to `computed` with a real ₱ amount; Release → Lock → Unlock (reason required) cycles correctly; Payslip opens a real PDF.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/admin/ThirteenthMonthView.jsx frontend/src/components/TmAdjustModal.jsx frontend/src/components/TmUnlockModal.jsx
git commit -m "feat(frontend): add admin 13th month view with compute/lock/release lifecycle"
```

### Task 28: Admin settings view and audit log view

**Files:**
- Create: `frontend/src/pages/admin/SettingsView.jsx`
- Create: `frontend/src/pages/admin/AuditLogView.jsx`

**Interfaces:**
- Consumes: `GET`/`PUT /api/admin/settings` (Task 19), `GET /api/admin/audit-log` (Task 20).
- Produces: the final two admin tabs — completes `AdminApp` from Task 25.

- [ ] **Step 1: Write the settings view**

`frontend/src/pages/admin/SettingsView.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { Button, inputStyle } from "../../components/ui";

const EARNING_CODES = { BASIC: "Basic Salary", OVERTIME: "Overtime Pay", NIGHT_DIFF: "Night Differential", HOLIDAY_PREMIUM: "Holiday Premium", ALLOWANCE: "Allowances", BONUS: "Bonuses", INCENTIVE: "Incentives", COMMISSION: "Commissions", LEAVE_CONVERSION: "Leave Conversion" };
const EMPLOYMENT_TYPES = ["regular", "probationary", "fixed_term", "seasonal"];

export default function SettingsView() {
  const [settings, setSettings] = useState(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    apiClient.get("/api/admin/settings").then((res) => setSettings(res.data));
  }, []);

  function set(field, value) {
    setSettings((s) => ({ ...s, [field]: value }));
  }

  function toggleEarning(code) {
    if (code === "BASIC") return;
    const list = settings.included_earnings.includes(code)
      ? settings.included_earnings.filter((c) => c !== code)
      : [...settings.included_earnings, code];
    set("included_earnings", list);
  }

  function toggleType(type) {
    const list = settings.employment_types_included.includes(type)
      ? settings.employment_types_included.filter((t) => t !== type)
      : [...settings.employment_types_included, type];
    set("employment_types_included", list);
  }

  async function save() {
    await apiClient.put("/api/admin/settings", settings);
    setSaved(true);
    setTimeout(() => setSaved(false), 2000);
  }

  if (!settings) return <div>Loading...</div>;

  return (
    <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20 }}>
      <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 20 }}>
        <h3>Basic Pay Rate & Overtime</h3>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Daily Basic Rate (₱)</div>
          <input type="number" value={settings.daily_basic_rate} onChange={(e) => set("daily_basic_rate", e.target.value)} style={inputStyle} />
        </div>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Overtime Multiplier</div>
          <input type="number" step="0.05" value={settings.overtime_multiplier} onChange={(e) => set("overtime_multiplier", e.target.value)} style={inputStyle} />
        </div>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Night Differential Multiplier</div>
          <input type="number" step="0.01" value={settings.night_diff_multiplier} onChange={(e) => set("night_diff_multiplier", e.target.value)} style={inputStyle} />
        </div>
        <Button variant="gold" onClick={save}>{saved ? "Saved ✓" : "Save Settings"}</Button>
      </div>

      <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 20 }}>
        <h3>13th Month Period & Release</h3>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Release Date</div>
          <input type="date" value={settings.release_date} onChange={(e) => set("release_date", e.target.value)} style={inputStyle} />
        </div>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Minimum Months of Service</div>
          <input type="number" value={settings.minimum_months} onChange={(e) => set("minimum_months", e.target.value)} style={inputStyle} />
        </div>
      </div>

      <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 20 }}>
        <h3>Included Earnings (13th Month Base)</h3>
        {Object.entries(EARNING_CODES).map(([code, label]) => (
          <label key={code} style={{ display: "flex", gap: 10, padding: "7px 0", fontSize: 13 }}>
            <input type="checkbox" checked={settings.included_earnings.includes(code)} disabled={code === "BASIC"} onChange={() => toggleEarning(code)} />
            {label}
          </label>
        ))}
      </div>

      <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 20 }}>
        <h3>Employment Type Eligibility</h3>
        {EMPLOYMENT_TYPES.map((type) => (
          <label key={type} style={{ display: "flex", gap: 8, fontSize: 13, marginBottom: 8 }}>
            <input type="checkbox" checked={settings.employment_types_included.includes(type)} onChange={() => toggleType(type)} />
            {type.replace("_", " ")}
          </label>
        ))}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Write the audit log view**

`frontend/src/pages/admin/AuditLogView.jsx`:

```jsx
import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP } from "../../theme";
import { Pill } from "../../components/ui";

export default function AuditLogView() {
  const [log, setLog] = useState([]);

  useEffect(() => {
    apiClient.get("/api/admin/audit-log").then((res) => setLog(res.data));
  }, []);

  if (log.length === 0) {
    return <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 40, textAlign: "center", color: "#7A6A57" }}>No actions recorded yet.</div>;
  }

  return (
    <table style={{ width: "100%", borderCollapse: "collapse" }}>
      <thead><tr><th>Timestamp</th><th>Staff</th><th>Area</th><th>Action</th><th>Detail / Old → New</th><th>Reason</th></tr></thead>
      <tbody>
        {log.map((entry) => (
          <tr key={entry.id}>
            <td>{new Date(entry.created_at).toLocaleString()}</td>
            <td>{entry.employee ? entry.employee.short_name : "All eligible employees"}</td>
            <td><Pill>{entry.type === "attendance" ? "Attendance" : "13th Month"}</Pill></td>
            <td><Pill tone="neutral">{entry.action.replace("_", " ")}</Pill></td>
            <td>{entry.detail || (entry.old_amount != null ? `${formatPHP(entry.old_amount)} → ${formatPHP(entry.new_amount)}` : "—")}</td>
            <td>{entry.reason}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
```

- [ ] **Step 3: Manually verify end-to-end**

Walk through the full admin flow: adjust an attendance record, compute/release a 13th month record, then open the Audit Log tab. Expected: both actions appear, newest first, each with the reason that was entered.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/admin/SettingsView.jsx frontend/src/pages/admin/AuditLogView.jsx
git commit -m "feat(frontend): add admin settings and unified audit log views"
```

---

## Phase 9 — Deployment Prep

### Task 29: Production build and deployment guide

**Files:**
- Create: `backend/.env.production.example`
- Create: `frontend/.env.production`
- Create: `DEPLOYMENT.md`

**Interfaces:**
- Consumes: the completed backend (Tasks 1–21) and frontend (Tasks 22–28).
- Produces: a documented, repeatable path from `git clone` to a live server matching the proposal's ₱1,500/mo shared-hosting-or-small-VPS hosting fee — no further tasks depend on this; it's the handoff artifact for whoever manages hosting.

- [ ] **Step 1: Write the backend production env template**

`backend/.env.production.example`:

```
APP_NAME=Ongkoleyt
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://api.ongkoleyt.example

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ongkoleyt_payroll
DB_USERNAME=
DB_PASSWORD=

SANCTUM_STATEFUL_DOMAINS=ongkoleyt.example
SESSION_DOMAIN=.ongkoleyt.example
SESSION_SECURE_COOKIE=true
```

- [ ] **Step 2: Write the frontend production env**

`frontend/.env.production`:

```
VITE_API_BASE_URL=https://api.ongkoleyt.example
```

Update `frontend/src/api/client.js` to read it:

```javascript
export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || "http://127.0.0.1:8000",
  withCredentials: true,
  headers: { Accept: "application/json" },
});
```

- [ ] **Step 3: Write the deployment guide**

`DEPLOYMENT.md`:

```markdown
# Deployment Guide

## Backend (Laravel API)

1. Provision a server/hosting plan with PHP 8.2+, MySQL 8, and Composer (a small VPS such as a 1-2GB DigitalOcean/Vultr droplet, or shared hosting with SSH access, comfortably fits the proposal's ₱1,500/mo hosting fee).
2. Clone the repo, `cd backend`, `composer install --no-dev --optimize-autoloader`.
3. Copy `.env.production.example` to `.env`, fill in DB credentials and `APP_URL`.
4. `php artisan key:generate --force`.
5. `php artisan migrate --force`.
6. `php artisan db:seed --force` (only for initial go-live with the demo roster — replace with the client's real employee list before production use).
7. Point the web server's document root at `backend/public`, or run behind Nginx + PHP-FPM with a standard Laravel server block.
8. Set up HTTPS (Let's Encrypt via certbot) — required for Sanctum's `SESSION_SECURE_COOKIE=true`.
9. Set up a cron entry for Laravel's scheduler (not used yet, but standard practice): `* * * * * php /path/to/backend/artisan schedule:run >> /dev/null 2>&1`.

## Frontend (React SPA)

1. `cd frontend`, `npm install`, `npm run build` — outputs static files to `frontend/dist`.
2. Deploy `frontend/dist` to any static host (same server via Nginx, or a separate static host) at the domain listed in `SANCTUM_STATEFUL_DOMAINS`.
3. Ensure the frontend's origin is HTTPS and matches `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` exactly, or the Sanctum cookie session will silently fail.

## Post-deploy checklist (mirrors the Handover Checklist in `Ongkoleyt-Client-Handover.docx`)

- [ ] Replace seeded demo employees with the real roster and real PINs (never ship the `1234` demo PIN to production).
- [ ] Create the real admin login(s) via `php artisan tinker` or a one-off seeder, then delete/rotate any demo admin credentials.
- [ ] Confirm daily basic rate, overtime multiplier, night differential multiplier, and 13th month settings with the client via the Settings tab before go-live.
- [ ] Walk admin staff at each branch through the kiosk + admin flows.
- [ ] Set a calendar reminder for the 14-day complimentary adjustment window mentioned in the handover doc.
```

- [ ] **Step 4: Verify the production build compiles**

```bash
cd frontend
npm run build
```

Expected: `dist/` is created with no build errors.

- [ ] **Step 5: Commit**

```bash
cd ..
git add backend/.env.production.example frontend/.env.production frontend/src/api/client.js DEPLOYMENT.md
git commit -m "docs: add production env templates and deployment guide"
```

---

## Self-Review Notes

- **Spec coverage:** every bullet in `Ongkoleyt-Client-Handover.docx`'s "The Solution" section maps to a task — Kiosk Clock In/Out (9), Staff Timesheet Login (23–24), Admin Attendance Dashboard (11, 25), Adjust Attendance with Audit Trail (10, 25), Daily & Weekly Payroll with CSV/PDF (12–14, 26), 13th Month Pay Module incl. lock/unlock/manual adjustment (15–18, 27), One Shared Pay Rate Configuration (3, 19, 28), Unified Audit Log (20, 28).
- **Placeholder scan:** no `TODO`/`TBD` remain; the one inline fix-up in Task 27 (the `compute-all` button's `onClick`) is a real code snippet, not a placeholder — it corrects a bug in the initially-shown component code before the task is considered done.
- **Type/name consistency:** `AttendancePayCalculator::compute()` return shape (`total_hours`, `regular_hours`, `ot_hours`, `night_diff_hours`, `basic`, `ot`, `night_diff`, `total`) is used identically in Tasks 9, 11, 12, 14, 21; `ThirteenthMonthRecord::adjusted_amount` accessor (Task 4) is referenced consistently in Tasks 16–18 and the frontend's `formatPHP(r.adjusted_amount)` (Task 27).

