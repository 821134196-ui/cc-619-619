<?php

use App\Http\Controllers\CanteenController;
use Illuminate\Support\Facades\Route;

Route::get('/masters', [CanteenController::class, 'masters']);
Route::get('/dashboard', [CanteenController::class, 'dashboard']);

// 发放 / 归还
Route::post('/issuances', [CanteenController::class, 'issue']);
Route::get('/issuances/{id}', [CanteenController::class, 'issuanceDetail']);
Route::post('/returns/scan', [CanteenController::class, 'returnByScan']);

// 换班交接
Route::post('/shifts', [CanteenController::class, 'openShift']);
Route::post('/shifts/{id}/close', [CanteenController::class, 'closeShift']);

// 赔付与模拟收款
Route::post('/compensations/lost', [CanteenController::class, 'reportLost']);
Route::post('/compensations/damaged', [CanteenController::class, 'reportDamaged']);
Route::post('/compensations/{id}/pay', [CanteenController::class, 'pay']);

// 月末盘点
Route::post('/stock-counts', [CanteenController::class, 'createCount']);
Route::get('/stock-counts/{id}', [CanteenController::class, 'countDetail']);
Route::patch('/stock-counts/lines/{lineId}', [CanteenController::class, 'setCountPhysical']);
Route::post('/stock-counts/{id}/confirm', [CanteenController::class, 'confirmCount']);
Route::get('/reconciliation/{period}', [CanteenController::class, 'reconciliation']);

// 数量流水
Route::get('/ledger', [CanteenController::class, 'ledger']);
