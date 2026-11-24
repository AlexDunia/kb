<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = str_replace('\\', '/', (string) config('database.connections.sqlite.database'));
$prefix = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/rushhour-phase03-';
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_starts_with($database, $prefix)) {
    exit(3);
}
$fixtures = new class
{
    use Tests\Support\EventLifecycleFixtures;

    public function create(): array
    {
        $event = $this->lifecycleEvent(App\Models\User::factory()->create(), true);

        return ['ticket_type_id' => $event->ticketTypes()->firstOrFail()->id];
    }
};
echo json_encode($fixtures->create(), JSON_THROW_ON_ERROR);
