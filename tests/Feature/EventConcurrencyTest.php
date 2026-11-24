<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CreateEventService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EventLifecycleFixtures;
use Tests\TestCase;

class EventConcurrencyTest extends TestCase
{
    use EventLifecycleFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real row-lock tests run on the isolated MySQL/MariaDB database.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertStringStartsWith('rushhour_phase01_test_', (string) DB::connection()->getDatabaseName());
        // This is the newly generated, isolated database; never a configured application DB.
        // Avoid legacy down() migrations, which have an existing MariaDB FK-index rollback defect.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function worker(string $action, int $owner, int $event, array $payload): array
    {
        $prefix = tempnam(sys_get_temp_dir(), 'rushhour-concurrency-');
        unlink($prefix);
        $output = $prefix.'.json';
        $ready = $prefix.'.ready';
        $process = proc_open([PHP_BINARY, base_path('tests/Support/event-editor-worker.php'), $action, (string) $owner, (string) $event, json_encode($payload, JSON_THROW_ON_ERROR), $ready], [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output.'.error', 'w']], $pipes, base_path(), getenv());
        $this->assertIsResource($process);
        fclose($pipes[0]);

        return [$process, $output, $ready];
    }

    private function waitUntilReady(array $worker): void
    {
        $deadline = microtime(true) + 12;
        while (! is_file($worker[2]) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($worker[2]);
        $this->assertTrue(proc_get_status($worker[0])['running'], 'Worker should be waiting on the held database lock.');
    }

    private function workerResult(array $worker): array
    {
        proc_close($worker[0]);
        try {
            return json_decode(file_get_contents($worker[1]), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            foreach ([$worker[1], $worker[2], $worker[1].'.error'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_two_simultaneous_editors_with_one_version_cannot_overwrite_each_other(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner);
        $id = $event->ticketTypes->first()->id;
        DB::beginTransaction();
        try {
            DB::table('events')->where('id', $event->id)->lockForUpdate()->first();
            $first = $this->worker('edit', $owner->id, $event->id, ['version' => 1, 'tickets' => [['id' => $id, 'units' => 20]]]);
            $second = $this->worker('edit', $owner->id, $event->id, ['version' => 1, 'tickets' => [['id' => $id, 'units' => 30]]]);
            $this->waitUntilReady($first);
            $this->waitUntilReady($second);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $statuses = [$this->workerResult($first)['status'], $this->workerResult($second)['status']];
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(2, $event->fresh()->lock_version);
        $this->assertContains($event->ticketTypes()->first()->quantity, [20, 30]);
    }

    public function test_editor_waits_for_checkout_commit_and_rejects_capacity_below_the_new_reservation(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $id = $event->ticketTypes->first()->id;
        DB::beginTransaction();
        try {
            DB::table('ticket_types')->where('id', $id)->lockForUpdate()->first();
            $worker = $this->worker('edit', $owner->id, $event->id, ['version' => 2, 'tickets' => [['id' => $id, 'units' => 0]]]);
            $this->waitUntilReady($worker);
            $this->lifecycleOrder($event, units: 3);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $result = $this->workerResult($worker);
        $this->assertSame(422, $result['status']);
        $this->assertArrayHasKey('tickets.0.units', $result['errors']);
        $this->assertSame(10, $event->ticketTypes()->first()->quantity);
        $this->assertSame(2, $event->fresh()->lock_version);
    }

    public function test_checkout_waits_for_inventory_edit_and_reads_its_committed_capacity(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $id = $event->ticketTypes->first()->id;
        DB::beginTransaction();
        try {
            app(CreateEventService::class)->saveEvent(['version' => 2, 'tickets' => [['id' => $id, 'units' => 0]]], $owner->id, $event->id);
            $worker = $this->worker('checkout', $owner->id, $event->id, [
                'checkout_token' => (string) Str::uuid(), 'customer' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'concurrent@example.com', 'phone' => '+2348000000000'],
                'items' => [['ticket_type_id' => $id, 'quantity' => 1]],
            ]);
            $this->waitUntilReady($worker);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $this->assertSame(422, $this->workerResult($worker)['status']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $event->ticketTypes()->first()->quantity);
    }

    public function test_simultaneous_purchases_serialize_on_the_last_unit(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $type = $event->ticketTypes()->first();
        $type->update(['quantity' => 1]);
        $payload = ['checkout_token' => (string) Str::uuid(), 'customer' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'buyer@example.com', 'phone' => '08000000000'], 'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]]];
        DB::beginTransaction();
        try {
            DB::table('ticket_types')->where('id', $type->id)->lockForUpdate()->first();
            $first = $this->worker('checkout', $owner->id, $event->id, $payload);
            $payload['checkout_token'] = (string) Str::uuid();
            $second = $this->worker('checkout', $owner->id, $event->id, $payload);
            $this->waitUntilReady($first);
            $this->waitUntilReady($second);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $statuses = [$this->workerResult($first)['status'], $this->workerResult($second)['status']];
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_simultaneous_finalizers_issue_only_one_ticket(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $order = $this->lifecycleOrder($event, units: 1);
        $payment = \App\Models\Payment::create(['order_id' => $order->id, 'provider' => 'paystack', 'reference' => 'KT-'.Str::ulid(), 'amount_minor' => $order->total_minor, 'currency' => 'NGN', 'status' => 'initialized']);
        $payload = ['id' => 71199, 'reference' => $payment->reference, 'amount' => $payment->amount_minor, 'currency' => 'NGN', 'status' => 'success'];
        DB::beginTransaction();
        try {
            DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();
            $first = $this->worker('finalize', $owner->id, $event->id, $payload);
            $second = $this->worker('finalize', $owner->id, $event->id, $payload);
            $this->waitUntilReady($first);
            $this->waitUntilReady($second);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $this->assertSame(200, $this->workerResult($first)['status']);
        $this->assertSame(200, $this->workerResult($second)['status']);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('commerce_deliveries', 1);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_failed_retry_and_new_purchase_compete_for_the_same_released_unit(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $type = $event->ticketTypes()->first();
        $type->update(['quantity' => 1]);
        $order = $this->lifecycleOrder($event, status: 'payment_failed', units: 1);
        $customer = ['first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'retry@example.com', 'phone' => '08000000000'];
        $items = [['ticket_type_id' => $type->id, 'quantity' => 1]];
        $token = (string) Str::uuid();
        $order->update(['checkout_token_hash' => hash('sha256', $token), 'cart_fingerprint' => hash('sha256', json_encode(['customer' => $customer, 'items' => $items], JSON_THROW_ON_ERROR))]);
        \App\Models\Payment::create(['order_id' => $order->id, 'provider' => 'paystack', 'reference' => 'KT-'.Str::ulid(), 'amount_minor' => $order->total_minor, 'currency' => 'NGN', 'status' => 'failed']);
        $payload = ['checkout_token' => $token, 'customer' => $customer, 'items' => $items];
        DB::beginTransaction();
        try {
            DB::table('ticket_types')->where('id', $type->id)->lockForUpdate()->first();
            $retry = $this->worker('checkout', $owner->id, $event->id, $payload);
            $payload['checkout_token'] = (string) Str::uuid();
            $purchase = $this->worker('checkout', $owner->id, $event->id, $payload);
            $this->waitUntilReady($retry);
            $this->waitUntilReady($purchase);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $statuses = [$this->workerResult($retry)['status'], $this->workerResult($purchase)['status']];
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $this->assertSame(1, app(\App\Services\Commerce\InventoryService::class)->reservedQuantities([$type->id])[$type->id]);
    }

    public function test_simultaneous_partial_scans_cannot_exceed_table_entitlement(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $order = $this->lifecycleOrder($event, status: 'paid', units: 1);
        $item = $order->items()->first();
        $token = Str::random(48);
        $ticket = \App\Models\Ticket::create(['public_id' => (string) Str::ulid(), 'order_item_id' => $item->id, 'event_id' => $event->id, 'ticket_type_id' => $item->ticket_type_id,
            'token' => $token, 'token_hash' => hash('sha256', $token), 'admit_count' => 4, 'status' => 'valid', 'issued_at' => now()]);
        app(\App\Services\Commerce\RegistrationService::class)->link($ticket, 0);
        $entrance = DB::table('event_entrances')->insertGetId(['event_id' => $event->id, 'name' => 'Main', 'active' => true, 'opens_at' => '2020-01-01', 'closes_at' => '2040-01-01']);
        $payload = ['token' => $token, 'entrance_id' => $entrance, 'direction' => 'in', 'count' => 3, 'request_key' => (string) Str::uuid()];
        DB::beginTransaction();
        try {
            DB::table('tickets')->where('id', $ticket->id)->lockForUpdate()->first();
            $first = $this->worker('scan', $owner->id, $event->id, $payload);
            $payload['request_key'] = (string) Str::uuid();
            $second = $this->worker('scan', $owner->id, $event->id, $payload);
            $this->waitUntilReady($first);
            $this->waitUntilReady($second);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $results = [$this->workerResult($first), $this->workerResult($second)];
        $this->assertSame([200, 200], array_column($results, 'status'));
        $outcomes = array_column(array_column($results, 'data'), 'outcome');
        sort($outcomes);
        $this->assertSame(['denied', 'recorded'], $outcomes);
        $this->assertSame(3, $ticket->attendees()->where('inside', true)->count());
        $this->assertDatabaseCount('admission_movements', 1);
    }

    public function test_simultaneous_free_registrations_cannot_take_the_last_place_twice(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true, false);
        $event->forceFill(['total_tickets' => 1])->save();
        $payload = ['checkout_token' => (string) Str::uuid(), 'quantity' => 1, 'customer' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'free@example.com']];
        DB::beginTransaction();
        try {
            DB::table('events')->where('id', $event->id)->lockForUpdate()->first();
            $first = $this->worker('free-register', $owner->id, $event->id, $payload);
            $payload['checkout_token'] = (string) Str::uuid();
            $second = $this->worker('free-register', $owner->id, $event->id, $payload);
            $this->waitUntilReady($first);
            $this->waitUntilReady($second);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $statuses = [$this->workerResult($first)['status'], $this->workerResult($second)['status']];
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_simultaneous_checkouts_for_different_products_share_one_discount_quota(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $type = $event->ticketTypes->first();
        $other = $type->replicate();
        $other->name = 'Other table';
        $other->save();
        $offer = \App\Models\EventDiscount::create(['event_id' => $event->id, 'code' => 'LAST20', 'name' => 'Last offer', 'type' => 'percent', 'value' => 20, 'currency' => 'NGN', 'usage_limit' => 1, 'active' => true]);
        $payload = fn ($id) => ['checkout_token' => (string) Str::uuid(), 'customer' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'buyer@example.com', 'phone' => '123'], 'items' => [['ticket_type_id' => $id, 'quantity' => 1]], 'discount' => ['event_id' => $event->id, 'code' => 'LAST20']];
        DB::beginTransaction();
        try {
            DB::table('event_discounts')->where('id', $offer->id)->lockForUpdate()->first();
            $a = $this->worker('checkout', $owner->id, $event->id, $payload($type->id));
            $b = $this->worker('checkout', $owner->id, $event->id, $payload($other->id));
            $this->waitUntilReady($a);
            $this->waitUntilReady($b);
            usleep(200000);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $statuses = [$this->workerResult($a)['status'], $this->workerResult($b)['status']];
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $this->assertDatabaseCount('discount_redemptions', 1);
    }

    public function test_failed_retry_and_new_checkout_cannot_both_reacquire_last_offer(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $id = $event->ticketTypes->first()->id;
        $offer = \App\Models\EventDiscount::create(['event_id' => $event->id, 'code' => 'LAST20', 'name' => 'Last offer', 'type' => 'percent', 'value' => 20, 'currency' => 'NGN', 'usage_limit' => 1, 'active' => true]);
        $payload = ['checkout_token' => (string) Str::uuid(), 'customer' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'buyer@example.com', 'phone' => '123'], 'items' => [['ticket_type_id' => $id, 'quantity' => 1]], 'discount' => ['event_id' => $event->id, 'code' => 'LAST20']];
        // First use the same provider-faked worker to create a committed, subsequently failed order.
        $w = $this->worker('checkout', $owner->id, $event->id, $payload);
        $this->assertSame(201, $this->workerResult($w)['status']);
        $o = \App\Models\Order::firstOrFail();
        $p = $o->payments()->firstOrFail();
        app(\App\Services\Commerce\PaymentFinalizer::class)->finalizePaystack($p->reference, ['reference' => $p->reference, 'amount' => $p->amount_minor, 'currency' => $p->currency, 'status' => 'failed']);
        DB::beginTransaction();
        try {
            DB::table('event_discounts')->where('id', $offer->id)->lockForUpdate()->first();
            $a = $this->worker('checkout', $owner->id, $event->id, $payload);
            $b = $this->worker('checkout', $owner->id, $event->id, [...$payload, 'checkout_token' => (string) Str::uuid()]);
            $this->waitUntilReady($a);
            $this->waitUntilReady($b);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $statuses = [$this->workerResult($a)['status'], $this->workerResult($b)['status']];
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $this->assertSame(1, app(\App\Services\Commerce\DiscountService::class)->committed($offer->id));
    }

    public function test_simultaneous_refund_requests_cannot_refund_the_same_line_twice(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $o = $this->lifecycleOrder($event, units: 1);
        $p = \App\Models\Payment::create(['order_id' => $o->id, 'provider' => 'paystack', 'reference' => 'KT-'.Str::ulid(), 'amount_minor' => $o->total_minor, 'currency' => 'NGN', 'status' => 'initialized']);
        app(\App\Services\Commerce\PaymentFinalizer::class)->finalizePaystack($p->reference, ['id' => '345678', 'reference' => $p->reference, 'amount' => $p->amount_minor, 'currency' => 'NGN', 'status' => 'success']);
        $payload = ['order_id' => $o->id, 'item_ids' => $o->items()->pluck('id')->all(), 'key' => hash('sha256', Str::uuid())];
        DB::beginTransaction();
        try {
            DB::table('orders')->where('id', $o->id)->lockForUpdate()->first();
            $a = $this->worker('refund', $owner->id, $event->id, $payload);
            $b = $this->worker('refund', $owner->id, $event->id, [...$payload, 'key' => hash('sha256', Str::uuid())]);
            $this->waitUntilReady($a);
            $this->waitUntilReady($b);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $statuses = [$this->workerResult($a)['status'], $this->workerResult($b)['status']];
        sort($statuses);
        $this->assertSame([202, 409], $statuses);
        $this->assertDatabaseCount('commerce_refunds', 1);
    }

    public function test_checkout_waits_for_sales_closure_and_cannot_book_after_it(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $id = $event->ticketTypes->first()->id;
        DB::beginTransaction();
        try {
            app(\App\Services\EventLifecycleService::class)->change($event, $owner->id, ['version' => 2, 'action' => 'close_sales']);
            $w = $this->worker('checkout', $owner->id, $event->id, ['checkout_token' => (string) Str::uuid(), 'customer' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'buyer@example.com', 'phone' => '123'], 'items' => [['ticket_type_id' => $id, 'quantity' => 1]]]);
            $this->waitUntilReady($w);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $this->assertSame(422, $this->workerResult($w)['status']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cancellation_serializes_with_an_inflight_admission_scan(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $o = $this->lifecycleOrder($event, 'paid', 1);
        $i = $o->items()->first();
        $token = Str::random(48);
        $ticket = \App\Models\Ticket::create(['public_id' => (string) Str::ulid(), 'order_item_id' => $i->id, 'event_id' => $event->id, 'ticket_type_id' => $i->ticket_type_id, 'token' => $token, 'token_hash' => hash('sha256', $token), 'admit_count' => 4, 'status' => 'valid', 'issued_at' => now()]);
        app(\App\Services\Commerce\RegistrationService::class)->link($ticket, 0);
        $gate = DB::table('event_entrances')->insertGetId(['event_id' => $event->id, 'name' => 'Main', 'active' => true, 'opens_at' => '2020-01-01', 'closes_at' => '2040-01-01']);
        DB::beginTransaction();
        try {
            app(\App\Services\EventLifecycleService::class)->change($event, $owner->id, ['version' => 2, 'action' => 'cancel', 'reason' => 'Venue unavailable']);
            $w = $this->worker('scan', $owner->id, $event->id, ['token' => $token, 'entrance_id' => $gate, 'direction' => 'in', 'count' => 1, 'request_key' => (string) Str::uuid()]);
            $this->waitUntilReady($w);
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $r = $this->workerResult($w);
        $this->assertSame(200, $r['status']);
        $this->assertSame('denied', $r['data']['outcome']);
        $this->assertDatabaseCount('admission_movements', 0);
    }
}
