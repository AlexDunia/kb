<?php

namespace App\Services\Commerce;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RegistrationService
{
    public function schema(Event $event): array
    {
        $fields = json_decode($event->attendee_fields ?? '[]', true) ?: [];

        return collect($fields)->filter(fn ($field) => ($field['enabled'] ?? false) || ($field['required'] ?? false))
            ->map(fn ($field) => ['id' => $field['id'], 'label' => $field['label'], 'required' => (bool) ($field['required'] ?? false), 'type' => $field['id'] === 'email' ? 'email' : 'text'])->values()->all();
    }

    public function answers(array $schema, array $answers, string $prefix): array
    {
        $answers = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $answers);
        $rules = [];
        foreach ($schema as $field) {
            $id = $field['id'];
            $rules[$id] = [$field['required'] ? 'required' : 'nullable', 'string', 'max:'.($id === 'email' ? 255 : ($id === 'name' ? 200 : 2000))];
            if ($field['type'] === 'email') {
                $rules[$id][] = 'email:rfc';
            }
        }
        if (array_diff(array_keys($answers), array_keys($rules))) {
            throw ValidationException::withMessages([$prefix => ['An answer does not belong to this registration form.']]);
        }
        $validator = Validator::make($answers, $rules);
        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->errors()->messages() as $key => $errors) {
                $messages[$prefix.'.'.$key] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }

        return collect($validator->validated())->map(fn ($value) => is_string($value) ? trim($value) : $value)->all();
    }

    public function attach(Order $order, array $supplied): void
    {
        $seats = [];
        foreach ($supplied as $index => $attendee) {
            $key = $attendee['ticket_type_id'].':'.$attendee['unit_index'].':'.$attendee['seat_index'];
            if (isset($seats[$key])) {
                throw ValidationException::withMessages(["attendees.$index" => ['Duplicate admission seat.']]);
            }
            $seats[$key] = ['answers' => $attendee['answers'] ?? [], 'index' => $index];
        }
        $total = 0;
        foreach ($order->items()->with('event')->get() as $item) {
            $total += $item->quantity * $item->people_per_unit;
            if ($total > 500) {
                throw ValidationException::withMessages(['attendees' => ['One checkout can register at most 500 attendees.']]);
            }
            $schema = $this->schema($item->event);
            $item->update(['registration_schema' => $schema]);
            $rows = [];
            for ($unit = 0; $unit < $item->quantity; $unit++) {
                for ($seat = 0; $seat < $item->people_per_unit; $seat++) {
                    $key = $item->ticket_type_id.':'.$unit.':'.$seat;
                    $record = $seats[$key] ?? ['answers' => [], 'index' => $key];
                    $answers = $this->answers($schema, $record['answers'], 'attendees.'.$record['index'].'.answers');
                    $rows[] = ['event_id' => $item->event_id, 'order_item_id' => $item->id, 'unit_index' => $unit, 'seat_index' => $seat,
                        'name' => $answers['name'] ?? null, 'email' => $answers['email'] ?? null, 'answers' => json_encode($answers, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()];
                    unset($seats[$key]);
                }
            }
            foreach (array_chunk($rows, 100) as $batch) {
                DB::table('attendees')->insert($batch);
            }
        }
        if ($seats) {
            throw ValidationException::withMessages(['attendees' => ['An attendee refers to a foreign ticket or unavailable admission seat.']]);
        }
    }

    public function link(Ticket $ticket, int $unit): void
    {
        $ticket->update(['unit_index' => $unit]);
        $existing = array_fill_keys(Attendee::where('order_item_id', $ticket->order_item_id)->where('unit_index', $unit)->pluck('seat_index')->all(), true);
        $missing = [];
        for ($seat = 0; $seat < $ticket->admit_count; $seat++) {
            if (! isset($existing[$seat])) {
                $missing[] = ['order_item_id' => $ticket->order_item_id, 'unit_index' => $unit, 'seat_index' => $seat,
                    'event_id' => $ticket->event_id, 'ticket_id' => $ticket->id, 'answers' => '{}', 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($missing, 100) as $batch) {
            DB::table('attendees')->insertOrIgnore($batch);
        }
        Attendee::where('order_item_id', $ticket->order_item_id)->where('unit_index', $unit)->update(['ticket_id' => $ticket->id]);
    }
}
