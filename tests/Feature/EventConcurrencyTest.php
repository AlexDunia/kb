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
}
