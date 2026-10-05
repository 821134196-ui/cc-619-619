<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShiftHandover;
use App\Models\Window;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class HandoverController extends Controller
{
    use ResolvesContext;

    public function __construct(private DashboardService $dashboard)
    {
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'window_id' => 'required|integer|exists:windows,id',
            'from_staff_id' => 'required|integer|exists:staff,id',
            'to_staff_id' => 'required|integer|exists:staff,id',
        ]);

        $handover = $this->dashboard->handover(
            Window::findOrFail($data['window_id']),
            \App\Models\Staff::findOrFail($data['from_staff_id']),
            \App\Models\Staff::findOrFail($data['to_staff_id']),
        );

        return response()->json($handover->load(['window', 'fromStaff:id,name', 'toStaff:id,name']), 201);
    }

    public function index(Request $request)
    {
        return ShiftHandover::with(['window', 'fromStaff:id,name', 'toStaff:id,name'])
            ->when($request->query('window_id'), fn ($q, $id) => $q->where('window_id', $id))
            ->orderByDesc('id')->limit(50)->get();
    }
}
