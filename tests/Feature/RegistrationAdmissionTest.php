<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Commerce\PaymentFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\EventLifecycleFixtures;
use Tests\TestCase;

class RegistrationAdmissionTest extends TestCase
{
    use EventLifecycleFixtures, RefreshDatabase;

    private function ticket(): array
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $order = $this->lifecycleOrder($event, units: 1);
        $payment = Payment::create(['order_id' => $order->id, 'provider' => 'paystack', 'reference' => 'KT-'.Str::ulid(), 'amount_minor' => $order->total_minor, 'currency' => 'NGN', 'status' => 'initialized']);
        app(PaymentFinalizer::class)->finalizePaystack($payment->reference, ['id' => (string) Str::ulid(), 'reference' => $payment->reference, 'amount' => $payment->amount_minor, 'currency' => 'NGN', 'status' => 'success']);
        $entrance = DB::table('event_entrances')->insertGetId(['event_id' => $event->id, 'name' => 'Main gate', 'active' => true, 'opens_at' => '2020-01-01', 'closes_at' => '2040-01-01', 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $event, Ticket::where('event_id', $event->id)->firstOrFail(), $entrance];
    }

    private function scan(Event $event, Ticket $ticket, int $entrance, string $direction = 'in', int $count = 1, ?string $key = null)
    {
        return $this->postJson('/api/event-operations/'.$event->id.'/admission/movements', ['token' => $ticket->token, 'entrance_id' => $entrance, 'direction' => $direction, 'count' => $count, 'request_key' => $key ?? (string) Str::uuid()]);
    }

    public function test_table_partial_entry_exit_and_explicit_reentry_are_persistent_and_idempotent(): void
    {
        [$owner, $event, $ticket, $entrance] = $this->ticket();
        $this->actingAs($owner);
        $key = (string) Str::uuid();
        $this->scan($event, $ticket, $entrance, count: 3, key: $key)->assertOk()->assertJsonPath('data.inside_count', 3);
        $this->scan($event, $ticket, $entrance, count: 3, key: $key)->assertOk()->assertJsonPath('data.inside_count', 3);
        $this->scan($event, $ticket, $entrance, count: 1, key: $key)->assertConflict();
        $this->scan($event, $ticket, $entrance, count: 2)->assertOk()->assertJsonPath('data.outcome', 'denied');
        $this->scan($event, $ticket, $entrance, direction: 'out', count: 2)->assertOk()->assertJsonPath('data.inside_count', 1);
        $this->scan($event, $ticket, $entrance, count: 2)->assertOk()->assertJsonPath('data.outcome', 'denied');
        $event->forceFill(['allow_reentry' => true])->save();
        $this->scan($event, $ticket, $entrance, count: 2)->assertOk()->assertJsonPath('data.outcome', 'recorded')->assertJsonPath('data.inside_count', 3);
        $this->assertDatabaseCount('admission_movements', 3);
        $this->assertDatabaseHas('admission_movements', ['event_id' => $event->id, 'entrance_id' => $entrance, 'staff_user_id' => $owner->id, 'count' => 3]);
    }

