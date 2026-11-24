<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Dashboard\DashboardAnalyticsService;
use App\Services\Dashboard\EventAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function events(
        Request $request,
        DashboardAnalyticsService $analytics
    ): JsonResponse {
        $v = $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $events = Event::query()
            ->where('created_by', $request->user()->id)
            ->with('address')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('date')
            ->orderBy('id')
            ->paginate($v['per_page'] ?? 30);
        $events->through(
            fn (Event $event) => $analytics->eventListItem($event)
        );

        return response()->json($events);
    }

    public function overview(
        Request $request,
        Event $event,
        DashboardAnalyticsService $analytics
    ): JsonResponse {
        Gate::authorize('update', $event);

        return response()->json([
            'data' => $analytics->overview($event),
        ]);
    }

    public function shareLinks(
        Request $request,
        Event $event,
        DashboardAnalyticsService $analytics
    ): JsonResponse {
        Gate::authorize('update', $event);

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
        Gate::authorize('update', $event);

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
}
