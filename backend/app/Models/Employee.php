<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = ['employee_no', 'name', 'role'];

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }
}
