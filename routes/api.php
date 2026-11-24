<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\CreateEventController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventTrackingController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PaystackWebhookController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\SubCategoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get(
    '/sanctum/csrf-cookie',
    [\Laravel\Sanctum\Http\Controllers\CsrfCookieController::class, 'show']
);

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum');

Route::get('/user', [AuthController::class, 'user'])
    ->middleware('auth:sanctum');

Route::post(
    '/forgot-password',
    [PasswordResetController::class, 'sendResetLink']
)->middleware('throttle:5,1');

Route::post(
    '/reset-password',
    [PasswordResetController::class, 'reset']
)->middleware('throttle:10,1');

Route::middleware(['web'])->group(function () {
    Route::get(
        '/auth/google',
        [AuthController::class, 'redirectToGoogle']
    );

    Route::get(
        '/auth/google/callback',
        [AuthController::class, 'handleGoogleCallback']
    );
});

Route::get('/events', [EventController::class, 'index'])
    ->name('events.index');

Route::get('/events/{event}', [EventController::class, 'show'])
    ->name('events.show');

Route::post('/events', [EventController::class, 'retiredMutation'])
    ->middleware('auth:sanctum')
    ->name('events.store');

Route::put('/events/{event}', [EventController::class, 'retiredMutation'])
    ->middleware('auth:sanctum')
    ->name('events.update');

Route::delete('/events/{event}', [EventController::class, 'retiredMutation'])
    ->middleware('auth:sanctum')
    ->name('events.destroy');

Route::post(
    '/events/{event}/track-view',
    [EventTrackingController::class, 'store']
)
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:event-view');

Route::get('/categories', [CategoryController::class, 'index'])
    ->name('categories.index');

Route::get(
    '/user/liked-events',
    [EventController::class, 'getLikedEvents']
)->middleware('auth:sanctum');

Route::get('/subcategories', [SubCategoryController::class, 'index'])
    ->name('subcategories.index');

Route::get('/ping', function () {
    return response()->json([
        'message' => 'pong',
        'success' => true,
    ]);
})->name('ping');

Route::get('/cors-test', function (Request $request) {
    return response()->json([
        'message' => 'CORS is working correctly!',
        'origin' => $request->header('Origin'),
        'method' => $request->method(),
        'time' => now()->toDateTimeString(),
    ]);
})->name('cors.test');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get(
        '/recommendations',
        [RecommendationController::class, 'index']
    );

    Route::post(
        '/recommendations/clear-cache',
        [RecommendationController::class, 'clearCache']
    );

    Route::post(
        '/events/{event}/toggle-like',
        [EventController::class, 'toggleLike']
    );

    Route::get(
        '/events/{event}/check-liked',
        [EventController::class, 'checkLiked']
    );

    Route::post(
        '/create-event',
        [CreateEventController::class, 'store']
    );

    Route::get(
        '/create-event/{id}',
        [CreateEventController::class, 'showForEditing']
    );

    Route::patch(
        '/create-event/{id}',
        [CreateEventController::class, 'update']
    );

    Route::post(
        '/create-event/{id}/publish',
        [CreateEventController::class, 'publish']
    );
});

Route::withoutMiddleware('throttle:api')->middleware([
    'auth:sanctum',
    'throttle:dashboard-read',
])->prefix('dashboard')->group(function () {
    Route::get(
        '/events',
        [DashboardController::class, 'events']
    );

    Route::get(
        '/events/{event}/overview',
        [DashboardController::class, 'overview']
    );

    Route::get(
        '/events/{event}/share-links',
        [DashboardController::class, 'shareLinks']
    );
});

Route::post(
    '/dashboard/events/{event}/share-links',
    [DashboardController::class, 'createShareLink']
)->middleware([
    'auth:sanctum',
    'throttle:dashboard-write',
]);

Route::prefix('checkout')->group(function () {
    Route::post(
        '/quote',
        [CheckoutController::class, 'quote']
    )->middleware('throttle:checkout-quote');

    Route::post(
        '/initialize',
        [CheckoutController::class, 'initialize']
    )->middleware('throttle:checkout-initialize');

    Route::get(
        '/orders/{publicId}',
        [CheckoutController::class, 'show']
    );

    Route::post(
        '/payments/{reference}/verify',
        [CheckoutController::class, 'verify']
    )->middleware('throttle:checkout-verify');
});

Route::post(
    '/payments/paystack/webhook',
    PaystackWebhookController::class
)
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:paystack-webhook');

Route::get('/buyer/orders', [\App\Http\Controllers\Api\BuyerOrderController::class, 'index'])->middleware('auth:sanctum');
Route::get('/buyer/tickets/{publicId}', [\App\Http\Controllers\Api\BuyerOrderController::class, 'ticket']);
Route::post('/checkout/recovery/request', [\App\Http\Controllers\Api\BuyerOrderController::class, 'requestRecovery'])->middleware('throttle:5,10');
Route::post('/checkout/recovery/exchange', [\App\Http\Controllers\Api\BuyerOrderController::class, 'exchangeRecovery'])->middleware('throttle:10,10');

