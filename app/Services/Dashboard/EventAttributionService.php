<?php

namespace App\Services\Dashboard;

use App\Models\Event;
use App\Models\EventShareLink;
use App\Models\EventTrafficView;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventAttributionService
{
    public const ATTRIBUTION_DAYS = 30;

    /**
     * Keep the built-in source codes short and stable because they become part
     * of public URLs and are persisted against orders.
     *
     * @return array<int, array{label:string,source_code:string}>
     */
    public function defaultLinks(): array
    {
        return [
            ['label' => 'WhatsApp', 'source_code' => 'wa'],
            ['label' => 'Instagram', 'source_code' => 'ig'],
            ['label' => 'Direct link', 'source_code' => 'direct'],
            ['label' => 'X / Twitter', 'source_code' => 'x'],
            ['label' => 'Facebook', 'source_code' => 'fb'],
            ['label' => 'LinkedIn', 'source_code' => 'in'],
            ['label' => 'Email', 'source_code' => 'email'],
        ];
    }

    public function hashVisitor(string $visitorId): string
    {
        return hash('sha256', strtolower(trim($visitorId)));
    }

    public function ensureDefaultLinks(Event $event): Collection
    {
        foreach ($this->defaultLinks() as $link) {
            EventShareLink::query()->firstOrCreate(
                [
                    'event_id' => $event->id,
                    'source_code' => $link['source_code'],
                ],
                [
                    'label' => $link['label'],
                    'is_system' => true,
                ]
            );
        }

        return EventShareLink::query()
            ->where('event_id', $event->id)
            ->orderByDesc('is_system')
            ->orderBy('id')
            ->get();
    }

    public function createCustomLink(Event $event, string $label): EventShareLink
    {
        $cleanLabel = trim(strip_tags($label));

        if ($cleanLabel === '') {
            throw ValidationException::withMessages([
                'label' => ['Enter a name for this tracking link.'],
            ]);
        }

        $base = Str::slug($cleanLabel, '-');
        $base = substr($base, 0, 24);
        $base = $base !== '' ? $base : 'link';

        $sourceCode = $base;
        $attempt = 0;

        while (
            EventShareLink::query()
                ->where('event_id', $event->id)
                ->where('source_code', $sourceCode)
                ->exists()
        ) {
            $attempt++;
            $suffix = strtolower(Str::random(4));
            $sourceCode = substr($base, 0, 27) . '-' . $suffix;

            if ($attempt >= 10) {
                $sourceCode = 'link-' . strtolower(Str::random(10));
                break;
            }
        }

        return EventShareLink::query()->create([
            'event_id' => $event->id,
            'label' => $cleanLabel,
            'source_code' => $sourceCode,
            'is_system' => false,
        ]);
    }

    public function recordView(
        Event $event,
        ?int $userId,
        string $visitorId,
        ?string $sourceCode,
        ?string $ipAddress,
        ?string $userAgent
    ): EventTrafficView {
        $visitorHash = $this->hashVisitor($visitorId);
        $sourceCode = $this->validatedSourceCode($event, $sourceCode);
        $now = now();
        $bucket = $now->copy()->startOfHour();

        $fingerprintKey = (string) (config('app.key') ?: 'kakatickets');

        $view = EventTrafficView::query()->firstOrCreate(
            [
                'event_id' => $event->id,
                'visitor_hash' => $visitorHash,
                'view_bucket' => $bucket,
            ],
            [
                'user_id' => $userId,
                'source_code' => $sourceCode,
                'viewed_at' => $now,
                'ip_hash' => $ipAddress
                    ? hash_hmac('sha256', $ipAddress, $fingerprintKey)
                    : null,
                'user_agent_hash' => $userAgent
                    ? hash_hmac('sha256', $userAgent, $fingerprintKey)
                    : null,
            ]
        );

        $changes = [];

        if ($userId !== null && $view->user_id === null) {
            $changes['user_id'] = $userId;
        }

        // If somebody first lands directly and then uses a tagged link in the
        // same hour, keep the useful tagged touch rather than the empty value.
        if ($sourceCode !== null && $view->source_code !== $sourceCode) {
            $changes['source_code'] = $sourceCode;
            $changes['viewed_at'] = $now;
        }

        if ($changes !== []) {
            $view->forceFill($changes)->save();
        }

        return $view->fresh();
    }

    /**
     * Last tagged touch, per event, during the attribution window.
     *
     * @param array<int, int> $eventIds
     * @return array<int, array{view_id:int,source_code:string}>
     */
    public function latestAttributionForVisitor(
        string $visitorId,
        array $eventIds
    ): array {
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));

        if ($eventIds === []) {
            return [];
        }

        $visitorHash = $this->hashVisitor($visitorId);

        $views = EventTrafficView::query()
            ->where('visitor_hash', $visitorHash)
            ->whereIn('event_id', $eventIds)
            ->whereNotNull('source_code')
            ->where('viewed_at', '>=', now()->subDays(self::ATTRIBUTION_DAYS))
            ->orderByDesc('viewed_at')
            ->get(['id', 'event_id', 'source_code']);

        $result = [];

        foreach ($views as $view) {
            $eventId = (int) $view->event_id;

            if (isset($result[$eventId])) {
                continue;
            }

            $result[$eventId] = [
                'view_id' => (int) $view->id,
                'source_code' => (string) $view->source_code,
            ];
        }

        return $result;
    }

    private function validatedSourceCode(
        Event $event,
        ?string $sourceCode
    ): ?string {
        if (! is_string($sourceCode)) {
            return null;
        }

        $sourceCode = strtolower(trim($sourceCode));

        if (
            $sourceCode === ''
            || ! preg_match('/^[a-z0-9_-]{1,32}$/', $sourceCode)
        ) {
            return null;
        }

        $default = collect($this->defaultLinks())
            ->firstWhere('source_code', $sourceCode);

        if ($default) {
            EventShareLink::query()->firstOrCreate(
                [
                    'event_id' => $event->id,
                    'source_code' => $sourceCode,
                ],
                [
                    'label' => $default['label'],
                    'is_system' => true,
                ]
            );

            return $sourceCode;
        }

        return EventShareLink::query()
            ->where('event_id', $event->id)
            ->where('source_code', $sourceCode)
            ->exists()
                ? $sourceCode
                : null;
    }
}
