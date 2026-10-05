<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReturnService;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    use ResolvesContext;

    public function __construct(private ReturnService $returns)
    {
    }

    public function scan(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string',
            'window_id' => 'required|integer|exists:windows,id',   // 实际接收窗口（可与发放窗口不同）
            'staff_id' => 'required|integer|exists:staff,id',
        ]);

        $result = $this->returns->scan(
            trim($data['code']),
            $this->window($request),
            $this->staff($request),
        );

        // duplicate=true 时返回 200，提示前端"重复扫描只算一次"
        return response()->json([
            'duplicate' => $result['duplicate'],
            'message' => $result['duplicate'] ? '该归还码已登记过归还，重复扫描只算一次' : '归还登记成功',
            'receipt' => $result['receipt'],
        ], $result['duplicate'] ? 200 : 201);
    }
}
