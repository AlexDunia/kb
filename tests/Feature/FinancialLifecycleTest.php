<?php

namespace Tests\Feature;

use App\Jobs\OnboardPayoutAccount;
use App\Jobs\ProcessEventCancellation;
use App\Jobs\ProcessRefund;
use App\Jobs\ReconcileSettlement;
use App\Jobs\ReconcileSettlementFeed;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\PaymentFinalizer;
use App\Services\Commerce\PaystackService;
use App\Services\Commerce\RefundService;
use App\Services\EventLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\EventLifecycleFixtures;
use Tests\TestCase;

class FinancialLifecycleTest extends TestCase
{
    use EventLifecycleFixtures,RefreshDatabase;

    private $owner;

    private $event;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_fake', 'services.paystack.base_url' => 'https://api.paystack.co', 'commerce.currency' => 'NGN', 'commerce.platform_fee_bps' => 1000, 'commerce.dynamic_splits_enabled' => false]);
        $this->owner = User::factory()->create();
        $this->event = $this->lifecycleEvent($this->owner, true);
        $this->fakeProvider(fn ($r) => Http::response(['status' => true, 'data' => ['reference' => $r['reference'], 'access_code' => 'fake', 'authorization_url' => 'https://example.com/pay']]));
    }

    private function fakeProvider(callable $callback): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function payload(array $extra = []): array
    {
        return [...['checkout_token' => (string) Str::uuid(), 'customer' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'buyer@example.com', 'phone' => '123'], 'items' => [['ticket_type_id' => $this->event->ticketTypes->first()->id, 'quantity' => 1]]], ...$extra];
    }

    private function paid(array $extra = []): Order
    {
        $data = app(CheckoutService::class)->initialize($this->payload($extra), null);
        $o = Order::where('public_id', $data['order']['public_id'])->firstOrFail();
        $p = $o->payments()->first();

        return app(PaymentFinalizer::class)->finalizePaystack($p->reference, ['reference' => $p->reference, 'amount' => $p->amount_minor, 'currency' => 'NGN', 'status' => 'success', 'id' => (string) random_int(100000, 999999999), 'fees' => 500]);
    }

    private function refund(Order $o, ?array $ids = null): object
    {
        $ids ??= $o->items()->where('event_id', $this->event->id)->pluck('id')->all();
        $r = $this->actingAs($this->owner)->postJson('/api/event-operations/'.$this->event->id.'/orders/'.$o->public_id.'/refunds', ['request_key' => (string) Str::uuid(), 'order_item_ids' => $ids, 'reason' => 'Buyer requested refund'])->assertAccepted();

        return DB::table('commerce_refunds')->where('public_id', $r->json('data.public_id'))->first();
    }

    private function providerRefund(object $refund, string $status = 'processed', array $override = []): void
    {
        $p = Payment::find($refund->payment_id);
        $data = [...['id' => 777, 'reference' => $refund->reference, 'transaction' => $p->provider_transaction_id, 'amount' => (int) $refund->amount_minor, 'currency' => 'NGN', 'status' => $status], ...$override];
        $this->fakeProvider(fn ($r) => Http::response(['status' => true, 'data' => str_contains($r->url(), '/refund?') ? [$data] : $data]));
    }

    private function process(object $r): void
    {
        (new ProcessRefund($r->id))->handle(app(PaystackService::class), app(RefundService::class));
    }

    public function test_refund_is_owner_authorized_idempotent_and_preserves_original_finances(): void
    {
        $o = $this->paid();
        $url = '/api/event-operations/'.$this->event->id.'/orders/'.$o->public_id.'/refunds';
        $input = ['request_key' => (string) Str::uuid(), 'order_item_ids' => $o->items()->pluck('id')->all(), 'reason' => 'Buyer requested refund'];
        $this->actingAs(User::factory()->create())->postJson($url, $input)->assertForbidden();
        $this->actingAs($this->owner)->postJson($url, $input)->assertAccepted();
        $this->postJson($url, $input)->assertAccepted();
        $this->assertDatabaseCount('commerce_refunds', 1);
        $r = DB::table('commerce_refunds')->first();
        $this->providerRefund($r);
        $this->process($r);
        $this->process($r);
        $this->assertSame('processed', DB::table('commerce_refunds')->find($r->id)->status, DB::table('commerce_refunds')->find($r->id)->last_error ?? '');
        $this->assertSame('refunded', $o->fresh()->status);
        $this->assertSame(10000, $o->fresh()->total_minor);
        $this->assertSame('success', $o->payments()->first()->status);
        $this->assertSame(10000, (int) $o->payments()->first()->refunded_minor);
        $this->assertSame('refunded', $o->items()->first()->tickets()->first()->status);
        $a = $o->allocations()->first();
        $this->assertSame(9000, $a->seller_net_minor);
        $this->assertSame(9000, (int) $a->seller_refund_liability_minor);
        $m = app(\App\Services\Dashboard\EventMetricsService::class)->metrics($this->event)['by_currency'][0];
        $this->assertSame(10000, $m['gross_minor']);
        $this->assertSame(10000, $m['refunds_minor']);
        $this->assertSame(0, $m['seller_net_minor']);
        $this->assertSame(500, $m['provider_fee_minor']);
        $this->assertSame(0, app(\App\Services\Commerce\InventoryService::class)->commitments([$o->items()->first()->ticket_type_id])[$o->items()->first()->ticket_type_id]['sold_units'] ?? 0);
    }

    public function test_partial_order_refund_only_revokes_owners_complete_lines(): void
    {
        $other = $this->lifecycleEvent(User::factory()->create(), true);
        $o = $this->paid(['items' => [['ticket_type_id' => $this->event->ticketTypes->first()->id, 'quantity' => 1], ['ticket_type_id' => $other->ticketTypes->first()->id, 'quantity' => 1]]]);
        $foreign = $o->items()->where('event_id', $other->id)->first();
        $this->actingAs($this->owner)->postJson('/api/event-operations/'.$this->event->id.'/orders/'.$o->public_id.'/refunds', ['request_key' => (string) Str::uuid(), 'order_item_ids' => [$foreign->id], 'reason' => 'Invalid foreign line'])->assertUnprocessable();
        $r = $this->refund($o);
        $this->providerRefund($r);
        $this->process($r);
        $this->assertSame('partially_refunded', $o->fresh()->status);
        $this->assertTrue($o->fresh()->isFulfilled());
        $this->assertSame('valid', $foreign->tickets()->first()->status);
        $counts = app(\App\Services\Commerce\InventoryService::class)->commitments([$foreign->ticket_type_id]);
        $this->assertSame(1, $counts[$foreign->ticket_type_id]['sold_units']);
    }

    public function test_pending_failed_and_mismatched_refunds_never_claim_completion_or_restore_admission(): void
    {
        $o = $this->paid();
        $r = $this->refund($o);
        $this->providerRefund($r, 'pending');
        $this->process($r);
        $this->assertDatabaseHas('commerce_refunds', ['id' => $r->id, 'status' => 'processing']);
        $this->assertSame('refund_pending', $o->items()->first()->tickets()->first()->status);
        DB::table('commerce_refunds')->where('id', $r->id)->update(['next_attempt_at' => now()]);
        $this->providerRefund($r, 'processed', ['amount' => $r->amount_minor + 1]);
        $this->process($r);
        $this->assertDatabaseHas('commerce_refunds', ['id' => $r->id, 'status' => 'attention']);
        $this->assertSame('paid', $o->fresh()->status);
        $this->assertSame(0, (int) $o->payments()->first()->refunded_minor);
    }

    public function test_ambiguous_provider_failure_retries_same_ref_without_duplicate_refund(): void
    {
        $o = $this->paid();
        $r = $this->refund($o);
        $this->fakeProvider(fn () => Http::response([], 503));
        $this->process($r);
        $this->assertDatabaseHas('commerce_refunds', ['id' => $r->id, 'status' => 'queued']);
        DB::table('commerce_refunds')->where('id', $r->id)->update(['next_attempt_at' => now()]);
        $this->providerRefund($r);
        $this->process($r);
        $this->assertDatabaseCount('commerce_refunds', 1);
        $this->assertDatabaseHas('commerce_refunds', ['id' => $r->id, 'reference' => $r->reference, 'status' => 'processed']);
    }

    public function test_admitted_tickets_require_cancellation_policy_instead_of_normal_refund(): void
    {
        $o = $this->paid();
        $o->attendees()->first()->update(['entries' => 1]);
        $this->actingAs($this->owner)->postJson('/api/event-operations/'.$this->event->id.'/orders/'.$o->public_id.'/refunds', ['request_key' => (string) Str::uuid(), 'order_item_ids' => $o->items()->pluck('id')->all(), 'reason' => 'Request after admission'])->assertConflict();
    }

    public function test_booking_cutoff_sales_closure_and_lifecycle_versions_are_enforced(): void
    {
        $this->actingAs($this->owner);
        $url = '/api/event-operations/'.$this->event->id.'/lifecycle';
        $this->postJson($url, ['version' => 2, 'action' => 'close_sales'])->assertOk();
        $this->postJson('/api/checkout/quote', $this->payload())->assertUnprocessable();
        $this->postJson($url, ['version' => 2, 'action' => 'open_sales'])->assertConflict();
        $this->postJson($url, ['version' => 3, 'action' => 'open_sales'])->assertOk();
        $this->postJson($url, ['version' => 4, 'action' => 'set_cutoff', 'booking_cutoff_at' => now()->subMinute()->toIso8601String()])->assertOk();
        $this->postJson('/api/checkout/initialize', $this->payload())->assertUnprocessable();
        $this->assertDatabaseHas('financial_audits', ['event_id' => $this->event->id, 'action' => 'event.close_sales']);
    }

    public function test_event_cancellation_releases_pending_orders_and_queues_paid_refunds(): void
    {
        $paid = $this->paid();
        $pendingData = app(CheckoutService::class)->initialize($this->payload(), null);
        $pending = Order::where('public_id', $pendingData['order']['public_id'])->first();
        $this->actingAs($this->owner)->postJson('/api/event-operations/'.$this->event->id.'/lifecycle', ['version' => 2, 'action' => 'cancel', 'reason' => 'Venue unavailable'])->assertOk();
        $id = DB::table('event_cancellation_work')->value('id');
        (new ProcessEventCancellation($id))->handle(app(EventLifecycleService::class));
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('void', $paid->items()->first()->tickets()->first()->status);
        $this->assertDatabaseCount('commerce_refunds', 1);
        $this->assertDatabaseHas('event_cancellation_work', ['id' => $id, 'finished' => true]);
        $this->postJson('/api/event-operations/'.$this->event->id.'/lifecycle', ['version' => 3, 'action' => 'archive'])->assertConflict();
        $this->assertGreaterThan(0, DB::table('financial_notifications')->where('kind', 'cancellation')->count());
    }

    public function test_late_verified_charge_for_cancelled_event_queues_refund_without_issuing_ticket(): void
    {
        $data = app(CheckoutService::class)->initialize($this->payload(), null);
        $o = Order::where('public_id', $data['order']['public_id'])->first();
        $p = $o->payments()->first();
        app(EventLifecycleService::class)->change($this->event, $this->owner->id, ['version' => 2, 'action' => 'cancel', 'reason' => 'Cancelled event']);
        app(PaymentFinalizer::class)->finalizePaystack($p->reference, ['id' => '987654', 'reference' => $p->reference, 'amount' => $p->amount_minor, 'currency' => 'NGN', 'status' => 'success', 'fees' => 500]);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseHas('commerce_refunds', ['order_id' => $o->id, 'amount_minor' => 10000, 'status' => 'queued']);
        $this->assertSame('cancelled', $o->fresh()->status);
    }

    public function test_completed_and_archived_events_retain_history_and_do_not_accept_booking(): void
    {
        $this->event->forceFill(['ends_at' => now()->subMinute()])->save();
        $e = app(EventLifecycleService::class)->change($this->event, $this->owner->id, ['version' => 2, 'action' => 'complete']);
        $this->assertSame('completed', $e->lifecycle_state);
        $e = app(EventLifecycleService::class)->change($e, $this->owner->id, ['version' => 3, 'action' => 'archive']);
        $this->getJson('/api/events/'.$e->id)->assertNotFound();
        $this->assertDatabaseHas('events', ['id' => $e->id, 'lifecycle_state' => 'archived']);
    }

    public function test_bank_onboarding_is_masked_verified_and_uses_new_records_for_changes(): void
    {
        $this->actingAs($this->owner);
        $input = ['bank_code' => '058', 'account_number' => '0123456789'];
        $r = $this->postJson('/api/seller/payout-accounts', $input)->assertAccepted();
        $id = $r->json('data.id');
        $this->assertStringNotContainsString('0123456789', $r->getContent());
        $this->postJson('/api/seller/payout-accounts', $input)->assertAccepted();
        $this->assertDatabaseCount('payout_accounts', 1);
        $stored = DB::table('payout_accounts')->find($id);
        $this->assertNotSame('0123456789', $stored->account_number);
        $this->fakeProvider(fn ($r) => Http::response(['status' => true, 'data' => str_contains($r->url(), '/bank/resolve') ? ['account_number' => '0123456789', 'account_name' => 'Ada Buyer'] : ['id' => 567, 'subaccount_code' => 'ACCT_test', 'account_number' => '0123456789', 'currency' => 'NGN', 'settlement_bank' => 'Test bank', 'active' => true, 'is_verified' => true]]));
        (new OnboardPayoutAccount($id))->handle(app(PaystackService::class));
        $a = PayoutAccount::find($id);
        $this->assertSame('active', $a->status);
        $this->assertNull($a->account_number);
        $this->actingAs(User::factory()->create())->getJson('/api/seller/payout-accounts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_subaccount_creation_timeout_is_held_for_review_without_blind_recreation(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/seller/payout-accounts', ['bank_code' => '058', 'account_number' => '0123456789'])->assertAccepted()->json('data.id');
        $this->fakeProvider(fn ($r) => str_contains($r->url(), '/bank/resolve') ? Http::response(['status' => true, 'data' => ['account_number' => '0123456789', 'account_name' => 'Ada Buyer']]) : Http::response([], 503));
        $job = new OnboardPayoutAccount($id);
        $job->handle(app(PaystackService::class));
        $job->handle(app(PaystackService::class));
        $this->assertDatabaseHas('payout_accounts', ['id' => $id, 'onboarding_state' => 'review', 'status' => 'pending']);
        Http::assertSentCount(2);
    }

    private function settlement(Order $o, bool $seller = true, int $effective = 9000): int
    {
        $p = $o->payments()->first();
        $a = $o->allocations()->first();
        $account = null;
        if ($seller) {
            $account = PayoutAccount::create(['user_id' => $this->owner->id, 'provider_subaccount_code' => 'ACCT_settle', 'provider_subaccount_id' => '55', 'status' => 'active']);
            $a->update(['provider_subaccount_code' => 'ACCT_settle', 'settlement_mode' => 'paystack_split']);
        }
        $feed = DB::table('settlement_feeds')->insertGetId(['scope' => $seller ? 'payout:'.$account->id : 'platform', 'payout_account_id' => $account?->id, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $settlement = ['id' => 12345, 'status' => 'success', 'currency' => 'NGN', 'total_amount' => $effective, 'effective_amount' => $effective, 'total_fees' => $seller ? 0 : 500, 'settlement_date' => now()->toIso8601String(), 'createdAt' => now()->toIso8601String()];
        $this->fakeProvider(fn ($r) => Http::response(['status' => true, 'data' => str_contains($r->url(), '/transactions') ? [['id' => $p->provider_transaction_id, 'reference' => $p->reference, 'status' => 'success', 'amount' => $p->amount_minor, 'currency' => 'NGN', 'fees' => 500]] : [$settlement], 'meta' => ['pageCount' => 1]]));
        (new ReconcileSettlementFeed($feed))->handle(app(PaystackService::class));

        return DB::table('provider_settlements')->where('feed_id', $feed)->value('id');
    }

    public function test_seller_settlement_requires_matching_provider_evidence_and_is_idempotent(): void
    {
        $o = $this->paid();
        $id = $this->settlement($o);
        $job = new ReconcileSettlement($id);
        $job->handle(app(PaystackService::class));
        $job->handle(app(PaystackService::class));
        $this->assertDatabaseHas('provider_settlements', ['id' => $id, 'reconciliation_status' => 'reconciled']);
        $this->assertSame(9000, (int) $o->allocations()->first()->settled_minor);
        $this->assertSame('settled', $o->allocations()->first()->settlement_status);
    }

    public function test_platform_settlement_is_not_reported_as_a_seller_payout(): void
    {
        $o = $this->paid();
        $id = $this->settlement($o, false, 9500);
        (new ReconcileSettlement($id))->handle(app(PaystackService::class));
        $a = $o->allocations()->first();
        $this->assertSame(0, (int) $a->settled_minor);
        $this->assertSame('platform_received', $a->settlement_status);
    }

    public function test_settlement_amount_mismatch_requires_review_without_marking_seller_paid(): void
    {
        $o = $this->paid();
        $id = $this->settlement($o, true, 8000);
        (new ReconcileSettlement($id))->handle(app(PaystackService::class));
        $this->assertDatabaseHas('provider_settlements', ['id' => $id, 'reconciliation_status' => 'review']);
        $this->assertSame(0, (int) $o->allocations()->first()->settled_minor);
    }

    public function test_refund_webhooks_only_schedule_independent_verification_and_are_duplicate_safe(): void
    {
        $o = $this->paid();
        $r = $this->refund($o);
        DB::table('commerce_refunds')->where('id', $r->id)->update(['next_attempt_at' => now()->addHour()]);
        $body = json_encode(['event' => 'refund.processed', 'data' => ['reference' => $r->reference, 'id' => 777]], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $body, 'sk_test_fake');
        $this->call('POST', '/api/payments/paystack/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature], $body)->assertOk();
        $this->call('POST', '/api/payments/paystack/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature], $body)->assertOk();
        $this->assertDatabaseCount('commerce_refunds', 1);
        $this->assertDatabaseHas('commerce_refunds', ['id' => $r->id, 'status' => 'queued']);
        $this->assertSame('paid', $o->fresh()->status);
        $this->providerRefund($r);
        $this->process($r);
        $this->assertSame('refunded', $o->fresh()->status);
    }

    public function test_zero_charge_refund_voids_credentials_without_a_provider_payment(): void
    {
        \App\Models\EventDiscount::create(['event_id' => $this->event->id, 'name' => 'Complimentary', 'code' => 'FREE100', 'type' => 'percent', 'value' => 100, 'usage_limit' => 1, 'currency' => 'NGN', 'active' => true]);
        $data = app(CheckoutService::class)->initialize($this->payload(['discount' => ['event_id' => $this->event->id, 'code' => 'FREE100']]), null);
        $o = Order::where('public_id', $data['order']['public_id'])->first();
        Http::assertNothingSent();
        $r = $this->refund($o);
        $this->process($r);
        Http::assertNothingSent();
        $this->assertDatabaseHas('commerce_refunds', ['id' => $r->id, 'status' => 'voided', 'amount_minor' => 0]);
        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertSame('void', $o->items()->first()->tickets()->first()->status);
        $this->assertNotNull(DB::table('discount_redemptions')->value('redeemed_at'));
    }

    public function test_revoked_ticket_can_record_exit_without_restoring_entry_entitlement(): void
    {
        $o = $this->paid();
        $ticket = $o->items()->first()->tickets()->first();
        $o->attendees()->first()->update(['entries' => 1, 'inside' => true]);
        $gate = DB::table('event_entrances')->insertGetId(['event_id' => $this->event->id, 'name' => 'Main', 'active' => true, 'opens_at' => '2020-01-01', 'closes_at' => '2040-01-01']);
        app(EventLifecycleService::class)->change($this->event, $this->owner->id, ['version' => 2, 'action' => 'cancel', 'reason' => 'Venue closed']);
        (new ProcessEventCancellation(DB::table('event_cancellation_work')->value('id')))->handle(app(EventLifecycleService::class));
        $this->actingAs($this->owner)->postJson('/api/event-operations/'.$this->event->id.'/admission/lookup', ['token' => $ticket->token, 'entrance_id' => $gate, 'direction' => 'out'])->assertOk()->assertJsonPath('data.ticket_status', 'void');
        $result = app(\App\Services\AdmissionService::class)->move($this->event, $this->owner->id, ['token' => $ticket->token, 'entrance_id' => $gate, 'direction' => 'out', 'count' => 1, 'request_key' => (string) Str::uuid()]);
        $this->assertSame('recorded', $result['outcome']);
        $this->assertSame(0, $ticket->attendees()->where('inside', true)->count());
        $this->assertSame('void', $ticket->fresh()->status);
    }

    public function test_forward_financial_migration_preserves_legacy_records_without_inventing_settlement(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.phase06_legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('phase06_legacy');
        try {
            $migration = 'database/migrations/2026_11_24_000005_add_financial_and_event_lifecycle.php';
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), basename($migration)) < 0));
            $this->artisan('migrate', ['--database' => 'phase06_legacy', '--path' => $paths, '--realpath' => true, '--force' => true])->assertExitCode(0);
            $owner = User::factory()->create();
            $organizer = \App\Models\Organizer::create(['name' => 'Historical organiser']);
            $address = \App\Models\EventAddress::create(['venue_name' => 'Hall', 'address_line1' => '', 'city' => 'Lagos', 'state' => 'Lagos', 'postal_code' => '', 'country' => 'Nigeria']);
            $event = \App\Models\Event::forceCreate(['title' => 'Historical event', 'description' => 'Description', 'organizer_id' => $organizer->id, 'address_id' => $address->id, 'created_by' => $owner->id, 'status' => 'active', 'ticket_mode' => 'paid', 'date' => now()->addDay(), 'ends_at' => now()->addDays(2), 'price' => 100, 'total_tickets' => 10]);
            \App\Models\TicketType::create(['event_id' => $event->id, 'name' => 'Original table', 'unit_type' => 'table', 'price' => '100.00', 'quantity' => 10, 'people_per_unit' => 4]);
            $o = $this->lifecycleOrder($event, Order::STATUS_PAID, 1);
            $i = $o->items()->first();
            $i->update(['platform_fee_minor' => 1000]);
            $account = PayoutAccount::create(['user_id' => $owner->id, 'provider_subaccount_code' => 'ACCT_legacy', 'status' => 'active', 'account_last4' => '6789']);
            $allocation = \App\Models\OrderAllocation::create(['order_id' => $o->id, 'seller_user_id' => $owner->id, 'payout_account_id' => $account->id, 'provider_subaccount_code' => 'ACCT_legacy', 'gross_minor' => 10000, 'platform_fee_minor' => 1000, 'seller_net_minor' => 9000, 'currency' => 'NGN', 'settlement_mode' => 'paystack_split', 'settlement_status' => 'pending']);
            Payment::create(['order_id' => $o->id, 'reference' => 'KT-legacy', 'provider' => 'paystack', 'provider_transaction_id' => '123456', 'amount_minor' => 10000, 'currency' => 'NGN', 'status' => 'success']);
            \App\Models\Ticket::create(['public_id' => (string) Str::ulid(), 'order_item_id' => $i->id, 'event_id' => $event->id, 'ticket_type_id' => $i->ticket_type_id, 'unit_index' => 0, 'token' => 'historical-private-token', 'token_hash' => hash('sha256', 'historical-private-token'), 'admit_count' => 4, 'status' => 'valid', 'issued_at' => now()]);
            $before = [];
            foreach (['orders', 'order_items', 'payments', 'tickets', 'order_allocations', 'payout_accounts'] as $table) {
                $before[$table] = (array) DB::table($table)->first();
            }
            $this->artisan('migrate', ['--database' => 'phase06_legacy', '--path' => $migration, '--force' => true])->assertExitCode(0);
            foreach ($before as $table => $row) {
                $this->assertSame($row, (array) DB::table($table)->select(array_keys($row))->first());
            }
            $a = DB::table('order_allocations')->find($allocation->id);
            $this->assertSame(10000, (int) $a->gross_before_discount_minor);
            $this->assertSame(0, (int) $a->settled_minor);
            $this->assertNull($a->provider_fee_minor);
            $this->assertNull(DB::table('payments')->value('provider_fee_minor'));
            $this->assertDatabaseCount('commerce_refunds', 0);
            $this->assertDatabaseCount('financial_notifications', 0);
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('phase06_legacy');
        }
    }
}
