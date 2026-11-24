<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\AdmissionService;
use App\Services\EventAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdmissionController extends Controller
{
    public function context(Request $request, Event $event, EventAccessService $access)
    {
        $role = $access->authorize($event, $request->user()->id, 'admission');
        $scope = $access->entranceScope($event, $request->user()->id);

        return response()->json(['data' => ['event' => $event->only(['id', 'title', 'date', 'ends_at', 'event_timezone', 'allow_reentry', 'lifecycle_state']), 'role' => $role,
            'entrances' => DB::table('event_entrances')->where('event_id', $event->id)->when($scope, fn ($q) => $q->where('id', $scope))->orderBy('id')->get(['id', 'name', 'active', 'opens_at', 'closes_at'])->map(fn ($row) => \App\Support\UtcDates::row($row))]]);
    }

    public function lookup(Request $request, Event $event, AdmissionService $admission)
    {
        $input = $request->validate(['token' => 'required|string|min:20|max:256', 'entrance_id' => 'required|integer', 'direction' => 'sometimes|in:in,out']);

        return response()->json(['data' => $admission->lookup($event, $request->user()->id, $input)]);
    }

    public function move(Request $request, Event $event, AdmissionService $admission)
    {
        $input = $request->validate(['token' => 'required|string|min:20|max:256', 'entrance_id' => 'required|integer', 'direction' => 'required|in:in,out', 'count' => 'required|integer|min:1|max:500', 'request_key' => 'required|uuid']);

        return response()->json(['data' => $admission->move($event, $request->user()->id, $input)]);
    }

    public function history(Request $request, Event $event, EventAccessService $access)
    {
        $access->authorize($event, $request->user()->id, 'admission');
        $input = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'ticket_public_id' => 'sometimes|string|size:26']);
        $scope = $access->entranceScope($event, $request->user()->id);
        $query = DB::table('admission_movements as m')->join('tickets as t', 't.id', '=', 'm.ticket_id')->join('event_entrances as e', 'e.id', '=', 'm.entrance_id')
            ->where('m.event_id', $event->id)->when($scope, fn ($q) => $q->where('m.entrance_id', $scope))
            ->when(isset($input['ticket_public_id']), fn ($q) => $q->where('t.public_id', $input['ticket_public_id']))
            ->select(['m.id', 't.public_id as ticket_public_id', 'm.entrance_id', 'e.name as entrance_name', 'm.staff_user_id', 'm.direction', 'm.count', 'm.occurred_at'])->orderByDesc('m.id');

        return response()->json($query->paginate($input['per_page'] ?? 30)->through(fn ($row) => \App\Support\UtcDates::row($row)));
    }
}
