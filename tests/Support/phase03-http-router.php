<?php

// Test-only HTTP harness. Never install this file as a deployment router.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = str_replace('\\', '/', (string) config('database.connections.sqlite.database'));
$prefix = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/rushhour-phase03-';
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_starts_with($database, $prefix)) {
    http_response_code(503);
    exit;
}
config(['services.paystack.secret_key' => 'sk_test_phase03_fake', 'services.paystack.base_url' => 'https://api.paystack.co', 'mail.default' => 'array']);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function ($request) {
    if (Illuminate\Support\Facades\DB::transactionLevel() !== 0) {
        throw new RuntimeException('Provider request inside database transaction');
    }
    if ($request->method() === 'POST' && str_ends_with($request->url(), '/transaction/initialize')) {
        return Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['reference' => $request['reference'], 'access_code' => 'fake-access', 'authorization_url' => 'https://checkout.example.invalid/test']]);
    }
    $reference = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));
    $payment = App\Models\Payment::where('reference', $reference)->firstOrFail();

    return Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['id' => 'fake-'.$payment->id, 'reference' => $reference, 'amount' => $payment->amount_minor, 'currency' => $payment->currency, 'status' => 'success', 'paid_at' => now()->toIso8601String()]]);
});
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/_phase03/drain') {
    $messages = [];
    Illuminate\Support\Facades\Mail::shouldReceive('raw')->andReturnUsing(function ($text) use (&$messages) {
        $messages[] = $text;
    });
    Illuminate\Support\Facades\DB::table('payment_reconciliations')->whereNull('finished_at')->update(['next_attempt_at' => now()]);
    Illuminate\Support\Facades\Artisan::call('commerce:dispatch');
    header('Content-Type: application/json');
    echo json_encode(['messages' => $messages], JSON_THROW_ON_ERROR);
    exit;
}
$app->handleRequest(Illuminate\Http\Request::capture());
