<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One weekday of an employee's weekly shift pattern. day_of_week follows Carbon
 * and PHP date('w'): 0 = Sunday through 6 = Saturday.
 */
class EmployeeDayShift extends Model {
    use HasFactory;

    /** Display order, Monday first — how the roster is read here. */
    public const WEEK = [1, 2, 3, 4, 5, 6, 0];

    public const DAY_NAMES = [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
    ];

    protected $fillable = ['employee_id', 'day_of_week', 'shift_start', 'shift_end'];

    protected $casts = ['day_of_week' => 'integer'];

    public function employee() {
        return $this->belongsTo(Employee::class);
    }

    // These times are copied onto attendance records and compared against clock
    // punches, so they are stored as H:i:s whatever the form sent. MySQL widens
    // "06:00" to "06:00:00" on its own and SQLite does not; normalising here
    // keeps the two engines — and so the tests and production — in agreement.
    protected function shiftStart(): Attribute {
        return Attribute::make(set: fn ($value) => self::asTime($value));
    }

    protected function shiftEnd(): Attribute {
        return Attribute::make(set: fn ($value) => self::asTime($value));
    }

    private static function asTime(?string $value): ?string {
        return $value === null || $value === '' ? null : substr($value, 0, 5) . ':00';
    }
}
