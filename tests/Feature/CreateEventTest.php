<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_can_be_saved_updated_and_published(): void
    {
        DB::table('categories')->insert(['name' => 'Music']);

        $created = $this->postJson('/api/create-event', [
            'title' => 'Test',
            'category' => 'Music',
            'eventType' => 'one_time',
            'ticketMode' => 'free',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $id = $created->json('data.id');

        $this->patchJson("/api/create-event/{$id}", [
            'venue' => 'Eko Hotel',
            'format' => 'in-person',
            'startsAtLocal' => '2026-08-01T18:00:00',
            'timeZone' => 'Africa/Lagos',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Test');

        $this->postJson("/api/create-event/{$id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('errors.coverImage', 'A cover image is required');

        $this->patchJson("/api/create-event/{$id}", [
            'coverImage' => 'https://picsum.photos/800/450',
        ])->assertOk();

        $this->postJson("/api/create-event/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_recurring_data_is_stored_without_transformation(): void
    {
        DB::table('categories')->insert(['name' => 'Music']);
        $recurrence = [
            'frequency' => 'weekly',
            'weeklyDays' => [0, 3, 6],
            'monthlyMode' => 'day_of_month',
            'dayOfMonth' => 21,
            'seriesEndType' => 'on_date',
            'seriesEndDate' => '2026-08-30',
            'occurrenceCount' => null,
        ];

        $response = $this->postJson('/api/create-event', [
            'title' => 'Weekly music',
            'category' => 'Music',
            'eventType' => 'recurring',
            'startsAtLocal' => '2026-06-21T12:00:00',
            'endsAtLocal' => '2026-06-21T22:00:00',
            'timeZone' => 'Africa/Lagos',
            'ticketMode' => 'free',
            'recurrence' => $recurrence,
        ])->assertCreated();

        $event = Event::findOrFail($response->json('data.id'));
        $this->assertSame($recurrence, json_decode($event->recurrence_data, true));
    }
}
