<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model {
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_code', 'full_name', 'short_name', 'role', 'branch_id',
        'employment_type', 'shift_start', 'shift_end', 'daily_basic_rate', 'hire_date',
        'resignation_date', 'separation_type', 'separation_reason',
    ];
    protected $casts = [
        'hire_date' => 'date',
        'resignation_date' => 'date',
        'daily_basic_rate' => 'decimal:2',
    ];

    public function branch() {
        return $this->belongsTo(Branch::class);
    }

    public function attendanceRecords() {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function dayShifts() {
        return $this->hasMany(EmployeeDayShift::class);
    }

    /**
     * The shift this employee stands on a given date: the weekly pattern's row
     * for that weekday, else the employee's default shift. Callers that resolve
     * many dates should eager-load `dayShifts` — the loaded collection is used
     * in preference to a query so a payslip does not fire one per day.
     *
     * @return array{start: ?string, end: ?string}
     */
    public function shiftFor($date): array {
        $default = ['start' => $this->shift_start, 'end' => $this->shift_end];
        if (! $date) {
            return $default;
        }

        $dayOfWeek = (int) \Illuminate\Support\Carbon::parse($date)->dayOfWeek;
        $row = $this->relationLoaded('dayShifts')
            ? $this->dayShifts->firstWhere('day_of_week', $dayOfWeek)
            : $this->dayShifts()->where('day_of_week', $dayOfWeek)->first();

        return $row ? ['start' => $row->shift_start, 'end' => $row->shift_end] : $default;
    }

    public function earnings() {
        return $this->hasMany(EmployeeEarning::class);
    }

    public function thirteenthMonthRecords() {
        return $this->hasMany(ThirteenthMonthRecord::class);
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
