<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Commerce\InventoryService;
use App\Services\Commerce\PaymentFinalizer;
use App\Services\EventMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EventLifecycleFixtures;
use Tests\TestCase;

class EventLifecycleIntegrityTest extends TestCase
{
    use EventLifecycleFixtures, RefreshDatabase;

    public function test_supplied_draft_values_are_validated_against_schema_and_business_types(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/create-event', [])->assertCreated();
        foreach ([
            ['description' => str_repeat('??', 17000)], ['title' => str_repeat('x', 101)], ['organiser' => str_repeat('x', 101)], ['venue' => str_repeat('x', 256)],
            ['category' => 'Not a category'], ['timeZone' => 'Made/Up'], ['startsAtLocal' => '2030-02-31T18:00:00'],
            ['startsAt' => '2030-06-21 18:00:00'], ['meetingLink' => 'javascript:alert(1)'], ['organiserWebsite' => 'file:///secret'],
            ['ticketMode' => 'paid', 'tickets' => [['name' => str_repeat('x', 51)]]],
            ['ticketMode' => 'paid', 'tickets' => [['price' => 1000000]]], ['ticketMode' => 'paid', 'tickets' => [['price' => 1.001]]],
            ['ticketMode' => 'paid', 'tickets' => [['units' => -1]]], ['tickets' => null], ['freeCapacity' => -1],
        ] as $invalid) {
            $this->postJson('/api/create-event', $invalid)->assertUnprocessable();
        }
        $this->assertDatabaseCount('events', 1);
    }

