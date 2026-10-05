<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stocktake;
use App\Models\UtensilType;
use App\Services\StocktakeService;
use Illuminate\Http\Request;

class StocktakeController extends Controller
{
    use ResolvesContext;

    public function __construct(private StocktakeService $stocktakes)
    {
    }

    public function index()
    {
        return Stocktake::with(['lines.utensilType', 'freezer:id,name'])
            ->orderByDesc('period')->get();
    }

    public function show(Stocktake $stocktake)
    {
        return $stocktake->load(['lines.utensilType', 'creator:id,name', 'freezer:id,name']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'staff_id' => 'required|integer|exists:staff,id',
        ]);

        $stocktake = $this->stocktakes->open($data['period'], $this->staff($request));

        return response()->json($stocktake, 201);
    }

    public function recordCount(Request $request, Stocktake $stocktake)
    {
        $data = $request->validate([
            'utensil_type_id' => 'required|integer|exists:utensil_types,id',
            'counted_qty' => 'required|integer|min:0',
        ]);

        $line = $this->stocktakes->recordCount(
            $stocktake,
            UtensilType::findOrFail($data['utensil_type_id']),
            $data['counted_qty'],
        );

        return response()->json($line);
    }

    public function submit(Request $request, Stocktake $stocktake)
    {
        return response()->json($this->stocktakes->submit($stocktake, $this->staff($request)));
    }

    public function freeze(Request $request, Stocktake $stocktake)
    {
        $supervisor = $this->staff($request);
        $this->requireSupervisor($supervisor);

        return response()->json($this->stocktakes->freeze($stocktake, $supervisor));
    }
}
