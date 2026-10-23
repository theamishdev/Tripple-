<?php

use App\Http\Controllers\Api\MockInsurerController;
use App\Http\Controllers\Api\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PolicyFlow REST API Routes
|--------------------------------------------------------------------------
*/

// Policy Request Intake, Query, Submission, and Reconciliation
Route::prefix('policy-requests')->group(function () {
    Route::get('/', [PolicyRequestController::class, 'index']);
    Route::post('/', [PolicyRequestController::class, 'store']);
    Route::get('/{id}', [PolicyRequestController::class, 'show']);
    Route::post('/{id}/submit', [PolicyRequestController::class, 'submit']);
    Route::post('/{id}/reconcile', [PolicyRequestController::class, 'reconcile']);
});

// Mock Insurer Simulation Endpoints
Route::prefix('mock-insurer')->group(function () {
    Route::post('/issue', [MockInsurerController::class, 'issue']);
    Route::get('/policies/{provider_reference}', [MockInsurerController::class, 'show']);
    Route::post('/callback', [MockInsurerController::class, 'handleCallback']);
    Route::post('/trigger-callback', [MockInsurerController::class, 'triggerCallback']);
});
