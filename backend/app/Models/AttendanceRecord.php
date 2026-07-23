<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model {
    use HasFactory;

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
