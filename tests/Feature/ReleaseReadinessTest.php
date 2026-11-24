<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\EventLifecycleFixtures;
use Tests\TestCase;

class ReleaseReadinessTest extends TestCase
{
    use EventLifecycleFixtures, RefreshDatabase;

    public function test_owner_editor_returns_persisted_lifecycle_and_cutoff_without_exposing_them_to_other_owners(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $event->forceFill(['lifecycle_state' => 'sales_closed', 'booking_cutoff_at' => '2030-06-21 16:00:00'])->save();
        $this->actingAs($owner)->getJson('/api/create-event/'.$event->id)->assertOk()
            ->assertJsonPath('data.lifecycle_state', 'sales_closed')
            ->assertJsonPath('data.booking_cutoff_at', '2030-06-21T16:00:00+00:00')
            ->assertJsonPath('data.version', (int) $event->lock_version);
        $this->actingAs(User::factory()->create())->getJson('/api/create-event/'.$event->id)->assertForbidden();
    }

    public function test_public_discovery_is_bounded_and_records_repeatable_local_kernel_measurements(): void
    {
        $owner = User::factory()->create();
        $event = $this->lifecycleEvent($owner, true);
        $row = $event->getAttributes();
        unset($row['id']);
        for ($i = 0; $i < 120; $i++) {
            $row['title'] = 'Release benchmark '.$i;
            DB::table('events')->insert($row);
        }
        $samples = [];
        $queries = [];
        $bytes = [];
        for ($i = 0; $i < 30; $i++) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $response = $this->getJson('/api/events?per_page=12&q=Release');
            $samples[] = (hrtime(true) - $start) / 1e6;
            $queries[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            $response->assertOk()->assertJsonCount(12, 'data')->assertJsonPath('meta.total', 120);
            $bytes[] = strlen($response->getContent());
        }
        sort($samples);
        $result = [
            'scope' => 'Laravel test-kernel sequential requests; not network load or production capacity',
            'engine' => DB::connection()->getDriverName(), 'catalog_rows' => Event::count(),
            'requests' => count($samples), 'errors' => 0, 'concurrency' => 1,
            'p50_ms' => $samples[14], 'p95_ms' => $samples[28], 'p99_ms' => $samples[29],
            'requests_per_second' => 30000 / array_sum($samples),
            'max_queries' => max($queries), 'max_response_bytes' => max($bytes),
            'process_peak_bytes' => memory_get_peak_usage(true),
        ];
        $this->assertLessThan(40, max($queries), 'Discovery queries must remain bounded for a 12-event page.');
        file_put_contents(storage_path('framework/testing/phase07-discovery.json'), json_encode($result, JSON_PRETTY_PRINT));
    }
}
