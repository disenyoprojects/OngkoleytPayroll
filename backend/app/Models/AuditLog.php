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
