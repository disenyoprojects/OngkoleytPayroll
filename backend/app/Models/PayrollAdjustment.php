<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollAdjustment extends Model {
    protected $fillable = [
        'employee_id', 'date', 'label', 'category', 'amount', 'paid', 'reason', 'created_by',
    ];
    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'paid' => 'boolean',
    ];

    public function employee() {
        return $this->belongsTo(Employee::class);
    }
}
