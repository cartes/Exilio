<?php

use App\Http\Controllers\AgentAutonomousController;
use App\Http\Controllers\AgentChatController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentDreamController;
use App\Http\Controllers\AgentExplorationController;
use App\Http\Controllers\AgentLearningController;
use App\Http\Controllers\AgentResearchController;
use App\Http\Controllers\MemoryController;
use Illuminate\Support\Facades\Route;

Route::get('/agents', [AgentController::class, 'index']);
Route::get('/agents/{agent}', [AgentController::class, 'show']);

Route::get('/agents/{agent}/chat', [AgentChatController::class, 'index']);
Route::post('/agents/{agent}/chat', [AgentChatController::class, 'chat']);
Route::get('/agents/{agent}/ai-usage', [AgentChatController::class, 'usage']);

Route::post('/agents/{agent}/explore', [AgentExplorationController::class, 'inquire']);
Route::post('/agents/{agent}/explore/persist', [AgentExplorationController::class, 'persist']);
Route::post('/agents/{agent}/autonomous-step', [AgentAutonomousController::class, 'step']);

Route::post('/agents/{agent}/dream', [AgentDreamController::class, 'dream']);
Route::post('/agents/{agent}/dream/persist', [AgentDreamController::class, 'persist']);

Route::get('/agents/{agent}/versions', [AgentLearningController::class, 'index']);
Route::post('/agents/{agent}/learn', [AgentLearningController::class, 'learn']);
Route::post('/agents/{agent}/versions/{version}/revert', [AgentLearningController::class, 'revert']);

Route::get('/agents/{agent}/memories', [MemoryController::class, 'index']);
Route::post('/agents/{agent}/memories', [MemoryController::class, 'store']);
Route::get('/agents/{agent}/tombstones', [MemoryController::class, 'tombstones']);
Route::get('/memories/{memory}', [MemoryController::class, 'show']);
Route::post('/memories/{memory}/rectify', [MemoryController::class, 'rectify']);
Route::delete('/memories/{memory}', [MemoryController::class, 'destroy']);

Route::get('/agents/{agent}/research/scouts', [AgentResearchController::class, 'index']);
Route::post('/agents/{agent}/research/scouts', [AgentResearchController::class, 'store']);
Route::post('/agents/{agent}/research/propose', [AgentResearchController::class, 'propose']);
Route::get('/research/scouts/{scout}', [AgentResearchController::class, 'show']);
Route::post('/research/scouts/{scout}/approve', [AgentResearchController::class, 'approve']);
Route::post('/research/scouts/{scout}/reject', [AgentResearchController::class, 'reject']);
Route::post('/research/scouts/{scout}/run', [AgentResearchController::class, 'run']);
Route::post('/research/scouts/{scout}/cancel', [AgentResearchController::class, 'cancel']);
Route::post('/research/findings/{finding}/approve', [AgentResearchController::class, 'approveFinding']);
Route::post('/research/findings/{finding}/reject', [AgentResearchController::class, 'rejectFinding']);
