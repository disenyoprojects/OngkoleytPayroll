<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;

class Employee extends Model {
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_code', 'full_name', 'short_name', 'role', 'branch_id',
        'employment_type', 'daily_basic_rate', 'hire_date', 'resignation_date',
        'separation_type', 'separation_reason', 'pin_hash',
    ];
    protected $hidden = ['pin_hash'];
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
