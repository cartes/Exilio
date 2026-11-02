<?php

use App\Http\Controllers\AgentController;
use Illuminate\Support\Facades\Route;

Route::get('/agents', [AgentController::class, 'index']);
Route::get('/agents/{agent}', [AgentController::class, 'show']);
