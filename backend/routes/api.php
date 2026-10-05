<?php

use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HandoverController;
use App\Http\Controllers\Api\IssuanceController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\MovementController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\StocktakeController;
use Illuminate\Support\Facades\Route;

Route::get('/meta', MetaController::class);

Route::get('/dashboard', [DashboardController::class, 'overview']);

Route::post('/issuances', [IssuanceController::class, 'store']);
Route::get('/issuances', [IssuanceController::class, 'index']);

Route::post('/returns/scan', [ReturnController::class, 'scan']);

Route::get('/claims', [ClaimController::class, 'index']);
Route::post('/claims/broken', [ClaimController::class, 'storeBroken']);
Route::post('/claims/lost', [ClaimController::class, 'storeLost']);
Route::post('/claims/{claim}/pay', [ClaimController::class, 'pay']);

Route::get('/stocktakes', [StocktakeController::class, 'index']);
Route::post('/stocktakes', [StocktakeController::class, 'store']);
Route::get('/stocktakes/{stocktake}', [StocktakeController::class, 'show']);
Route::post('/stocktakes/{stocktake}/counts', [StocktakeController::class, 'recordCount']);
Route::post('/stocktakes/{stocktake}/submit', [StocktakeController::class, 'submit']);
Route::post('/stocktakes/{stocktake}/freeze', [StocktakeController::class, 'freeze']);

Route::get('/movements', [MovementController::class, 'index']);

Route::post('/handovers', [HandoverController::class, 'store']);
Route::get('/handovers', [HandoverController::class, 'index']);
