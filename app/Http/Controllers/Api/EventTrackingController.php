<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Dashboard\EventAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventTrackingController extends Controller
{
    public function store(
        Request $request,
        Event $event,
        EventAttributionService $attribution
    ): JsonResponse {
        // Tracking a draft/private event is not useful and would leak that the
        // event exists through analytics side effects.
        if (($event->status ?? 'draft') !== 'active') {
            return response()->json(['ok' => true]);
        }

        $validated = $request->validate([
            'visitor_id' => [
                'required',
                'uuid',
            ],
            'source_code' => [
                'nullable',
                'string',
                'max:32',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],
        ]);

        $attribution->recordView(
            $event,
            auth('sanctum')->id(),
            $validated['visitor_id'],
            $validated['source_code'] ?? null,
            $request->ip(),
            $request->userAgent()
        );

        return response()->json(['ok' => true]);
    }
}
