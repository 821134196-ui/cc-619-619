<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Issuance;
use App\Models\UtensilType;
use App\Services\IssuanceService;
use Illuminate\Http\Request;

class IssuanceController extends Controller
{
    use ResolvesContext;

    public function __construct(private IssuanceService $issuances)
    {
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'window_id' => 'required|integer|exists:windows,id',
            'staff_id' => 'required|integer|exists:staff,id',
            'note' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.utensil_type_id' => 'required|integer|exists:utensil_types,id',
            'lines.*.qty' => 'required|integer|min:1',
        ]);

        $window = $this->window($request);
        $staff = $this->staff($request);

        $lines = collect($data['lines'])->map(fn ($l) => [
            'type' => UtensilType::findOrFail($l['utensil_type_id']),
            'qty' => $l['qty'],
        ])->all();

        $issuance = $this->issuances->issue($window, $staff, $lines, null, $data['note'] ?? null);

        return response()->json($issuance, 201);
    }

    public function index(Request $request)
    {
        return Issuance::with(['window', 'staff', 'lines.units'])
            ->when($request->query('window_id'), fn ($q, $id) => $q->where('window_id', $id))
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }
}
