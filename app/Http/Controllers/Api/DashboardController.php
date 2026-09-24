<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Dashboard\DashboardAnalyticsService;
use App\Services\Dashboard\EventAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function events(
        Request $request,
        DashboardAnalyticsService $analytics
    ): JsonResponse {
        $events = Event::query()
            ->where('created_by', $request->user()->id)
            ->with('address')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('date')
            ->get()
            ->map(
                fn (Event $event) =>
                    $analytics->eventListItem($event)
            )
            ->values();

        return response()->json([
            'data' => $events,
        ]);
    }

    public function overview(
        Request $request,
        Event $event,
        DashboardAnalyticsService $analytics
    ): JsonResponse {
        $this->authorizeOwner($request, $event);

        return response()->json([
            'data' => $analytics->overview($event),
        ]);
    }

    public function shareLinks(
        Request $request,
        Event $event,
        DashboardAnalyticsService $analytics
    ): JsonResponse {
        $this->authorizeOwner($request, $event);

        return response()->json([
            'data' => $analytics->shareLinks($event),
        ]);
    }

    public function createShareLink(
        Request $request,
        Event $event,
        EventAttributionService $attribution,
        DashboardAnalyticsService $analytics
    ): JsonResponse {
        $this->authorizeOwner($request, $event);

        $validated = $request->validate([
            'label' => [
                'required',
                'string',
                'min:2',
                'max:60',
            ],
        ]);

        $attribution->createCustomLink(
            $event,
            $validated['label']
        );

        return response()->json([
            'data' => $analytics->shareLinks($event),
        ], 201);
    }

    private function authorizeOwner(
        Request $request,
        Event $event
    ): void {
        abort_unless(
            (int) $event->created_by === (int) $request->user()->id,
            403,
            'You do not have access to this event dashboard.'
        );
    }
}
