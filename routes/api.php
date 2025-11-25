<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\SubCategoryController;
use App\Http\Controllers\Api\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Authentication routes
Route::get('/sanctum/csrf-cookie', [\Laravel\Sanctum\Http\Controllers\CsrfCookieController::class, 'show']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/user', [AuthController::class, 'user'])->middleware('auth:sanctum');

// ✅ NEW: Google OAuth routes
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);

// Event routes
Route::get('/events', [EventController::class, 'index'])->name('events.index');
Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
Route::post('/events', [EventController::class, 'store'])->middleware('auth:sanctum')->name('events.store');
Route::put('/events/{event}', [EventController::class, 'update'])->middleware('auth:sanctum')->name('events.update');
Route::delete('/events/{event}', [EventController::class, 'destroy'])->middleware('auth:sanctum')->name('events.destroy');

// Category routes
Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');

// SubCategory routes
Route::get('/subcategories', [SubCategoryController::class, 'index'])->name('subcategories.index');

// Test endpoints
Route::get('/ping', function() {
    return response()->json(['message' => 'pong', 'success' => true]);
})->name('ping');

Route::get('/cors-test', function(Request $request) {
    return response()->json([
        'message' => 'CORS is working correctly!',
        'origin' => $request->header('Origin'),
        'method' => $request->method(),
        'time' => now()->toDateTimeString(),
    ]);
})->name('cors.test');
