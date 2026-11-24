<?php

namespace App\Jobs;

use App\Services\EventMessagingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildEventMessageAudience implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $messageId) {}

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public function handle(EventMessagingService $service): void
    {
        $service->build($this->messageId);
        $service->complete($this->messageId);
    }
}