    public function test_publish_and_active_edits_share_format_end_date_and_paid_price_rules(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner);
        $this->actingAs($owner);
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 1, 'format' => 'online', 'tickets' => [['id' => $event->ticketTypes->first()->id, 'price' => 0]]])->assertOk();
        $this->postJson("/api/create-event/{$event->id}/publish", ['version' => 2])->assertUnprocessable()->assertJsonValidationErrors(['meetingLink', 'tickets.0.price']);
        $fixed = $this->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'meetingLink' => 'https://meet.example.com/join', 'tickets' => [['id' => $event->ticketTypes->first()->id, 'price' => '200.00', 'units' => 0]]])->assertOk();
        $this->postJson("/api/create-event/{$event->id}/publish", ['version' => $fixed->json('data.version')])->assertOk();
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 4, 'meetingLink' => null])->assertUnprocessable()->assertJsonValidationErrors('meetingLink');
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 4, 'endsAtLocal' => '2030-06-21T17:00:00'])->assertUnprocessable()->assertJsonValidationErrors('endsAtLocal');
        $this->assertSame(4, $event->fresh()->lock_version);
        $this->getJson("/api/events/{$event->id}")->assertOk()->assertJsonPath('data.ticket_types.0.available_quantity', 0);
    }

    public function test_versions_prevent_stale_updates_and_publish_retries(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner);
        $this->actingAs($owner)->patchJson("/api/create-event/{$event->id}", ['title' => 'No version'])->assertStatus(428);
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 1, 'title' => 'First writer'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 1, 'title' => 'Stale writer'])->assertConflict();
        $this->postJson("/api/create-event/{$event->id}/publish", ['version' => 1])->assertConflict();
        $this->postJson("/api/create-event/{$event->id}/publish", ['version' => 2])->assertOk();
        $this->postJson("/api/create-event/{$event->id}/publish", ['version' => 3])->assertUnprocessable();
        $this->assertSame('First writer', $event->fresh()->title);
    }

    public function test_ticket_merges_preserve_unrelated_products_and_replacement_retires_omissions(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner);
        $first = $event->ticketTypes->first();
        $this->actingAs($owner)->patchJson("/api/create-event/{$event->id}", ['version' => 1, 'tickets' => [['name' => 'Second', 'price' => 50, 'units' => 20]]])->assertOk()->assertJsonCount(2, 'data.form.tickets');
        $second = $event->ticketTypes()->where('id', '!=', $first->id)->firstOrFail();
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'tickets' => [['id' => $first->id, 'price' => 120]]])->assertOk()->assertJsonCount(2, 'data.form.tickets');
        $this->assertSame(10, $first->fresh()->quantity);
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 3, 'ticketCollectionMode' => 'replace', 'tickets' => [['id' => $second->id]]])->assertOk()->assertJsonCount(1, 'data.form.tickets');
        $this->assertNotNull($first->fresh()->retired_at);
        $this->assertFalse($first->fresh()->visible);
        $this->assertSame('Second', $second->fresh()->name);
        $this->assertDatabaseCount('ticket_types', 2);
    }

    public function test_unknown_foreign_duplicate_and_retired_ticket_ids_are_explicitly_rejected(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner);
        $other = $this->lifecycleEvent($owner);
        $this->actingAs($owner);
        foreach ([999999, $other->ticketTypes->first()->id, ['invalid']] as $id) {
            $this->patchJson("/api/create-event/{$event->id}", ['version' => 1, 'tickets' => [['id' => $id]]])->assertUnprocessable()->assertJsonValidationErrors('tickets.0.id');
        }
        $id = $event->ticketTypes->first()->id;
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 1, 'tickets' => [['id' => $id], ['id' => $id]]])->assertUnprocessable();
        $this->assertDatabaseCount('ticket_types', 2);
    }

    public function test_capacity_guards_sold_and_reserved_units_and_freezes_admission_semantics(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $type = $event->ticketTypes->first();
        $this->lifecycleOrder($event, Order::STATUS_PAID, 2);
        $this->lifecycleOrder($event, Order::STATUS_PENDING_PAYMENT, 3);
        $this->actingAs($owner);
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'tickets' => [['id' => $type->id, 'units' => 4]]])->assertUnprocessable()->assertJsonValidationErrors('tickets.0.units');
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'tickets' => [['id' => $type->id, 'peoplePerUnit' => 8]]])->assertUnprocessable()->assertJsonValidationErrors('tickets.0.peoplePerUnit');
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'ticketMode' => 'free'])->assertUnprocessable();
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'tickets' => [['id' => $type->id, 'units' => 5]]])->assertOk();
        $this->getJson("/api/events/{$event->id}")->assertOk()->assertJsonPath('data.ticket_types.0.sold_units', 2)->assertJsonPath('data.ticket_types.0.reserved_units', 3)->assertJsonPath('data.ticket_types.0.available_quantity', 0)->assertJsonPath('data.ticket_types.0.admission_capacity', 20);
        \App\Models\EventLike::create(['user_id' => $owner->id, 'event_id' => $event->id]);
        $this->getJson('/api/user/liked-events')->assertOk()->assertJsonPath('data.0.ticket_types.0.available_quantity', 0);
        $this->getJson("/api/dashboard/events/{$event->id}/overview")->assertOk()->assertJsonPath('data.sales.units_sold', 2)->assertJsonPath('data.sales.reserved_units', 3)->assertJsonPath('data.sales.available_units', 0);
    }

    public function test_catalog_edits_and_retirement_preserve_order_snapshots_and_issued_admission_counts(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $type = $event->ticketTypes->first();
        $order = $this->lifecycleOrder($event, units: 2);
        $payment = Payment::create(['order_id' => $order->id, 'provider' => 'paystack', 'reference' => 'phase02-history', 'amount_minor' => 20000, 'currency' => 'NGN', 'status' => 'initialized']);
        $this->actingAs($owner)->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'tickets' => [['id' => $type->id, 'price' => '250.00', 'name' => 'Renamed']]])->assertOk();
        $this->assertSame('Gold table', $order->items->first()->ticket_name);
        $this->assertSame(10000, (int) $order->items->first()->unit_price_minor);
        app(PaymentFinalizer::class)->finalizePaystack($payment->reference, ['status' => 'success', 'id' => 'phase02-transaction', 'reference' => $payment->reference, 'amount' => 20000, 'currency' => 'NGN', 'paid_at' => now()->toIso8601String()]);
        $this->assertDatabaseCount('tickets', 2);
        $this->assertDatabaseHas('tickets', ['admit_count' => 4, 'ticket_type_id' => $type->id]);
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 3, 'ticketCollectionMode' => 'replace', 'tickets' => [['name' => 'New sale', 'price' => 300, 'units' => 5]]])->assertOk();
        $this->assertNotNull($type->fresh()->retired_at);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('tickets', 2);
        $this->getJson("/api/events/{$event->id}")->assertOk()->assertJsonCount(1, 'data.ticket_types')->assertJsonPath('data.ticket_types.0.name', 'New sale');
    }

    public function test_expired_reservations_release_inventory_but_late_success_cannot_oversell(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $type = $event->ticketTypes->first();
        $order = $this->lifecycleOrder($event, units: 1);
        $order->update(['expires_at' => now()->subMinute()]);
        $payment = Payment::create(['order_id' => $order->id, 'provider' => 'paystack', 'reference' => 'phase02-expired', 'amount_minor' => 10000, 'currency' => 'NGN', 'status' => 'initialized']);
        $this->actingAs($owner)->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'tickets' => [['id' => $type->id, 'units' => 0]]])->assertOk();
        $result = app(PaymentFinalizer::class)->finalizePaystack($payment->reference, ['status' => 'success', 'reference' => $payment->reference, 'amount' => 10000, 'currency' => 'NGN', 'paid_at' => now()->subMinutes(2)->toIso8601String()]);
        $this->assertSame(Order::STATUS_PAYMENT_REVIEW, $result->status);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_utc_storage_local_round_trips_dst_and_mismatching_instants(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $response = $this->postJson('/api/create-event', ['timeZone' => 'Africa/Lagos', 'startsAtLocal' => '2030-06-21T18:00:00', 'endsAtLocal' => '2030-06-21T22:00:00'])->assertCreated();
        $id = $response->json('data.id');
        $event = Event::findOrFail($id);
        $this->assertSame('2030-06-21 17:00:00', $event->date->utc()->format('Y-m-d H:i:s'));
        $this->getJson("/api/create-event/{$id}")->assertJsonPath('data.form.startsAtLocal', '2030-06-21T18:00:00');
        $this->patchJson("/api/create-event/{$id}", ['version' => 1, 'timeZone' => 'America/New_York'])->assertOk()->assertJsonPath('data.form.startsAtLocal', '2030-06-21T13:00:00');
        $this->postJson('/api/create-event', ['timeZone' => 'America/New_York', 'startsAtLocal' => '2030-03-10T02:30:00'])->assertUnprocessable();
        $this->postJson('/api/create-event', ['timeZone' => 'Africa/Lagos', 'startsAtLocal' => '2030-06-21T18:00:00', 'startsAt' => '2030-06-21T18:00:00Z'])->assertUnprocessable();
    }

    public function test_real_media_is_deduplicated_classified_and_cleaned_after_replacement(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6p30AAAAASUVORK5CYII=';
        $response = $this->postJson('/api/create-event', ['coverImage' => $png, 'secondaryImages' => [$png, $png]])->assertCreated();
        $id = $response->json('data.id');
        $url = $response->json('data.form.coverImage');
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('event_media', 1);
        $this->assertDatabaseHas('event_media', ['event_id' => $id, 'source_type' => 'upload', 'url' => $url]);
        $this->patchJson("/api/create-event/{$id}", ['version' => 1, 'coverImage' => $url])->assertOk();
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->patchJson("/api/create-event/{$id}", ['version' => 2, 'coverImage' => 'https://example.com/new.png', 'secondaryImages' => []])->assertOk();
        app(EventMediaService::class)->discardUnreferenced($id, [$url]);
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->assertDatabaseHas('event_media', ['event_id' => $id, 'source_type' => 'external_url']);
    }

    public function test_invalid_media_rolls_back_rows_and_new_files_and_preserves_existing_assets(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6p30AAAAASUVORK5CYII=';
        $this->postJson('/api/create-event', ['coverImage' => $png, 'secondaryImages' => ['data:image/png;base64,'.base64_encode('<?php executable();')]])->assertUnprocessable();
        $this->assertDatabaseCount('events', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->postJson('/api/create-event', ['coverImage' => 'data:image/svg+xml;base64,'.base64_encode('<svg/>')])->assertUnprocessable();
        $this->postJson('/api/create-event', ['coverImage' => '/storage/events/images/../../secret'])->assertUnprocessable();
    }

    public function test_public_profile_authoring_is_owner_only_versioned_and_uses_existing_profile_storage(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true, false);
        $this->actingAs(User::factory()->create())->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'publicProfile' => ['summary' => 'Intrusion']])->assertForbidden();
        $this->actingAs($owner)->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'publicProfile' => ['summary' => 'Public summary', 'faqs' => [['question' => 'When?', 'answer' => 'At six.']], 'moments' => [['url' => 'https://example.com/moment.jpg', 'caption' => 'Stage']]]])->assertOk();
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 3, 'publicProfile' => ['caption_title' => 'Join us']])->assertOk();
        $this->getJson("/api/events/{$event->id}")->assertOk()->assertJsonPath('data.public_profile.summary', 'Public summary')->assertJsonPath('data.public_profile.caption_title', 'Join us')->assertJsonPath('data.public_profile.faqs.0.answer', 'At six.');
        $this->assertDatabaseCount('event_public_profiles', 1);
    }

    public function test_discovery_filters_search_city_category_and_paginates_on_the_server(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true, false);
        $this->lifecycleEvent($owner, true, false);
        $this->lifecycleEvent($owner, false, false);
        $this->getJson('/api/events?q=music&city=lagos&per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 2);
        $this->getJson('/api/events?q=does-not-exist')->assertJsonCount(0, 'data');
        $this->getJson('/api/events?q=%25')->assertJsonCount(0, 'data');
        $this->getJson('/api/events?city=Abuja')->assertJsonCount(0, 'data');
        $this->getJson('/api/events?category_id='.$event->category_id)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/events?category_id=999999')->assertUnprocessable();
    }

    public function test_completed_legacy_purchases_also_protect_product_capacity_and_entitlements(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $type = $event->ticketTypes->first();
        DB::table('purchases')->insert(['user_id' => $owner->id, 'event_id' => $event->id, 'ticket_type_id' => $type->id, 'quantity' => 4, 'total_price' => 400, 'status' => 'completed']);
        $this->actingAs($owner)->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'tickets' => [['id' => $type->id, 'units' => 3]]])->assertUnprocessable();
        $this->getJson("/api/events/{$event->id}")->assertOk()->assertJsonPath('data.ticket_types.0.sold_units', 4)->assertJsonPath('data.ticket_types.0.available_quantity', 6);
    }

    public function test_discovery_query_plans_use_the_new_filter_indexes(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true, false);
        $prefix = DB::getDriverName() === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ';
        $eventPlan = DB::select($prefix.'SELECT id FROM events WHERE status = ? AND category_id = ? AND date >= ? ORDER BY date, id LIMIT 12', ['active', $event->category_id, '2030-01-01']);
        $cityPlan = DB::select($prefix.'SELECT id FROM event_addresses WHERE city_search = ?', ['lagos']);
        $eventJson = json_encode($eventPlan);
        $cityJson = json_encode($cityPlan);
        $this->assertStringContainsString('events_discovery_category_date', $eventJson);
        $this->assertStringContainsString('event_addresses_city_search', $cityJson);
    }

    public function test_failed_checkout_retry_cannot_restore_a_reservation_after_capacity_is_reduced(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $type = $event->ticketTypes->first();
        config(['services.paystack.secret_key' => 'test-secret', 'services.paystack.base_url' => 'https://api.paystack.co']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['status' => false], 500)]);
        $payload = ['checkout_token' => (string) \Illuminate\Support\Str::uuid(), 'customer' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'retry@example.com', 'phone' => '+2348000000000'], 'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]]];
        $this->postJson('/api/checkout/initialize', $payload)->assertStatus(500);
        $this->assertDatabaseHas('orders', ['status' => Order::STATUS_PAYMENT_FAILED]);
        $this->actingAs($owner)->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'tickets' => [['id' => $type->id, 'units' => 0]]])->assertOk();
        $this->postJson('/api/checkout/initialize', $payload)->assertUnprocessable();
        $this->assertSame(Order::STATUS_PAYMENT_FAILED, Order::firstOrFail()->status);
        $this->assertSame([], app(InventoryService::class)->reservedQuantities([$type->id]));
    }

    public function test_ticket_sales_instants_and_dst_overlap_survive_editor_reopen(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner);
        $id = $event->ticketTypes->first()->id;
        $this->actingAs($owner)->patchJson("/api/create-event/{$event->id}", ['version' => 1, 'tickets' => [['id' => $id, 'salesStartLocal' => '2030-06-20T18:00:00', 'salesEndLocal' => '2030-06-21T21:00:00']]])->assertOk();
        $this->assertSame('2030-06-20 17:00:00', $event->ticketTypes()->first()->sales_start_date->utc()->format('Y-m-d H:i:s'));
        $this->patchJson("/api/create-event/{$event->id}", ['version' => 2, 'timeZone' => 'America/New_York'])->assertOk()->assertJsonPath('data.form.tickets.0.salesStartLocal', '2030-06-20T13:00:00');
        $overlap = $this->postJson('/api/create-event', ['timeZone' => 'America/New_York', 'startsAtLocal' => '2030-11-03T01:30:00', 'startsAt' => '2030-11-03T06:30:00Z'])->assertCreated();
        $this->getJson('/api/create-event/'.$overlap->json('data.id'))->assertOk()->assertJsonPath('data.form.startsAt', '2030-11-03T06:30:00+00:00');
    }

    public function test_assets_referenced_by_profiles_survive_media_removal_and_invalid_updates(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6p30AAAAASUVORK5CYII=';
        $created = $this->postJson('/api/create-event', ['coverImage' => $png])->assertCreated();
        $id = $created->json('data.id');
        $url = $created->json('data.form.coverImage');
        $this->patchJson("/api/create-event/{$id}", ['version' => 1, 'coverImage' => 'https://example.com/new.png', 'secondaryImages' => ['data:image/png;base64,'.base64_encode('invalid')]])->assertUnprocessable();
        $this->assertSame($url, Event::findOrFail($id)->main_image);
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->patchJson("/api/create-event/{$id}", ['version' => 1, 'coverImage' => null, 'publicProfile' => ['moments' => [['url' => $url, 'caption' => 'Keep this']]]])->assertOk();
        app(EventMediaService::class)->discardUnreferenced($id, [$url]);
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->patchJson("/api/create-event/{$id}", ['version' => 2, 'publicProfile' => ['moments' => []]])->assertOk();
        app(EventMediaService::class)->discardUnreferenced($id, [$url]);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }
}