    public function test_door_staff_are_scoped_and_receive_no_attendee_answers_or_buyer_details(): void
    {
        [$owner, $event, $ticket, $entrance] = $this->ticket();
        $this->getJson('/api/event-operations/'.$event->id.'/admission')->assertUnauthorized();
        $staff = User::factory()->create();
        DB::table('event_public_profiles')->insert(['event_id' => $event->id, 'crew' => json_encode([['name' => $staff->name, 'email' => $staff->email, 'role' => 'Door staff']]), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($staff)->postJson('/api/event-operations/'.$event->id.'/admission/lookup', ['token' => $ticket->token, 'entrance_id' => $entrance])->assertForbidden();
        DB::table('event_staff')->insert(['event_id' => $event->id, 'user_id' => $staff->id, 'entrance_id' => $entrance, 'role' => 'door', 'active' => true]);
        $lookup = $this->postJson('/api/event-operations/'.$event->id.'/admission/lookup', ['token' => $ticket->token, 'entrance_id' => $entrance])->assertOk();
        $this->assertSame(['ticket_public_id', 'ticket_status', 'ticket_name', 'admit_count', 'inside_count', 'remaining_first_entries'], array_keys($lookup->json('data')));
        $this->getJson('/api/event-operations/'.$event->id.'/attendees')->assertForbidden();
        $otherEntrance = DB::table('event_entrances')->insertGetId(['event_id' => $event->id, 'name' => 'Other', 'active' => true]);
        $this->scan($event, $ticket, $otherEntrance)->assertForbidden();
        $this->actingAs($owner)->deleteJson('/api/event-operations/'.$event->id.'/staff/'.DB::table('event_staff')->value('id'))->assertOk();
        $this->actingAs($staff);
        $this->scan($event, $ticket, $entrance)->assertForbidden();
    }

    public function test_closed_foreign_cancelled_refunded_and_recurring_admissions_are_rejected(): void
    {
        [$owner, $event, $ticket, $entrance] = $this->ticket();
        $this->actingAs($owner);
        [$otherOwner, $otherEvent, $otherTicket, $otherEntrance] = $this->ticket();
        $this->scan($event, $otherTicket, $entrance)->assertNotFound();
        $this->scan($event, $ticket, $otherEntrance)->assertUnprocessable();
        DB::table('event_entrances')->where('id', $entrance)->update(['closes_at' => '2021-01-01']);
        $this->scan($event, $ticket, $entrance)->assertOk()->assertJsonPath('data.reason', 'entrance_closed');
        $ticket->orderItem->order->update(['status' => 'refunded']);
        $this->scan($event, $ticket, $entrance)->assertUnprocessable();
        $ticket->orderItem->order->update(['status' => 'paid']);
        $event->forceFill(['event_type' => 'recurring'])->save();
        $this->scan($event, $ticket, $entrance)->assertUnprocessable();
        $this->assertDatabaseCount('admission_movements', 0);
    }

    public function test_free_registration_uses_existing_records_without_payment_and_enforces_capacity(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true, false);
        $event->forceFill(['total_tickets' => 1, 'attendee_fields' => json_encode([['id' => 'name', 'label' => 'Name', 'enabled' => true, 'required' => true]])])->save();
        $payload = ['checkout_token' => (string) Str::uuid(), 'quantity' => 1, 'customer' => ['first_name' => 'Buyer', 'last_name' => 'Only', 'email' => 'buyer@example.com'], 'attendees' => [['unit_index' => 0, 'seat_index' => 0, 'answers' => ['name' => 'Actual guest']]]];
        $first = $this->postJson('/api/events/'.$event->id.'/registrations', $payload)->assertCreated()->assertJsonPath('data.status', 'registered')->assertJsonPath('data.items.0.tickets.0.attendees.0.name', 'Actual guest');
        $this->postJson('/api/events/'.$event->id.'/registrations', $payload)->assertCreated()->assertJsonPath('data.public_id', $first->json('data.public_id'));
        $payload['checkout_token'] = (string) Str::uuid();
        $this->postJson('/api/events/'.$event->id.'/registrations', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('commerce_deliveries', 1);
        $this->actingAs($owner)->patchJson('/api/create-event/'.$event->id, ['version' => 2, 'freeCapacity' => 0])->assertUnprocessable();
    }

    public function test_paid_registration_validates_every_table_seat_and_preserves_historical_schema(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $event->forceFill(['attendee_fields' => json_encode([['id' => 'name', 'label' => 'Original name label', 'enabled' => true, 'required' => true]])])->save();
        config(['services.paystack.secret_key' => 'sk_test_fake']);
        Http::fake(fn ($request) => Http::response(['status' => true, 'data' => ['reference' => $request['reference'], 'access_code' => 'test', 'authorization_url' => 'https://example.invalid/pay']]));
        $type = $event->ticketTypes()->first();
        $payload = ['checkout_token' => (string) Str::uuid(), 'customer' => ['first_name' => 'Buyer', 'last_name' => 'Only', 'email' => 'buyer@example.com', 'phone' => '08000000000'], 'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]]];
        $this->postJson('/api/checkout/initialize', $payload)->assertUnprocessable();
        for ($seat = 0; $seat < 4; $seat++) {
            $payload['attendees'][] = ['ticket_type_id' => $type->id, 'unit_index' => 0, 'seat_index' => $seat, 'answers' => ['name' => 'Guest '.$seat]];
        }
        $response = $this->postJson('/api/checkout/initialize', $payload)->assertCreated();
        $this->assertDatabaseCount('attendees', 4);
        $attendee = Attendee::firstOrFail();
        $event->forceFill(['attendee_fields' => json_encode([['id' => 'phone', 'label' => 'New question', 'enabled' => true, 'required' => true]])])->save();
        $this->actingAs($owner)->patchJson('/api/event-operations/'.$event->id.'/attendees/'.$attendee->id, ['version' => 1, 'answers' => ['name' => 'Updated guest']])->assertOk()->assertJsonPath('data.name', 'Updated guest');
        $this->patchJson('/api/event-operations/'.$event->id.'/attendees/'.$attendee->id, ['version' => 1, 'answers' => ['name' => 'Stale']])->assertConflict();
        $this->assertSame('Original name label', $attendee->orderItem->registration_schema[0]['label']);
        $this->assertNotSame('Buyer Only', $attendee->name);
    }

    public function test_invitation_requires_secret_and_matching_account_and_can_be_revoked(): void
    {
        [$owner, $event, $ticket, $entrance] = $this->ticket();
        $staff = User::factory()->create();
        $this->actingAs($owner)->postJson('/api/event-operations/'.$event->id.'/staff/invitations', ['email' => $staff->email, 'role' => 'door', 'entrance_id' => $entrance])->assertCreated()->assertJsonMissingPath('data.token');
        $invitation = DB::table('event_staff_invitations')->first();
        $token = Crypt::decryptString($invitation->encrypted_token);
        $this->postJson('/api/event-staff/invitations/accept', ['token' => $token])->assertForbidden();
        Mail::fake();
        (new \App\Jobs\DeliverStaffInvitation($invitation->id))->handle();
        $this->assertNotNull(DB::table('event_staff_invitations')->first()->sent_at);
        $this->actingAs($staff)->postJson('/api/event-staff/invitations/accept', ['token' => $token])->assertOk();
        $this->postJson('/api/event-staff/invitations/accept', ['token' => $token])->assertForbidden();
        $this->scan($event, $ticket, $entrance)->assertOk()->assertJsonPath('data.outcome', 'recorded');
        $this->getJson('/api/event-operations/'.$event->id.'/staff')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/event-operations/'.$event->id.'/staff')->assertOk()->assertJsonPath('data.0.email', $staff->email)->assertJsonPath('data.0.name', $staff->name)->assertJsonMissingPath('data.0.password');
    }

    public function test_management_search_export_resend_and_cross_event_ownership(): void
    {
        [$owner, $event, $ticket] = $this->ticket();
        Attendee::where('ticket_id', $ticket->id)->firstOrFail()->update(['name' => '=HYPERLINK("bad")', 'email' => 'guest@example.com']);
        $this->actingAs(User::factory()->create())->getJson('/api/event-operations/'.$event->id.'/attendees')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/event-operations/'.$event->id.'/attendees?q=guest&per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.ticket.token');
        $this->getJson('/api/event-operations/'.$event->id.'/attendees?per_page=101')->assertUnprocessable();
        $csv = $this->get('/api/event-operations/'.$event->id.'/attendees/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString($ticket->token, $csv);
        $this->postJson('/api/event-operations/'.$event->id.'/tickets/'.$ticket->public_id.'/resend')->assertStatus(202);
        $this->getJson('/api/event-operations/'.$event->id.'/purchases')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_recurring_bookings_are_explicitly_blocked_for_paid_and_free_events(): void
    {
        $owner = User::factory()->create();
        $paid = $this->lifecycleEvent($owner, true);
        $paid->forceFill(['event_type' => 'recurring'])->save();
        $this->postJson('/api/checkout/quote', ['items' => [['ticket_type_id' => $paid->ticketTypes()->first()->id, 'quantity' => 1]]])->assertUnprocessable();
        $this->getJson('/api/events/'.$paid->id)->assertOk()->assertJsonPath('data.booking_supported', false);
        $free = $this->lifecycleEvent($owner, true, false);
        $free->forceFill(['event_type' => 'recurring', 'total_tickets' => 10])->save();
        $this->postJson('/api/events/'.$free->id.'/registrations', ['checkout_token' => (string) Str::uuid(), 'quantity' => 1,
            'customer' => ['first_name' => 'Buyer', 'last_name' => 'Only', 'email' => 'buyer@example.com']])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_zero_capacity_and_invalid_question_seats_never_create_free_orders(): void
    {
        $event = $this->lifecycleEvent(User::factory()->create(), true, false);
        $payload = ['checkout_token' => (string) Str::uuid(), 'quantity' => 1, 'customer' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'buyer@example.com']];
        $this->postJson('/api/events/'.$event->id.'/registrations', $payload)->assertUnprocessable();
        $event->forceFill(['total_tickets' => 10, 'attendee_fields' => json_encode([['id' => 'name', 'label' => 'Name', 'enabled' => true, 'required' => true]])])->save();
        $this->postJson('/api/events/'.$event->id.'/registrations', $payload)->assertUnprocessable();
        $payload['attendees'] = [['unit_index' => 0, 'seat_index' => 0, 'answers' => ['name' => 'Attendee', 'unknown' => 'not permitted']]];
        $this->postJson('/api/events/'.$event->id.'/registrations', $payload)->assertUnprocessable();
        $payload['attendees'][0]['answers'] = ['name' => 'Attendee'];
        $payload['attendees'][0]['unit_index'] = 1;
        $this->postJson('/api/events/'.$event->id.'/registrations', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('attendees', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_expired_and_revoked_invitations_never_create_memberships(): void
    {
        [$owner, $event] = $this->ticket();
        $staff = User::factory()->create();
        $this->actingAs($owner)->postJson('/api/event-operations/'.$event->id.'/staff/invitations', ['email' => $staff->email, 'role' => 'manager'])->assertCreated();
        $invitation = DB::table('event_staff_invitations')->first();
        $token = Crypt::decryptString($invitation->encrypted_token);
        DB::table('event_staff_invitations')->where('id', $invitation->id)->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($staff)->postJson('/api/event-staff/invitations/accept', ['token' => $token])->assertForbidden();
        $this->actingAs($owner)->deleteJson('/api/event-operations/'.$event->id.'/staff/invitations/'.$invitation->id)->assertOk();
        DB::table('event_staff_invitations')->where('id', $invitation->id)->update(['expires_at' => now()->addDay()]);
        $this->actingAs($staff)->postJson('/api/event-staff/invitations/accept', ['token' => $token])->assertForbidden();
        $this->assertDatabaseCount('event_staff', 0);
    }

    public function test_attendee_search_query_plan_can_use_event_identity_indexes(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Query plan verification uses MariaDB/MySQL.');
        }
        [$owner, $event] = $this->ticket();
        foreach (['name', 'email'] as $field) {
            $plan = DB::select('EXPLAIN SELECT id FROM attendees WHERE event_id = ? AND '.$field.' LIKE ?', [$event->id, 'A%']);
            $this->assertStringContainsString('attendees_event_id_'.$field.'_index', (string) $plan[0]->possible_keys);
            $this->assertNotEmpty($plan[0]->key);
        }
    }

    public function test_identical_free_retry_after_event_end_returns_the_original_registration(): void
    {
        $event = $this->lifecycleEvent(User::factory()->create(), true, false);
        $event->forceFill(['total_tickets' => 1])->save();
        $payload = ['checkout_token' => (string) Str::uuid(), 'quantity' => 1, 'customer' => ['first_name' => 'Buyer', 'last_name' => 'Only', 'email' => 'buyer@example.com']];
        $first = $this->postJson('/api/events/'.$event->id.'/registrations', $payload)->assertCreated();
        $event->forceFill(['ends_at' => now()->subMinute()])->save();
        $this->postJson('/api/events/'.$event->id.'/registrations', $payload)->assertCreated()->assertJsonPath('data.public_id', $first->json('data.public_id'));
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_entrance_timezones_round_trip_as_utc_iso_dates(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $this->actingAs($owner)->postJson('/api/event-operations/'.$event->id.'/entrances', ['name' => 'Main', 'active' => true,
            'opens_at' => '2030-06-21T18:00:00+01:00', 'closes_at' => '2030-06-21T23:00:00+01:00'])->assertOk()
            ->assertJsonPath('data.opens_at', '2030-06-21T17:00:00+00:00')->assertJsonPath('data.closes_at', '2030-06-21T22:00:00+00:00');
        $this->getJson('/api/event-operations/'.$event->id.'/admission')->assertOk()->assertJsonPath('data.entrances.0.opens_at', '2030-06-21T17:00:00+00:00');
    }

    public function test_forward_migration_preserves_historical_credentials_and_creates_unnamed_seats(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.phase04_legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('phase04_legacy');
        try {
            $migration = 'database/migrations/2026_11_24_000003_add_registration_and_admission.php';
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), basename($migration)) < 0));
            $this->artisan('migrate', ['--database' => 'phase04_legacy', '--path' => $paths, '--realpath' => true, '--force' => true])->assertExitCode(0);
            $user = User::factory()->create();
            $organizer = \App\Models\Organizer::create(['name' => 'Historical organiser']);
            $address = \App\Models\EventAddress::create(['venue_name' => 'Hall', 'address_line1' => '', 'city' => 'Lagos', 'state' => 'Lagos', 'postal_code' => '', 'country' => 'Nigeria']);
            $event = Event::forceCreate(['title' => 'Historical event', 'description' => 'Original description', 'organizer_id' => $organizer->id, 'address_id' => $address->id, 'price' => 100, 'total_tickets' => 40, 'created_by' => $user->id, 'status' => 'active', 'ticket_mode' => 'paid', 'date' => now(), 'ends_at' => now()->addHours(3)]);
            $type = \App\Models\TicketType::create(['event_id' => $event->id, 'name' => 'Original table', 'unit_type' => 'table', 'price' => '100.00', 'quantity' => 10, 'people_per_unit' => 4]);
            $order = $this->lifecycleOrder($event, status: 'paid', units: 2);
            $item = $order->items()->first();
            $credentials = [];
            for ($unit = 0; $unit < 2; $unit++) {
                $token = Str::random(48);
                $ticket = Ticket::create(['public_id' => (string) Str::ulid(), 'order_item_id' => $item->id, 'event_id' => $event->id, 'ticket_type_id' => $type->id,
                    'token' => $token, 'token_hash' => hash('sha256', $token), 'admit_count' => 4, 'status' => 'valid', 'issued_at' => now()]);
                $credentials[$ticket->id] = $token;
            }
            $this->artisan('migrate', ['--database' => 'phase04_legacy', '--path' => $migration, '--force' => true])->assertExitCode(0);
            $this->assertSame(8, Attendee::count());
            $this->assertSame(0, Attendee::whereNotNull('name')->count());
            $this->assertSame(0, Attendee::whereNotNull('email')->count());
            foreach ($credentials as $id => $token) {
                $ticket = Ticket::findOrFail($id);
                $this->assertSame($token, $ticket->token);
                $this->assertSame(hash('sha256', $token), $ticket->token_hash);
                $this->assertSame(4, $ticket->admit_count);
                $this->assertSame(4, $ticket->attendees()->count());
            }
            $this->assertSame([0, 1], Ticket::orderBy('id')->pluck('unit_index')->all());
            $this->assertSame($item->unit_price_minor, $item->fresh()->unit_price_minor);
            $this->assertSame('Original table', $item->fresh()->ticket_name);
        } finally {
            DB::purge('phase04_legacy');
            DB::setDefaultConnection($original);
        }
    }
}
