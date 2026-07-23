<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model {
    protected $fillable = ['name'];

    public function employees() {
        return $this->hasMany(Employee::class);
    }
}
