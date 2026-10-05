<?php

namespace App\Http\Controllers\Api;

use App\Models\Staff;
use App\Models\Window;
use App\Support\BusinessRuleException;

trait ResolvesContext
{
    protected function staff(\Illuminate\Http\Request $request, string $field = 'staff_id'): Staff
    {
        return Staff::findOrFail((int) $request->input($field));
    }

    protected function window(\Illuminate\Http\Request $request, string $field = 'window_id'): ?Window
    {
        $id = $request->input($field);
        return $id ? Window::findOrFail((int) $id) : null;
    }

    protected function requireSupervisor(Staff $staff): void
    {
        if (!$staff->isSupervisor()) {
            throw new BusinessRuleException('该操作需要主管权限', 403);
        }
    }
}
