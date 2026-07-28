<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model {
    use HasFactory;

    protected $fillable = [
        'employee_id', 'work_date', 'shift_start', 'shift_end', 'clock_in', 'clock_out',
        'holiday_type', 'is_rest_day', 'absence_type', 'break_out', 'break_in',
        'status', 'adjusted', 'reason', 'details',
    ];
    protected $casts = [
        'work_date' => 'date',
        'adjusted' => 'boolean',
        'is_rest_day' => 'boolean',
    ];

    public function employee() {
        return $this->belongsTo(Employee::class);
    }
}
