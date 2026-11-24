<?php

namespace Tests\Feature;

use App\Jobs\DeliverEventMessage;
use App\Models\EventDiscount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Commerce\PaymentFinalizer;
use App\Services\Dashboard\DashboardAnalyticsService;
use App\Services\EventMessagingService;
use App\Services\RecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\EventLifecycleFixtures;
use Tests\TestCase;

class PromotionMessagingTest extends TestCase
{
    use EventLifecycleFixtures, RefreshDatabase;

    private $owner;

    private $event;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_fake', 'services.paystack.base_url' => 'https://api.paystack.co', 'commerce.currency' => 'NGN', 'commerce.platform_fee_bps' => 1000, 'commerce.dynamic_splits_enabled' => false]);
        Http::fake(fn ($r) => Http::response(['status' => true, 'data' => ['reference' => $r['reference'], 'access_code' => 'fake', 'authorization_url' => 'https://example.com/pay']]));
        $this->owner = User::factory()->create();
        $this->event = $this->lifecycleEvent($this->owner, true);
    }

    private function offer(array $changes = []): EventDiscount
    {
        return EventDiscount::create([...['event_id' => $this->event->id, 'code' => 'SAVE20', 'name' => 'Early offer', 'type' => 'percent', 'value' => 20, 'currency' => 'NGN', 'usage_limit' => 2, 'active' => true], ...$changes]);
    }

    private function payload(array $changes = []): array
    {
        return [...['checkout_token' => (string) Str::uuid(), 'customer' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'buyer@example.com', 'phone' => '+2348000000000'], 'items' => [['ticket_type_id' => $this->event->ticketTypes->first()->id, 'quantity' => 1]], 'discount' => ['event_id' => $this->event->id, 'code' => 'SAVE20']], ...$changes];
    }

    private function checkout(array $payload): Order
    {
        $r = $this->postJson('/api/checkout/initialize', $payload)->assertCreated();

        return Order::where('public_id', $r->json('data.order.public_id'))->firstOrFail();
    }

    private function finalize(Order $o, string $status = 'success', ?Payment $payment = null): Order
    {
        $p = $payment ?? $o->payments()->latest('id')->firstOrFail();

        return app(PaymentFinalizer::class)->finalizePaystack($p->reference, ['id' => (string) random_int(100000, 999999999), 'reference' => $p->reference, 'status' => $status, 'amount' => $p->amount_minor, 'currency' => $p->currency]);
    }

    private function draft(string $purpose = 'event_update'): int
    {
        return $this->actingAs($this->owner)->postJson('/api/event-operations/'.$this->event->id.'/messages', ['subject' => 'Event update', 'body' => 'The doors open soon.', 'audience' => 'all_ticket_holders', 'purpose' => $purpose])->assertCreated()->json('data.id');
    }

    private function prepare(int $id): void
    {
        $this->postJson('/api/event-operations/'.$this->event->id.'/messages/'.$id.'/send', ['version' => 1])->assertAccepted();
        app(EventMessagingService::class)->build($id);
    }

    public function test_percentage_quote_and_fixed_product_eligibility_use_server_prices(): void
    {
        $o = $this->offer();
        $p = $this->payload();
        $this->postJson('/api/checkout/quote', $p)->assertOk()->assertJsonPath('data.discount_minor', 2000)->assertJsonPath('data.total_minor', 8000);
        $o->update(['type' => 'fixed', 'value' => 3000, 'ticket_type_ids' => [$p['items'][0]['ticket_type_id']]]);
        $other = $this->event->ticketTypes->first()->replicate();
        $other->name = 'Other';
        $other->save();
        $p['items'][] = ['ticket_type_id' => $other->id, 'quantity' => 1];
        $r = $this->postJson('/api/checkout/quote', $p)->assertOk()->assertJsonPath('data.discount_minor', 3000)->assertJsonPath('data.total_minor', 17000);
        $this->assertSame([3000, 0], array_column($r->json('data.items'), 'discount_minor'));
        $o->update(['ticket_type_ids' => [999999]]);
        $this->postJson('/api/checkout/quote', $p)->assertUnprocessable();
    }

    public function test_discount_owner_validation_dates_and_version_conflicts(): void
    {
        $url = '/api/event-operations/'.$this->event->id.'/discounts';
        $data = ['code' => 'NEW20', 'name' => 'New offer', 'type' => 'percent', 'value' => 20, 'usage_limit' => 2, 'active' => true, 'ticket_type_ids' => [$this->event->ticketTypes->first()->id], 'starts_at' => '2030-01-01T00:00:00+01:00', 'ends_at' => '2030-06-21T00:00:00+01:00'];
        $this->actingAs(User::factory()->create())->postJson($url, $data)->assertForbidden();
        $r = $this->actingAs($this->owner)->postJson($url, $data)->assertCreated();
        $id = $r->json('data.id');
        $this->assertSame('2029-12-31 23:00:00', EventDiscount::find($id)->starts_at->format('Y-m-d H:i:s'));
        $this->putJson($url.'/'.$id, [...$data, 'version' => 1, 'active' => false])->assertOk();
        $this->putJson($url.'/'.$id, [...$data, 'version' => 1])->assertConflict();
        $this->postJson($url, [...$data, 'code' => 'OTHER', 'value' => 101])->assertUnprocessable();
        $this->postJson($url, [...$data, 'code' => 'OTHER', 'ticket_type_ids' => [999999]])->assertUnprocessable();
    }

    public function test_pause_dates_and_limits_are_enforced_and_edits_do_not_reprice_orders(): void
    {
        $offer = $this->offer(['usage_limit' => 1]);
        $p = $this->payload();
        $order = $this->checkout($p);
        $this->postJson('/api/checkout/initialize', $this->payload())->assertUnprocessable();
        $offer->update(['value' => 80, 'active' => false]);
        $this->postJson('/api/checkout/initialize', $p)->assertCreated()->assertJsonPath('data.order.total_minor', 8000);
        $this->finalize($order);
        $this->assertSame(8000, $order->fresh()->total_minor);
        $this->assertSame(2000, $order->items()->first()->discount_minor);
        $this->assertSame(7200, (int) $order->allocations()->first()->seller_net_minor);
        $this->assertNotNull(DB::table('discount_redemptions')->where('order_id', $order->id)->value('redeemed_at'));
        $order->update(['status' => 'refunded']);
        $this->assertSame(1, app(\App\Services\Commerce\DiscountService::class)->committed($offer->id));
    }

    public function test_failed_retry_must_reacquire_offer_and_old_failure_cannot_release_new_hold(): void
    {
        $offer = $this->offer(['usage_limit' => 1]);
        $p = $this->payload();
        $order = $this->checkout($p);
        $old = $order->payments()->first();
        $this->finalize($order, 'failed');
        $this->assertSame(0, app(\App\Services\Commerce\DiscountService::class)->committed($offer->id));
        $this->postJson('/api/checkout/initialize', $p)->assertCreated();
        $this->finalize($order, 'failed', $old);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame(1, app(\App\Services\Commerce\DiscountService::class)->committed($offer->id));
        $this->finalize($order, 'failed');
        $this->checkout($this->payload());
        $this->postJson('/api/checkout/initialize', $p)->assertUnprocessable();
    }

    public function test_late_charge_after_offer_is_consumed_is_reviewed_without_tickets(): void
    {
        $this->offer(['usage_limit' => 1]);
        $a = $this->checkout($this->payload());
        $a->update(['expires_at' => now()->subSecond()]);
        DB::table('discount_redemptions')->where('order_id', $a->id)->update(['held_until' => now()->subSecond()]);
        $b = $this->checkout($this->payload());
        $this->finalize($b);
        $this->finalize($a);
        $this->assertSame('payment_review', $a->fresh()->status);
        $this->assertDatabaseHas('payment_resolutions', ['reason' => 'discount_limit_charge']);
        $this->assertSame(0, $a->items()->first()->tickets()->count());
    }

    public function test_cancel_releases_hold_and_subsequent_charge_is_reviewed(): void
    {
        $offer = $this->offer(['usage_limit' => 1]);
        $p = $this->payload();
        $o = $this->checkout($p);
        $this->postJson('/api/checkout/orders/'.$o->public_id.'/cancel')->assertForbidden();
        $this->withHeader('X-Checkout-Token', $p['checkout_token'])->postJson('/api/checkout/orders/'.$o->public_id.'/cancel')->assertOk();
        $this->assertSame(0, app(\App\Services\Commerce\DiscountService::class)->committed($offer->id));
        $this->finalize($o);
        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertDatabaseHas('payment_resolutions', ['reason' => 'terminal_order_charge']);
    }

    public function test_full_discount_issues_idempotent_credentials_without_payment(): void
    {
        $this->offer(['value' => 100]);
        $p = $this->payload();
        $o = $this->checkout($p);
        Http::assertNothingSent();
        $this->assertSame('registered', $o->status);
        $this->assertSame(0, $o->payments()->count());
        $this->assertSame(4, $o->attendees()->count());
        $this->postJson('/api/checkout/initialize', $p)->assertCreated()->assertJsonCount(1, 'data.order.items.0.tickets');
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('commerce_deliveries', 1);
    }

    public function test_invalid_scheduled_and_expired_offers_are_rejected(): void
    {
        $o = $this->offer(['starts_at' => now()->addDay()]);
        $this->postJson('/api/checkout/quote', $this->payload())->assertUnprocessable();
        $o->update(['starts_at' => null, 'ends_at' => now()->subSecond()]);
        $this->postJson('/api/checkout/initialize', $this->payload())->assertUnprocessable();
        $o->update(['ends_at' => null, 'active' => false]);
        $this->postJson('/api/checkout/quote', $this->payload())->assertUnprocessable();
        $o->update(['active' => true]);
        $this->postJson('/api/checkout/quote', $this->payload())->assertOk();
    }

    public function test_metrics_use_snapshots_partition_currencies_and_count_actual_people(): void
    {
        $this->offer();
        $o = $this->checkout($this->payload());
        $this->finalize($o);
        $this->event->ticketTypes()->update(['people_per_unit' => 99]);
        $o->attendees()->first()->update(['entries' => 2, 'inside' => true]);
        $m = app(DashboardAnalyticsService::class)->overview($this->event->fresh());
        $this->assertSame(4, $m['sales']['admission_entries_covered']);
        $this->assertSame(1, $m['metrics']['actual_attendance']);
        $this->assertSame(10000, $m['metrics']['by_currency'][0]['gross_minor']);
        $this->assertSame(2000, $m['metrics']['by_currency'][0]['discounts_minor']);
        $this->assertSame(7200, $m['metrics']['by_currency'][0]['seller_net_minor']);
        $this->assertSame(0, $m['metrics']['by_currency'][0]['refunds_minor']);
        $o->items()->update(['currency' => 'USD']);
        $o->update(['currency' => 'USD']);
        $m = app(DashboardAnalyticsService::class)->overview($this->event->fresh());
        $this->assertSame('USD', $m['metrics']['by_currency'][0]['currency']);
        $this->assertSame(0, $m['revenue']['total_minor']);
    }

    public function test_message_drafts_and_audiences_are_owner_only(): void
    {
        $id = $this->draft();
        $url = '/api/event-operations/'.$this->event->id.'/messages';
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $this->postJson($url.'/'.$id.'/send', ['version' => 1])->assertForbidden();
        $this->actingAs($this->owner)->putJson($url.'/'.$id, ['version' => 9, 'subject' => 'Changed', 'body' => 'Body', 'audience' => 'all_ticket_holders', 'purpose' => 'event_update'])->assertConflict();
        $this->postJson($url.'/audience', ['purpose' => 'event_update', 'audience' => 'ticket_types', 'ticket_type_ids' => [999999]])->assertUnprocessable();
    }

    public function test_message_audience_deduplicates_tables_and_jobs_do_not_resend_success(): void
    {
        config(['mail.default' => 'array']);
        Mail::purge('array');
        $this->offer(['value' => 100]);
        $this->checkout($this->payload());
        $id = $this->draft();
        $this->prepare($id);
        $this->assertDatabaseCount('event_message_deliveries', 1);
        $delivery = DB::table('event_message_deliveries')->first();
        $job = new DeliverEventMessage($delivery->id);
        $job->handle(app(EventMessagingService::class));
        $job->handle(app(EventMessagingService::class));
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('buyer@example.com', $sent->first()->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertStringContainsString('event-message-'.$delivery->id, $sent->first()->getOriginalMessage()->getHeaders()->get('Message-ID')->getBodyAsString());
        $this->assertDatabaseHas('event_message_deliveries', ['id' => $delivery->id, 'status' => 'sent', 'attempts' => 1]);
        $this->assertDatabaseHas('event_messages', ['id' => $id, 'status' => 'completed']);
        $this->postJson('/api/event-operations/'.$this->event->id.'/messages/'.$id.'/send', ['version' => 1])->assertAccepted();
        $this->assertDatabaseCount('event_message_deliveries', 1);
    }

    public function test_marketing_requires_mailbox_verified_consent_and_unsubscribe_suppresses_delivery(): void
    {
        $this->offer(['value' => 100]);
        $p = $this->payload();
        $o = $this->checkout($p);
        $url = '/api/checkout/orders/'.$o->public_id.'/message-preferences';
        $this->withHeader('X-Checkout-Token', $p['checkout_token'])->putJson($url, ['event_id' => $this->event->id, 'marketing_consent' => true])->assertForbidden();
        $token = Str::random(64);
        DB::table('order_recoveries')->insert(['order_id' => $o->id, 'token_hash' => hash('sha256', Str::random(64)), 'access_hash' => hash('sha256', $token), 'exchanged_at' => now(), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeader('X-Order-Recovery-Token', $token)->putJson($url, ['event_id' => $this->event->id, 'marketing_consent' => true])->assertOk();
        $id = $this->draft('marketing');
        $this->prepare($id);
        $delivery = DB::table('event_message_deliveries')->first();
        $signed = URL::signedRoute('event-message-unsubscribe', ['delivery' => $delivery->id]);
        $this->get($signed)->assertOk();
        $this->assertNull(DB::table('event_message_preferences')->first()->unsubscribed_at);
        $this->post($signed)->assertOk();
        Mail::fake();
        (new DeliverEventMessage($delivery->id))->handle(app(EventMessagingService::class));
        $this->assertDatabaseHas('event_message_deliveries', ['id' => $delivery->id, 'status' => 'suppressed']);
        $this->post('/api/event-message-preferences/'.$delivery->id.'/unsubscribe')->assertForbidden();
    }

    public function test_marketing_without_opt_in_has_empty_audience(): void
    {
        $this->offer(['value' => 100]);
        $this->checkout($this->payload());
        $id = $this->draft('marketing');
        $this->prepare($id);
        $this->assertDatabaseCount('event_message_deliveries', 0);
        app(EventMessagingService::class)->complete($id);
        $this->assertDatabaseHas('event_messages', ['id' => $id, 'status' => 'completed']);
    }

    public function test_failed_delivery_is_durable_and_retry_rechecks_permissions(): void
    {
        $this->offer(['value' => 100]);
        $this->checkout($this->payload());
        $id = $this->draft();
        $this->prepare($id);
        $d = DB::table('event_message_deliveries')->first();
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('provider unavailable'));
        (new DeliverEventMessage($d->id))->handle(app(EventMessagingService::class));
        $this->assertDatabaseHas('event_message_deliveries', ['id' => $d->id, 'status' => 'pending', 'attempts' => 1]);
        DB::table('event_message_deliveries')->where('id', $d->id)->update(['next_attempt_at' => now()]);
        DB::table('event_message_preferences')->insert(['event_id' => $this->event->id, 'email' => $d->email, 'marketing_consent' => false, 'unsubscribed_at' => now()]);
        (new DeliverEventMessage($d->id))->handle(app(EventMessagingService::class));
        $this->assertDatabaseHas('event_message_deliveries', ['id' => $d->id, 'status' => 'suppressed', 'attempts' => 2]);
    }

    public function test_recommendations_use_current_traffic_commerce_and_currency(): void
    {
        $user = User::factory()->create();
        DB::table('event_traffic_views')->insert(['event_id' => $this->event->id, 'user_id' => $user->id, 'visitor_hash' => hash('sha256', 'visitor'), 'view_bucket' => now()->startOfHour(), 'viewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $engine = new RecommendationEngine($user);
        $r = $engine->getRecommendations();
        $this->assertSame($this->event->id, $r['jump_back_in']['events'][0]['id']);
        $this->assertSame('NGN 100.00', $r['jump_back_in']['events'][0]['formatted_price']);
        $this->offer(['value' => 100]);
        $this->actingAs($user);
        $this->checkout($this->payload());
        $engine->clearCache();
        $r = $engine->getRecommendations();
        $this->assertSame(0, $r['jump_back_in']['count']);
    }

    public function test_exhausted_message_delivery_can_be_retried_without_resending_other_recipients(): void
    {
        $this->offer(['value' => 100]);
        $this->checkout($this->payload());
        $id = $this->draft();
        $this->prepare($id);
        $d = DB::table('event_message_deliveries')->first();
        Mail::shouldReceive('raw')->times(5)->andThrow(new \RuntimeException('unavailable'));
        for ($i = 0; $i < 5; $i++) {
            DB::table('event_message_deliveries')->where('id', $d->id)->update(['next_attempt_at' => now()]);
            (new DeliverEventMessage($d->id))->handle(app(EventMessagingService::class));
        }
        $this->assertDatabaseHas('event_messages', ['id' => $id, 'status' => 'failed']);
        $this->postJson('/api/event-operations/'.$this->event->id.'/messages/'.$id.'/retry')->assertAccepted();
        Mail::fake();
        (new DeliverEventMessage($d->id))->handle(app(EventMessagingService::class));
        $this->assertDatabaseHas('event_message_deliveries', ['id' => $d->id, 'status' => 'sent', 'attempts' => 1]);
    }

    public function test_dashboard_lists_are_paginated_and_chart_uses_local_dst_days(): void
    {
        $this->event->forceFill(['event_timezone' => 'America/New_York'])->save();
        $o = $this->lifecycleOrder($this->event, Order::STATUS_PAID, 1);
        $o->update(['paid_at' => '2026-11-01 03:30:00']);
        $m = app(DashboardAnalyticsService::class)->overview($this->event->fresh());
        $day = collect($m['sales_trend']['30d'])->firstWhere('date', '2026-10-31');
        $this->assertSame(1, $day['units_sold']);
        $this->actingAs($this->owner)->getJson('/api/dashboard/events?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('per_page', 1);
        $this->getJson('/api/dashboard/events?per_page=1000')->assertUnprocessable();
    }

    public function test_analytics_queries_are_sql_aggregates_and_record_measured_plans(): void
    {
        $o = $this->lifecycleOrder($this->event, Order::STATUS_PAID, 1);
        $template = $o->getAttributes();
        unset($template['id']);
        $orders = [];
        for ($i = 0; $i < 500; $i++) {
            $orders[] = [...$template, 'public_id' => (string) Str::ulid(), 'checkout_token_hash' => hash('sha256', Str::uuid())];
        }
        foreach (array_chunk($orders, 100) as $batch) {
            DB::table('orders')->insert($batch);
        }
        $item = $o->items()->first()->getAttributes();
        unset($item['id']);
        $lines = DB::table('orders')->where('id', '!=', $o->id)->pluck('id')->map(fn ($id) => [...$item, 'order_id' => $id])->all();
        foreach (array_chunk($lines, 100) as $batch) {
            DB::table('order_items')->insert($batch);
        }
        $started = microtime(true);
        DB::enableQueryLog();
        $m = app(DashboardAnalyticsService::class)->overview($this->event->fresh());
        $elapsed = round((microtime(true) - $started) * 1000, 2);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(501, $m['metrics']['by_currency'][0]['orders']);
        $this->assertLessThanOrEqual(1095, count($m['sales_trend']['all']));
        $this->assertFalse(collect($queries)->contains(fn ($q) => str_contains(strtolower($q['query']), 'select * from `order_items`')));
        if (DB::getDriverName() === 'mysql') {
            $plans = [];
            foreach ([
                'commerce' => ['SELECT i.currency,SUM(i.quantity) FROM order_items i JOIN orders o ON o.id=i.order_id WHERE i.event_id=? GROUP BY i.currency', [$this->event->id]],
                'traffic' => ['SELECT COUNT(DISTINCT visitor_hash) FROM event_traffic_views WHERE event_id=?', [$this->event->id]],
                'quota' => ['SELECT id FROM discount_redemptions WHERE discount_id=? AND (redeemed_at IS NOT NULL OR held_until > NOW())', [1]],
                'audience' => ['SELECT id FROM attendees WHERE event_id=? AND id>? ORDER BY id LIMIT 200', [$this->event->id, 0]],
            ] as $name => [$sql,$bindings]) {
                $plans[$name] = DB::select('EXPLAIN '.$sql, $bindings);
            }
            if (! is_dir(storage_path('framework/testing'))) {
                mkdir(storage_path('framework/testing'), 0777, true);
            }
            file_put_contents(storage_path('framework/testing/phase05-query-plans.json'), json_encode(['fixture_orders' => 501, 'overview_ms' => $elapsed, 'query_count' => count($queries), 'plans' => $plans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    public function test_forward_migration_preserves_existing_discount_fees_and_ticket_credentials(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.phase05_legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('phase05_legacy');
        try {
            $migration = 'database/migrations/2026_11_24_000004_add_promotion_and_messaging.php';
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), basename($migration)) < 0));
            $this->artisan('migrate', ['--database' => 'phase05_legacy', '--path' => $paths, '--realpath' => true, '--force' => true])->assertExitCode(0);
            $owner = User::factory()->create();
            $organizer = \App\Models\Organizer::create(['name' => 'Historical organiser']);
            $address = \App\Models\EventAddress::create(['venue_name' => 'Hall', 'address_line1' => '', 'city' => 'Lagos', 'state' => 'Lagos', 'postal_code' => '', 'country' => 'Nigeria']);
            $event = \App\Models\Event::forceCreate(['title' => 'Historical event', 'description' => 'Description', 'organizer_id' => $organizer->id, 'address_id' => $address->id, 'created_by' => $owner->id, 'status' => 'active', 'ticket_mode' => 'paid', 'date' => now()->addDay(), 'ends_at' => now()->addDays(2), 'price' => 100, 'total_tickets' => 10]);
            \App\Models\TicketType::create(['event_id' => $event->id, 'name' => 'Original table', 'unit_type' => 'table', 'price' => '100.00', 'quantity' => 10, 'people_per_unit' => 4]);
            $o = $this->lifecycleOrder($event, Order::STATUS_PAID, 1);
            $o->update(['discount_minor' => 1000, 'total_minor' => 9000]);
            $item = $o->items()->first();
            \App\Models\OrderAllocation::create(['order_id' => $o->id, 'seller_user_id' => $owner->id, 'gross_minor' => 9000, 'platform_fee_minor' => 900, 'seller_net_minor' => 8100, 'currency' => 'NGN', 'settlement_mode' => 'platform_held', 'settlement_status' => 'pending']);
            $ticket = \App\Models\Ticket::create(['public_id' => (string) Str::ulid(), 'order_item_id' => $item->id, 'event_id' => $event->id, 'ticket_type_id' => $item->ticket_type_id, 'unit_index' => 0, 'token' => 'historical-private-token', 'token_hash' => hash('sha256', 'historical-private-token'), 'admit_count' => 4, 'status' => 'valid', 'issued_at' => now()]);
            $before = $ticket->fresh()->getAttributes();
            $totals = $o->fresh()->getAttributes();
            $this->artisan('migrate', ['--database' => 'phase05_legacy', '--path' => $migration, '--force' => true])->assertExitCode(0);
            $this->assertSame(1000, (int) DB::table('order_items')->where('id', $item->id)->value('discount_minor'));
            $this->assertSame(900, (int) DB::table('order_items')->where('id', $item->id)->value('platform_fee_minor'));
            $after = $ticket->fresh()->getAttributes();
            ksort($before);
            ksort($after);
            $this->assertSame($before, $after);
            $afterOrder = $o->fresh()->getAttributes();
            ksort($totals);
            ksort($afterOrder);
            $this->assertSame($totals, $afterOrder);
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('phase05_legacy');
        }
    }
}
