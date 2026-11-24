<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.env') !== 'testing' || ! str_starts_with((string) config('database.connections.'.config('database.default').'.database'), 'rushhour_phase01_test_')) {
    exit(3);
}
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
[$script, $action, $ownerId, $eventId, $json, $ready] = $argv;
$payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
file_put_contents($ready, 'ready');
try {
    if ($action === 'edit') {
        $event = app(App\Services\CreateEventService::class)->saveEvent($payload, (int) $ownerId, (int) $eventId);
        $result = ['status' => 200, 'version' => $event->lock_version];
    } elseif ($action === 'refund') {
        app(App\Services\Commerce\RefundService::class)->request(App\Models\Event::findOrFail($eventId), (int) $ownerId, App\Models\Order::findOrFail($payload['order_id']), $payload['item_ids'], $payload['key'], 'Buyer refund');
        $result = ['status' => 202];
    } elseif ($action === 'lifecycle') {
        app(App\Services\EventLifecycleService::class)->change(App\Models\Event::findOrFail($eventId), (int) $ownerId, $payload);
        $result = ['status' => 200];
    } elseif ($action === 'scan') {
        $result = ['status' => 200, 'data' => app(App\Services\AdmissionService::class)->move(App\Models\Event::findOrFail($eventId), (int) $ownerId, $payload)];
    } elseif ($action === 'free-register') {
        app(App\Services\Commerce\FreeRegistrationService::class)->register((int) $eventId, $payload, null);
        $result = ['status' => 201];
    } elseif ($action === 'finalize') {
        app(App\Services\Commerce\PaymentFinalizer::class)->finalizePaystack($payload['reference'], $payload);
        $result = ['status' => 200];
    } else {
        config(['services.paystack.secret_key' => 'test-key', 'services.paystack.base_url' => 'https://api.paystack.co']);
        Illuminate\Support\Facades\Http::fake(fn ($request) => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['reference' => $request['reference'], 'access_code' => 'fake', 'authorization_url' => 'https://example.com/pay']]));
        app(App\Services\Commerce\CheckoutService::class)->initialize($payload, null);
        $result = ['status' => 201];
    }
} catch (Illuminate\Validation\ValidationException $e) {
    $result = ['status' => 422, 'errors' => $e->errors()];
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    $result = ['status' => $e->getStatusCode()];
} catch (Throwable $e) {
    $result = ['status' => 500, 'exception' => get_class($e)];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
