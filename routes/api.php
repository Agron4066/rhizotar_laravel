<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TestController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ReportController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/test', [TestController::class, 'index']);
Route::get('/stream', [StreamController::class, 'stream']);
Route::post('/chat', [ChatController::class, 'stream']);
Route::post('/chat/continue', [ChatController::class, 'continue']);
Route::post('/chat/respond', [ChatController::class, 'respond']);
Route::post('/chat/respond-continue', [ChatController::class, 'respondContinue']);
Route::post('/report/generate', [ReportController::class, 'generate']);
Route::post('/appointment/memo', [\App\Http\Controllers\AppointmentController::class, 'memo']);
Route::post('/customer/summarize', [\App\Http\Controllers\CustomerSummaryController::class, 'summarize']);
