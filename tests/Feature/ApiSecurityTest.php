<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventLike;
use App\Models\EventPublicProfile;
use App\Models\User;
use App\Services\CreateEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function event(User $owner, string $status = 'draft'): Event
    {
        DB::table('categories')->insertOrIgnore(['name' => 'Music']);
        $event = app(CreateEventService::class)->saveEvent([
            'title' => 'Protected event', 'category' => 'Music',
            'format' => 'online', 'meetingLink' => 'https://private.example/secret',
            'extraDetails' => [
                ['type' => 'agenda', 'label' => 'Agenda', 'value' => '6 PM Doors'],
            ],
        ], $owner->id);
        $event->forceFill(['status' => $status])->save();

        return $event;
    }

    public function test_public_routes_only_expose_active_events_even_to_owners(): void
    {
        $owner = User::factory()->create();
        $active = $this->event($owner, 'active');
        foreach (['draft', 'cancelled', 'completed'] as $status) {
            $event = $this->event($owner, $status);
            $this->getJson("/api/events/{$event->id}")->assertNotFound();
        }
        $this->getJson('/api/events')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $draft = $this->event($owner);
        $this->actingAs($owner)->getJson("/api/events/{$draft->id}")->assertNotFound();
        $this->getJson("/api/create-event/{$draft->id}")->assertOk()
            ->assertJsonPath('data.form.meetingLink', 'https://private.example/secret');
    }

    public function test_editor_and_dashboard_permissions_isolate_owners(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $this->getJson("/api/create-event/{$event->id}")->assertUnauthorized();
        $this->getJson('/api/dashboard/events')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->getJson("/api/create-event/{$event->id}")->assertForbidden();
        $this->patchJson("/api/create-event/{$event->id}", ['title' => 'Intrusion'])->assertForbidden();
        $this->postJson("/api/create-event/{$event->id}/publish")->assertForbidden();
        $this->getJson("/api/dashboard/events/{$event->id}/overview")->assertForbidden();
        $this->getJson("/api/dashboard/events/{$event->id}/share-links")->assertForbidden();
        $this->postJson("/api/dashboard/events/{$event->id}/share-links", ['label' => 'Other'])->assertForbidden();
        $this->assertSame('Protected event', $event->fresh()->title);
        $this->assertSame('draft', $event->fresh()->status);
        $this->actingAs($owner)->patchJson("/api/create-event/{$event->id}", ['title' => 'Owner edit', 'version' => 1])->assertOk();
        $this->getJson('/api/dashboard/events')->assertJsonPath('data.0.id', $event->id);
    }

    public function test_public_serializers_protect_fields_in_details_lists_likes_and_related_events(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner, 'active');
        $event->forceFill(['extra_details' => json_encode([['type' => 'agenda', 'value' => '6 PM Doors', 'internal' => 'secret'], ['type' => 'internal', 'value' => 'private configuration']])])->save();
        $draft = $this->event($owner);
        $related = $this->event($owner, 'active');
        $event->organizer->update(['email' => 'private@example.com']);
        $event->ticketTypes()->create(['name' => 'Hidden ticket', 'price' => 5, 'quantity' => 5, 'visible' => false]);
        EventPublicProfile::create(['event_id' => $event->id, 'related_event_ids' => [$draft->id, $related->id]]);
        $response = $this->getJson("/api/events/{$event->id}")->assertOk()
            ->assertJsonCount(0, 'data.ticket_types')
            ->assertJsonCount(1, 'data.related_events')
            ->assertJsonPath('data.related_events.0.id', $related->id)
            ->assertJsonPath('data.extra_details.0.type', 'agenda');
        foreach (['meeting_link', 'attendee_fields', 'recurrence_data', 'created_by_user', 'organizer.email', 'public_profile.related_event_ids', 'extra_details.0.internal'] as $field) {
            $response->assertJsonMissingPath('data.'.$field);
        }
        $this->assertStringNotContainsString('private.example', $response->getContent());
        $this->assertStringNotContainsString('private configuration', $response->getContent());
        $list = $this->getJson('/api/events')->assertOk();
        foreach ($list->json('data') as $item) {
            $this->assertArrayNotHasKey('meeting_link', $item);
            $this->assertArrayNotHasKey('email', $item['organizer']);
        }
        $this->getJson('/api/user/liked-events')->assertUnauthorized();
        $this->actingAs($owner);
        foreach ([$event, $draft] as $liked) {
            EventLike::create(['user_id' => $owner->id, 'event_id' => $liked->id]);
        }
        $this->getJson('/api/user/liked-events')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.meeting_link')->assertJsonCount(0, 'data.0.ticket_types');
        $this->postJson("/api/events/{$draft->id}/toggle-like")->assertNotFound();
        $this->getJson("/api/events/{$draft->id}/check-liked")->assertNotFound();
        $this->postJson("/api/events/{$event->id}/toggle-like")->assertOk()->assertJsonPath('liked', false);
        $this->postJson("/api/events/{$event->id}/toggle-like")->assertOk()->assertJsonPath('liked', true);
        $this->getJson("/api/events/{$event->id}/check-liked")->assertOk()->assertJsonPath('liked', true);
    }

    public function test_legacy_mutations_are_explicitly_retired_without_writing_records(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $this->actingAs($owner)->postJson('/api/events', ['title' => 'Legacy'])->assertStatus(410);
        $this->putJson("/api/events/{$event->id}", ['title' => 'Legacy'])->assertStatus(410);
        $this->deleteJson("/api/events/{$event->id}")->assertStatus(410);
        $this->assertDatabaseCount('events', 1);
        $this->assertSame('Protected event', $event->fresh()->title);
    }

    public function test_api_errors_are_json_without_accept_header_and_internal_details_are_hidden(): void
    {
        $this->get('/api/user')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->get('/api/events/999999')->assertNotFound()->assertJsonPath('message', 'Resource not found.');
        $this->getJson('/api/events?per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->actingAs(User::factory()->create())->postJson('/api/create-event', ['title' => ['invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        Route::get('/api/phase01-error-test', fn () => throw new \RuntimeException('private SQL password'));
        config(['app.debug' => true]);
        $this->get('/api/phase01-error-test')->assertStatus(500)
            ->assertExactJson(['message' => 'Server error. Please try again later.']);
    }

    public function test_dashboard_named_limiter_is_not_capped_by_generic_api_limiter(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/dashboard/events')->assertOk();
        }
        $this->getJson('/api/dashboard/events')->assertStatus(429)->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many requests. Please try again later.');
    }
}
