<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\CreateEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CreateEventController extends Controller
{
    public function store(Request $request, CreateEventService $service): JsonResponse
    {
        $event = $service->saveEvent($request->all(), auth('sanctum')->id());

        return response()->json(['data' => $this->summary($event, $service), 'message' => 'Draft saved'], 201);
    }

    public function showForEditing(int $id, CreateEventService $service): JsonResponse
    {
        $event = Event::findOrFail($id);
        Gate::authorize('update', $event);
        abort_unless(in_array($event->status, ['draft', 'active'], true), 422, 'This event cannot be edited in its current state.');

        return response()->json(['data' => $this->summary($event, $service)]);
    }

    public function update(Request $request, int $id, CreateEventService $service): JsonResponse
    {
        Gate::authorize('update', Event::findOrFail($id));
        $event = $service->saveEvent($request->all(), auth('sanctum')->id(), $id);

        return response()->json(['data' => $this->summary($event, $service), 'message' => $event->status === 'active' ? 'Event updated' : 'Draft updated']);
    }

    public function publish(Request $request, int $id, CreateEventService $service): JsonResponse
    {
        Gate::authorize('update', Event::findOrFail($id));
        $event = $service->saveEvent(['version' => $request->input('version')], auth('sanctum')->id(), $id, true);

        return response()->json(['data' => $this->summary($event, $service), 'message' => 'Event published successfully']);
    }

    private function summary(Event $event, CreateEventService $service): array
    {
        return ['id' => $event->id, 'status' => $event->status, 'title' => $event->title, 'version' => (int) $event->lock_version, 'form' => $service->editorPayload($event)];
    }
}
