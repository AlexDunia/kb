<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventPublicProfile;
use App\Models\User;
use App\Services\CreateEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventPublicProfileTest extends TestCase
{
    use RefreshDatabase;

    private function publicEvent(): Event
    {
        DB::table('categories')->insert(['name' => 'Music']);
        $user = User::factory()->create();
        $event = app(CreateEventService::class)->saveEvent([
            'title' => 'Database event', 'description' => 'Event description.',
            'category' => 'Music', 'organiser' => 'Event team', 'venue' => 'Lagos',
            'ticketMode' => 'free', 'freeCapacity' => 20,
        ], $user->id);
        $event->forceFill(['status' => 'active'])->save();

        return $event;
    }

    public function test_database_owned_profile_content_is_returned_without_changing_the_event(): void
    {
        $event = $this->publicEvent();
        EventPublicProfile::create([
            'event_id' => $event->id,
            'summary' => 'A short introduction.',
            'caption_title' => 'Join us for an evening together.',
            'guests' => [['id' => 'ada', 'name' => 'Ada', 'role' => 'Host', 'bio' => 'Event host.']],
            'schedule' => [['time' => '6 PM', 'title' => 'Doors open', 'description' => 'Welcome.']],
            'moments' => [['url' => '/storage/events/photo.jpg', 'caption' => 'On stage']],
            'faqs' => [['question' => 'When?', 'answer' => 'At 6 PM.']],
            'crew' => [['name' => 'Tobi', 'role' => 'Producer']],
        ]);
        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Database event')
            ->assertJsonPath('data.public_profile.guests.0.name', 'Ada')
            ->assertJsonPath('data.public_profile.schedule.0.title', 'Doors open')
            ->assertJsonPath('data.public_profile.moments.0.caption', 'On stage')
            ->assertJsonPath('data.public_profile.faqs.0.answer', 'At 6 PM.')
            ->assertJsonPath('data.public_profile.crew.0.role', 'Producer');
        $this->assertDatabaseCount('event_public_profiles', 1);
        $this->assertSame('Database event', $event->fresh()->title);
    }

    public function test_reading_an_event_does_not_create_or_seed_a_profile(): void
    {
        $event = $this->publicEvent();
        $this->getJson("/api/events/{$event->id}")->assertOk()->assertJsonPath('data.public_profile', null);
        $this->assertDatabaseCount('event_public_profiles', 0);
    }

    public function test_public_ticket_counts_use_checkout_inventory_and_keep_capacity_unchanged(): void
    {
        $event = $this->publicEvent();
        $ticket = $event->ticketTypes()->create(['name' => 'Early bird', 'price' => 8000, 'quantity' => 10, 'visible' => true]);
        $this->mock(\App\Services\Commerce\InventoryService::class, function ($mock) use ($ticket) {
            $mock->shouldReceive('commitments')->once()->with([$ticket->id])->andReturn([$ticket->id => ['sold_units' => 1, 'reserved_units' => 2]]);
            $mock->shouldReceive('availableQuantity')->once()->with(10, 3)->andReturn(7);
        });
        $this->getJson("/api/events/{$event->id}")->assertOk()
            ->assertJsonPath('data.ticket_types.0.available_quantity', 7)
            ->assertJsonPath('data.ticket_types.0.quantity', 10);
        $this->assertSame(10, $ticket->fresh()->quantity);
    }
}
