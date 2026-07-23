<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeEarning extends Model {
    protected $fillable = ['employee_id', 'year', 'month', 'code', 'amount'];

    public function employee() {
        return $this->belongsTo(Employee::class);
    }
}
