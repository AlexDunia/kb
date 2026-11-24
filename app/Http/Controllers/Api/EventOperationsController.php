<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\FreeRegistrationService;
use App\Services\Commerce\RegistrationService;
use App\Services\EventAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventOperationsController extends Controller
{
    public function registrationForm(Request $request, Event $event, RegistrationService $registration)
    {
        abort_unless($event->status === 'active' && $event->lifecycle_state !== 'archived', 404);
        $used = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('order_items.event_id', $event->id)->whereIn('orders.status', ['registered', 'partially_refunded'])->selectRaw('COALESCE(SUM(order_items.quantity - order_items.refunded_quantity),0) AS total')->value('total');

        return response()->json(['data' => ['event_id' => $event->id, 'fields' => $registration->schema($event),
            'capacity' => $event->total_tickets, 'available' => max(0, $event->total_tickets - $used),
            'booking_supported' => $event->ticket_mode === 'free' && $event->event_type !== 'recurring' && $event->bookingsOpen()]]);
    }

    public function register(Request $request, Event $event, FreeRegistrationService $registration, CheckoutService $checkout)
    {
        return response()->json(['data' => $checkout->publicOrderPayload($registration->register($event->id, $request->all(), auth('sanctum')->id()))], 201);
    }

    private function listing(Request $request, Event $event, EventAccessService $access)
    {
        $access->authorize($event, $request->user()->id, 'attendees');
        $input = $request->validate(['q' => 'sometimes|nullable|string|max:100', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $query = Attendee::where('event_id', $event->id)->whereHas('orderItem.order', fn ($q) => $q->whereIn('status', ['paid', 'registered']));
        if (! empty($input['q'])) {
            $pattern = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($input['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern]));
        }

        return [$query, $input];
    }

    public function attendees(Request $request, Event $event, EventAccessService $access)
    {
        [$query, $input] = $this->listing($request, $event, $access);

        return response()->json($query->with(['orderItem:id,order_id,ticket_name,registration_schema', 'ticket:id,public_id,status'])->orderBy('id')->paginate($input['per_page'] ?? 30));
    }

    public function purchases(Request $request, Event $event, EventAccessService $access)
    {
        $access->authorize($event, $request->user()->id, 'attendees');
        $input = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'q' => 'sometimes|nullable|string|max:100']);
        $query = OrderItem::where('event_id', $event->id)->with('order:id,public_id,customer_first_name,customer_last_name,customer_email,status,created_at');
        if (! empty($input['q'])) {
            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($input['q'])).'%';
            $query->whereHas('order', fn ($q) => $q->whereRaw("customer_email LIKE ? ESCAPE '!'", [$prefix])->orWhereRaw("public_id LIKE ? ESCAPE '!'", [$prefix]));
        }

        return response()->json($query->orderByDesc('id')->paginate($input['per_page'] ?? 30));
    }

    public function update(Request $request, Event $event, int $attendee, EventAccessService $access, RegistrationService $registration)
    {
        $input = $request->validate(['version' => 'required|integer|min:1', 'answers' => 'required|array|max:30']);
        $result = DB::transaction(function () use ($request, $event, $attendee, $access, $registration, $input) {
            $access->authorize($event, $request->user()->id, 'attendees');
            $record = Attendee::where('event_id', $event->id)->whereKey($attendee)->lockForUpdate()->firstOrFail();
            abort_unless($record->lock_version === (int) $input['version'], 409, 'This attendee changed. Reload before saving.');
            $schema = $record->orderItem->registration_schema ?? [];
            // Historical unnamed seats may be named without imposing today's form.
            if (! $schema) {
                $schema = [['id' => 'name', 'label' => 'Full name', 'required' => false, 'type' => 'text'], ['id' => 'email', 'label' => 'Email', 'required' => false, 'type' => 'email']];
            }
            $answers = $registration->answers($schema, array_replace($record->answers ?? [], $input['answers']), 'answers');
            $record->update(['name' => $answers['name'] ?? null, 'email' => $answers['email'] ?? null, 'answers' => $answers, 'lock_version' => $record->lock_version + 1]);

            return $record;
        }, 3);

        return response()->json(['data' => $result]);
    }

    public function resend(Request $request, Event $event, string $ticket, EventAccessService $access)
    {
        $access->authorize($event, $request->user()->id, 'attendees');
        $credential = Ticket::where('event_id', $event->id)->where('public_id', $ticket)->with('orderItem.order')->firstOrFail();
        abort_unless($credential->orderItem->order->isFulfilled() && $credential->status === 'valid', 422);
        DB::table('commerce_deliveries')->insertOrIgnore(['order_id' => $credential->orderItem->order_id,
            'deduplication_key' => 'resend:'.$event->id.':'.$credential->id.':'.intdiv(now()->timestamp, 600), 'kind' => 'purchase', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'A secure ticket link has been queued for the original buyer.'], 202);
    }

    public function export(Request $request, Event $event, EventAccessService $access)
    {
        [$query] = $this->listing($request, $event, $access);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Attendee ID', 'Name', 'Email', 'Ticket ID', 'Inside', 'Entries', 'Answers'], ',', '"', '');
            foreach ($query->with('ticket:id,public_id')->orderBy('id')->lazyById(200) as $record) {
                $values = [$record->id, $record->name, $record->email, $record->ticket?->public_id, $record->inside ? 'yes' : 'no', $record->entries, json_encode($record->answers)];
                fputcsv($out, array_map(fn ($value) => preg_match('/^[\s\x{FEFF}]*[=+@\-\t\r\n]/u', (string) $value) ? "'".$value : $value, $values), ',', '"', '');
            }
            fclose($out);
        }, 'event-'.$event->id.'-attendees.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
