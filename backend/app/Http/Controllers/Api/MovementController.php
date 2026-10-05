<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\UtensilType;
use Illuminate\Http\Request;

class MovementController extends Controller
{
    /**
     * 数量流水：说明期末实物如何由期初逐笔计算
     * 可按餐具类型、月份过滤。
     */
    public function index(Request $request)
    {
        $query = StockMovement::with(['utensilType:id,name,code', 'window:id,code,name'])
            ->orderBy('utensil_type_id')->orderBy('id');

        if ($tid = $request->query('utensil_type_id')) {
            $query->where('utensil_type_id', $tid);
        }
        if ($period = $request->query('period')) {
            $query->where('period', $period);
        }

        return response()->json($query->limit(1000)->get());
    }
}