Route::post('/events/{event}/registrations', [\App\Http\Controllers\Api\EventOperationsController::class, 'register'])->middleware('throttle:10,1');
Route::middleware('auth:sanctum')->prefix('event-operations/{event}')->group(function () {
    Route::get('/admission', [\App\Http\Controllers\Api\AdmissionController::class, 'context']);
    Route::post('/admission/lookup', [\App\Http\Controllers\Api\AdmissionController::class, 'lookup'])->middleware('throttle:60,1');
    Route::post('/admission/movements', [\App\Http\Controllers\Api\AdmissionController::class, 'move'])->middleware('throttle:120,1');
    Route::get('/admission/history', [\App\Http\Controllers\Api\AdmissionController::class, 'history']);
    Route::put('/admission/settings', [\App\Http\Controllers\Api\EventStaffController::class, 'settings']);
    Route::get('/entrances', [\App\Http\Controllers\Api\EventStaffController::class, 'entrances']);
    Route::post('/entrances', [\App\Http\Controllers\Api\EventStaffController::class, 'saveEntrance']);
    Route::put('/entrances/{entrance}', [\App\Http\Controllers\Api\EventStaffController::class, 'saveEntrance']);
    Route::get('/staff', [\App\Http\Controllers\Api\EventStaffController::class, 'staff']);
    Route::post('/staff/invitations', [\App\Http\Controllers\Api\EventStaffController::class, 'invite'])->middleware('throttle:10,1');
    Route::delete('/staff/invitations/{invitation}', [\App\Http\Controllers\Api\EventStaffController::class, 'revokeInvitation']);
    Route::delete('/staff/{member}', [\App\Http\Controllers\Api\EventStaffController::class, 'revoke']);
    Route::get('/attendees', [\App\Http\Controllers\Api\EventOperationsController::class, 'attendees']);
    Route::get('/attendees/export', [\App\Http\Controllers\Api\EventOperationsController::class, 'export']);
    Route::patch('/attendees/{attendee}', [\App\Http\Controllers\Api\EventOperationsController::class, 'update']);
    Route::get('/purchases', [\App\Http\Controllers\Api\EventOperationsController::class, 'purchases']);
    Route::post('/tickets/{ticket}/resend', [\App\Http\Controllers\Api\EventOperationsController::class, 'resend'])->middleware('throttle:10,1');
});
Route::post('/event-staff/invitations/accept', [\App\Http\Controllers\Api\EventStaffController::class, 'accept'])->middleware(['auth:sanctum', 'throttle:10,1']);

Route::get('/events/{event}/registration', [\App\Http\Controllers\Api\EventOperationsController::class, 'registrationForm']);

Route::middleware(['auth:sanctum', 'throttle:dashboard-write'])->prefix('event-operations/{event}')->group(function () {
    Route::get('/discounts', [\App\Http\Controllers\Api\EventDiscountController::class, 'index']);
    Route::post('/discounts', [\App\Http\Controllers\Api\EventDiscountController::class, 'save']);
    Route::put('/discounts/{discount}', [\App\Http\Controllers\Api\EventDiscountController::class, 'save']);
});

Route::middleware(['auth:sanctum', 'throttle:dashboard-write'])->prefix('event-operations/{event}')->group(function () {
    Route::get('/messages', [\App\Http\Controllers\Api\EventMessageController::class, 'index']);
    Route::post('/messages', [\App\Http\Controllers\Api\EventMessageController::class, 'save']);
    Route::put('/messages/{message}', [\App\Http\Controllers\Api\EventMessageController::class, 'save']);
    Route::post('/messages/{message}/send', [\App\Http\Controllers\Api\EventMessageController::class, 'send']);
    Route::post('/messages/{message}/retry', [\App\Http\Controllers\Api\EventMessageController::class, 'retry']);
});
Route::put('/checkout/orders/{publicId}/message-preferences', [\App\Http\Controllers\Api\EventMessageController::class, 'preference'])->middleware('throttle:10,1');
Route::match(['get', 'post'], '/event-message-preferences/{delivery}/unsubscribe', [\App\Http\Controllers\Api\EventMessageController::class, 'unsubscribe'])->middleware(['signed', 'throttle:10,1'])->name('event-message-unsubscribe');

Route::post('/checkout/orders/{publicId}/cancel', [CheckoutController::class, 'cancel'])->middleware('throttle:10,1');
Route::post('/event-operations/{event}/messages/audience', [\App\Http\Controllers\Api\EventMessageController::class, 'audience'])->middleware(['auth:sanctum', 'throttle:dashboard-read']);

Route::middleware(['auth:sanctum', 'throttle:dashboard-write'])->prefix('event-operations/{event}')->group(function () {
    Route::post('/lifecycle', [\App\Http\Controllers\Api\EventFinanceController::class, 'lifecycle']);
    Route::get('/earnings', [\App\Http\Controllers\Api\EventFinanceController::class, 'earnings']);
    Route::get('/refunds', [\App\Http\Controllers\Api\EventFinanceController::class, 'refunds']);
    Route::post('/orders/{publicId}/refunds', [\App\Http\Controllers\Api\EventFinanceController::class, 'refund']);
    Route::post('/refunds/{refund}/retry', [\App\Http\Controllers\Api\EventFinanceController::class, 'retry']);
    Route::get('/financial-audit', [\App\Http\Controllers\Api\EventFinanceController::class, 'audits']);
});
Route::middleware(['auth:sanctum', 'throttle:5,1'])->prefix('seller')->group(function () {
    Route::get('/banks', [\App\Http\Controllers\Api\PayoutAccountController::class, 'banks']);
    Route::get('/payout-accounts', [\App\Http\Controllers\Api\PayoutAccountController::class, 'index']);
    Route::post('/payout-accounts', [\App\Http\Controllers\Api\PayoutAccountController::class, 'store']);
});
