<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\UtensilType;
use App\Models\Window;

class MetaController extends Controller
{
    public function __invoke()
    {
        return response()->json([
            'windows' => Window::orderBy('code')->get(['id', 'code', 'name']),
            'staff' => Staff::with('window:id,code,name')->orderBy('employee_no')
                ->get(['id', 'employee_no', 'name', 'role', 'current_window_id']),
            'utensil_types' => UtensilType::where('active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'unit_price_cents']),
        ]);
    }
}
