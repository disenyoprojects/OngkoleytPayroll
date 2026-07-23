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
