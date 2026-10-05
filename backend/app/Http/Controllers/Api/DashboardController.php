<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    public function overview(Request $request)
    {
        $windowId = $request->query('window_id');
        $window = $windowId ? \App\Models\Window::findOrFail((int) $windowId) : null;

        return response()->json($this->dashboard->overview($window));
    }
}
