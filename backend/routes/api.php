<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\MemoryController;
use Illuminate\Support\Facades\Route;

Route::get('/agents', [AgentController::class, 'index']);
Route::get('/agents/{agent}', [AgentController::class, 'show']);

Route::get('/agents/{agent}/memories', [MemoryController::class, 'index']);
Route::post('/agents/{agent}/memories', [MemoryController::class, 'store']);
Route::get('/agents/{agent}/tombstones', [MemoryController::class, 'tombstones']);
Route::get('/memories/{memory}', [MemoryController::class, 'show']);
Route::post('/memories/{memory}/rectify', [MemoryController::class, 'rectify']);
Route::delete('/memories/{memory}', [MemoryController::class, 'destroy']);
