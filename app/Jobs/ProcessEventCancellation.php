<?php

namespace App\Jobs;

use App\Services\EventLifecycleService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessEventCancellation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $workId) {}

    public function uniqueId(): string
    {
        return (string) $this->workId;
    }

    public function handle(EventLifecycleService $service): void
    {
        if (! DB::table('event_cancellation_work')->where('id', $this->workId)->where('finished', false)->where('next_attempt_at', '<=', now())->update(['next_attempt_at' => now()->addMinutes(5)])) {
            return;
        }
        try {
            $service->cancelOrders($this->workId);
            DB::table('event_cancellation_work')->where('id', $this->workId)->update(['next_attempt_at' => now(), 'last_error' => null]);
        } catch (\Throwable $e) {
            DB::table('event_cancellation_work')->where('id', $this->workId)->update(['last_error' => 'Cancellation processing failed: '.class_basename($e), 'next_attempt_at' => now()->addMinutes(5)]);
        }
    }
}
