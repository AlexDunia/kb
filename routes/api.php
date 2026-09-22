<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\CreateEventController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PaystackWebhookController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\SubCategoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/sanctum/csrf-cookie',[\Laravel\Sanctum\Http\Controllers\CsrfCookieController::class,'show']);
Route::post('/login',[AuthController::class,'login']);
Route::post('/register',[AuthController::class,'register']);
Route::post('/logout',[AuthController::class,'logout'])->middleware('auth:sanctum');
Route::get('/user',[AuthController::class,'user'])->middleware('auth:sanctum');
Route::post('/forgot-password',[PasswordResetController::class,'sendResetLink'])->middleware('throttle:5,1');
Route::post('/reset-password',[PasswordResetController::class,'reset'])->middleware('throttle:10,1');
Route::middleware(['web'])->group(function () {
    Route::get('/auth/google',[AuthController::class,'redirectToGoogle']);
    Route::get('/auth/google/callback',[AuthController::class,'handleGoogleCallback']);
});
Route::get('/events',[EventController::class,'index'])->name('events.index');
Route::get('/events/{event}',[EventController::class,'show'])->name('events.show');
Route::post('/events',[EventController::class,'store'])->middleware('auth:sanctum')->name('events.store');
Route::put('/events/{event}',[EventController::class,'update'])->middleware('auth:sanctum')->name('events.update');
Route::delete('/events/{event}',[EventController::class,'destroy'])->middleware('auth:sanctum')->name('events.destroy');
Route::get('/categories',[CategoryController::class,'index'])->name('categories.index');
Route::get('/user/liked-events',[EventController::class,'getLikedEvents']);
Route::get('/subcategories',[SubCategoryController::class,'index'])->name('subcategories.index');
Route::get('/ping',function(){return response()->json(['message'=>'pong','success'=>true]);})->name('ping');
Route::get('/cors-test',function(Request $request){return response()->json(['message'=>'CORS is working correctly!','origin'=>$request->header('Origin'),'method'=>$request->method(),'time'=>now()->toDateTimeString()]);})->name('cors.test');
Route::middleware(['auth:sanctum'])->group(function(){
    Route::get('/recommendations',[RecommendationController::class,'index']);
    Route::post('/recommendations/clear-cache',[RecommendationController::class,'clearCache']);
});
Route::middleware(['throttle:60,1'])->group(function(){Route::post('/events/{event}/track-view',[EventController::class,'trackView']);});
Route::middleware(['auth:sanctum'])->group(function(){
    Route::post('/events/{event}/toggle-like',[EventController::class,'toggleLike']);
    Route::get('/events/{event}/check-liked',[EventController::class,'checkLiked']);
});
Route::middleware(['auth:sanctum'])->group(function(){
    Route::post('/create-event',[CreateEventController::class,'store']);
    Route::get('/create-event/{id}',[CreateEventController::class,'showForEditing']);
    Route::patch('/create-event/{id}',[CreateEventController::class,'update']);
    Route::post('/create-event/{id}/publish',[CreateEventController::class,'publish']);
});
Route::prefix('checkout')->group(function(){
    Route::post('/quote',[CheckoutController::class,'quote'])->middleware('throttle:checkout-quote');
    Route::post('/initialize',[CheckoutController::class,'initialize'])->middleware('throttle:checkout-initialize');
    Route::get('/orders/{publicId}',[CheckoutController::class,'show']);
    Route::post('/payments/{reference}/verify',[CheckoutController::class,'verify'])->middleware('throttle:checkout-verify');
});
Route::post('/payments/paystack/webhook',PaystackWebhookController::class)->withoutMiddleware('throttle:api')->middleware('throttle:paystack-webhook');