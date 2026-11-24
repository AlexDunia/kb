<?php

namespace App\Services;

use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EventEditorValidation
{
    public function validate(array $payload, bool $published = false): array
    {
        $payload = Validator::make($payload, $this->rules())->validate();
        foreach (['timeZone' => 'Africa/Lagos', 'eventType' => 'one_time', 'format' => 'in-person', 'ticketMode' => 'free'] as $key => $value) {
            $payload[$key] = $payload[$key] ?? $value;
        }
        $errors = [];
        $zone = $payload['timeZone'];
        $start = $this->instant($payload['startsAtLocal'] ?? null, $payload['startsAt'] ?? null, $zone, 'startsAtLocal', $errors);
        $end = $this->instant($payload['endsAtLocal'] ?? null, $payload['endsAt'] ?? null, $zone, 'endsAtLocal', $errors);
        if ($start && $end && $end <= $start) {
            $errors['endsAtLocal'] = ['End time must be after start time.'];
        }
        $payload['startsAt'] = $start?->toIso8601String();
        $payload['endsAt'] = $end?->toIso8601String();
        $admissions = 0;
        $payload['tickets'] = $payload['tickets'] ?? [];
        foreach ($payload['tickets'] as $i => &$ticket) {
            $ticket['unitType'] = $ticket['unitType'] ?? 'individual';
            $ticket['peoplePerUnit'] = $ticket['peoplePerUnit'] ?? 1;
            if ($ticket['unitType'] === 'individual' && (int) $ticket['peoplePerUnit'] !== 1) {
                $errors["tickets.$i.peoplePerUnit"] = ['Individual tickets admit one person per unit.'];
            }
            $admissions += (int) ($ticket['units'] ?? 0) * (int) $ticket['peoplePerUnit'];
            $salesStart = $this->instant($ticket['salesStartLocal'] ?? null, $ticket['salesStart'] ?? null, $zone, "tickets.$i.salesStartLocal", $errors);
            $salesEnd = $this->instant($ticket['salesEndLocal'] ?? null, $ticket['salesEnd'] ?? null, $zone, "tickets.$i.salesEndLocal", $errors);
            if ($salesStart && $salesEnd && $salesEnd <= $salesStart) {
                $errors["tickets.$i.salesEndLocal"] = ['Sales end must be after sales start.'];
            }
            if ($salesEnd && $end && $salesEnd > $end) {
                $errors["tickets.$i.salesEndLocal"] = ['Sales cannot end after the event ends.'];
            }
            $ticket['salesStart'] = $salesStart?->toIso8601String();
            $ticket['salesEnd'] = $salesEnd?->toIso8601String();
        }
        unset($ticket);
        if ($admissions > 2147483647) {
            $errors['tickets'] = ['Total admission capacity exceeds the supported limit.'];
        }
        if ($published) {
            foreach (['title' => 'Event name is required', 'category' => 'Category is required', 'coverImage' => 'A cover image is required'] as $field => $message) {
                if (trim((string) ($payload[$field] ?? '')) === '') {
                    $errors[$field] = [$message];
                }
            }
            if (! $start) {
                $errors['startsAtLocal'] = ['Start date and time is required.'];
            }
            if (! $end) {
                $errors['endsAtLocal'] = ['End date and time is required.'];
            }
            if (in_array($payload['format'], ['in-person', 'hybrid'], true) && empty(trim($payload['venue'] ?? ''))) {
                $errors['venue'] = ['Venue is required.'];
            }
            if (in_array($payload['format'], ['online', 'hybrid'], true) && empty($payload['meetingLink'])) {
                $errors['meetingLink'] = ['Meeting link is required.'];
            }
            if ($payload['ticketMode'] === 'paid') {
                if (empty($payload['tickets'])) {
                    $errors['tickets'] = ['At least one ticket product is required.'];
                }
                foreach ($payload['tickets'] ?? [] as $i => $ticket) {
                    if (empty(trim($ticket['name'] ?? ''))) {
                        $errors["tickets.$i.name"] = ['Ticket name is required.'];
                    }
                    if ((float) ($ticket['price'] ?? 0) <= 0) {
                        $errors["tickets.$i.price"] = ['Paid ticket prices must be greater than zero.'];
                    }
                }
            }
            if ($payload['eventType'] === 'recurring') {
                $recurrence = $payload['recurrence'] ?? [];
                if (empty($recurrence['frequency'])) {
                    $errors['recurrence.frequency'] = ['Recurrence frequency is required.'];
                }
                if (($recurrence['frequency'] ?? '') === 'weekly' && empty($recurrence['weeklyDays'])) {
                    $errors['recurrence.weeklyDays'] = ['Select at least one weekday.'];
                }
                if (($recurrence['frequency'] ?? '') === 'monthly') {
                    $field = ($recurrence['monthlyMode'] ?? '') === 'nth_weekday' ? 'nthWeekday' : 'dayOfMonth';
                    if (empty($recurrence[$field])) {
                        $errors['recurrence.'.$field] = ['A monthly recurrence rule is required.'];
                    }
                    if (! in_array($recurrence['monthlyMode'] ?? null, ['day_of_month', 'nth_weekday'], true)) {
                        $errors['recurrence.monthlyMode'] = ['Select a monthly recurrence mode.'];
                    }
                    if ($field === 'nthWeekday' && (! isset($recurrence['nthWeekday']['ordinal']) || ! isset($recurrence['nthWeekday']['weekday']))) {
                        $errors['recurrence.nthWeekday'] = ['Select both the ordinal and weekday.'];
                    }
                }
                $endType = $recurrence['seriesEndType'] ?? '';
                if (! in_array($endType, ['on_date', 'after_occurrences'], true)) {
                    $errors['recurrence.seriesEndType'] = ['A series end rule is required.'];
                }
                if ($endType === 'on_date' && empty($recurrence['seriesEndDate'])) {
                    $errors['recurrence.seriesEndDate'] = ['Series end date is required.'];
                }
                if ($endType === 'on_date' && $start && ! empty($recurrence['seriesEndDate']) && $recurrence['seriesEndDate'] < $start->setTimezone($zone)->format('Y-m-d')) {
                    $errors['recurrence.seriesEndDate'] = ['Series end cannot precede its start.'];
                }
                if ($endType === 'after_occurrences' && empty($recurrence['occurrenceCount'])) {
                    $errors['recurrence.occurrenceCount'] = ['Occurrence count is required.'];
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $payload;
    }

    public function supplied(array $payload): void
    {
        Validator::make($payload, $this->rules())->validate();
    }

    private function rules(): array
    {
        $rules = [
            'title' => 'nullable|string|max:100', 'description' => 'nullable|string|max:20000',
            'version' => 'sometimes|integer|min:1', 'ticketCollectionMode' => 'nullable|in:merge,replace',
            'timeZone' => 'nullable|timezone:all|max:60',
            'startsAt' => 'nullable|date', 'endsAt' => 'nullable|date',
            'startsAtLocal' => 'nullable|date_format:Y-m-d\TH:i:s', 'endsAtLocal' => 'nullable|date_format:Y-m-d\TH:i:s',
            'eventType' => 'nullable|in:one_time,recurring', 'format' => 'nullable|in:in-person,online,hybrid',
            'venue' => 'nullable|string|max:255', 'city' => 'nullable|string|max:255',
            'meetingLink' => 'nullable|url:http,https|max:1000', 'organiserWebsite' => 'nullable|url:http,https|max:500',
            'category' => ['nullable', 'string', 'max:100', function ($attribute, $value, $fail) {
                if (trim($value) !== '' && ! Category::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($value))])->exists()) {
                    $fail('Select an existing category.');
                }
            }],
            'organiser' => 'nullable|string|max:100', 'tags' => 'nullable|string|max:500',
            'coverImage' => 'nullable|string|max:7000000', 'secondaryImages' => 'nullable|array|max:20',
            'secondaryImages.*' => 'required|string|max:7000000',
            'ticketMode' => 'nullable|in:free,paid', 'freeCapacity' => 'nullable|integer|min:0|max:1000000',
            'tickets' => 'sometimes|array|max:100', 'tickets.*' => 'array',
            'tickets.*.id' => 'nullable|integer|min:1|distinct', 'tickets.*.name' => 'nullable|string|max:50',
            'tickets.*.unitType' => 'nullable|in:individual,table', 'tickets.*.color' => 'nullable|regex:/^#[a-fA-F0-9]{6}$/',
            'tickets.*.price' => 'nullable|numeric|decimal:0,2|min:0|max:999999.99',
            'tickets.*.units' => 'nullable|integer|min:0|max:1000000',
            'tickets.*.peoplePerUnit' => 'nullable|integer|min:1|max:10000',
            'tickets.*.maxPerPerson' => 'nullable|integer|min:1|max:1000000', 'tickets.*.visible' => 'nullable|boolean',
            'tickets.*.perks' => 'nullable|string|max:1000',
            'tickets.*.salesStart' => 'nullable|date', 'tickets.*.salesEnd' => 'nullable|date',
            'tickets.*.salesStartLocal' => 'nullable|date_format:Y-m-d\TH:i:s', 'tickets.*.salesEndLocal' => 'nullable|date_format:Y-m-d\TH:i:s',
            'attendeeFields' => 'nullable|array|max:30', 'attendeeFields.*' => 'array:id,label,enabled,required',
            'attendeeFields.*.id' => 'required|string|max:50|distinct|regex:/^[a-zA-Z][a-zA-Z0-9_]*$/D', 'attendeeFields.*.label' => 'required|string|max:200',
            'attendeeFields.*.enabled' => 'required|boolean', 'attendeeFields.*.required' => 'required|boolean',
            'extraDetails' => 'nullable|array|max:8', 'extraDetails.*' => 'array:type,label,value',
            'extraDetails.*.type' => 'required|distinct|in:parking,dress,bring,agenda,speakers,food,transport,faq',
            'extraDetails.*.label' => 'nullable|string|max:200', 'extraDetails.*.value' => 'nullable|string|max:5000',
            'recurrence' => 'nullable|array:frequency,weeklyDays,monthlyMode,dayOfMonth,nthWeekday,seriesEndType,seriesEndDate,occurrenceCount',
            'recurrence.frequency' => 'nullable|in:daily,weekly,monthly',
            'recurrence.weeklyDays' => 'nullable|array|max:7', 'recurrence.weeklyDays.*' => 'integer|between:0,6|distinct',
            'recurrence.monthlyMode' => 'nullable|in:day_of_month,nth_weekday', 'recurrence.dayOfMonth' => 'nullable|integer|between:1,31',
            'recurrence.nthWeekday' => 'nullable|array:ordinal,weekday', 'recurrence.nthWeekday.ordinal' => 'nullable|integer|between:1,5',
            'recurrence.nthWeekday.weekday' => 'nullable|integer|between:0,6',
            'recurrence.seriesEndType' => 'nullable|in:on_date,after_occurrences',
            'recurrence.seriesEndDate' => 'nullable|date_format:Y-m-d', 'recurrence.occurrenceCount' => 'nullable|integer|between:1,10000',
            'publicProfile' => 'nullable|array:summary,caption_title,caption_description,organiser_bio,organiser_image,guests,schedule,moments,faqs,crew,related_event_ids',
        ];
        $textBytes = function ($attribute, $value, $fail) {
            if (is_string($value) && strlen($value) > 65535) {
                $fail('This text exceeds the database byte limit.');
            }
        };
        $rules['description'] = ['nullable', 'string', 'max:20000', $textBytes];
        foreach (['summary', 'caption_description', 'organiser_bio'] as $field) {
            $rules['publicProfile.'.$field] = ['nullable', 'string', 'max:20000', $textBytes];
        }
        $rules['publicProfile.caption_title'] = 'nullable|string|max:255';
        $rules['publicProfile.organiser_image'] = $this->imageUrlRules();
        foreach (['guests', 'schedule', 'moments', 'faqs', 'crew'] as $field) {
            $rules['publicProfile.'.$field] = 'nullable|array|max:100';
        }
        foreach ([
            'guests' => ['id:100', 'name:200', 'role:200', 'bio:5000', 'image:url'],
            'schedule' => ['time:100', 'title:200', 'description:5000', 'label:100'],
            'moments' => ['url:url', 'caption:1000'], 'faqs' => ['question:255', 'answer:5000'],
            'crew' => ['name:200', 'role:200', 'image:url'],
        ] as $group => $fields) {
            $keys = [];
            foreach ($fields as $definition) {
                [$field, $limit] = explode(':', $definition);
                $keys[] = $field;
                $rules["publicProfile.$group.*.$field"] = $limit === 'url' ? $this->imageUrlRules() : "nullable|string|max:$limit";
            }
            $rules["publicProfile.$group.*"] = 'array:'.implode(',', $keys);
        }
        $rules['publicProfile.related_event_ids'] = 'nullable|array|max:20';
        $rules['publicProfile.related_event_ids.*'] = 'integer|distinct|exists:events,id';

        return $rules;
    }

    private function imageUrlRules(): array
    {
        return ['nullable', 'string', 'max:1000', function ($attribute, $value, $fail) {
            if (! is_string($value)) {
                return;
            }
            $remote = filter_var($value, FILTER_VALIDATE_URL) && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
            $local = preg_match('#^/storage/events/images/(?:[0-9]+/)?[a-zA-Z0-9-]+\.(jpg|jpeg|png|gif|webp)$#D', $value);
            if (! $remote && ! $local) {
                $fail('Use an HTTP(S) image URL or an existing public storage image.');
            }
        }];
    }

    private function instant(?string $local, ?string $iso, string $zone, string $field, array &$errors): ?CarbonImmutable
    {
        try {
            if ($iso) {
                if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $iso)) {
                    throw new \InvalidArgumentException;
                }
                $date = CarbonImmutable::parse($iso)->utc();
                if ($local && $date->setTimezone($zone)->format('Y-m-d\TH:i:s') !== $local) {
                    throw new \InvalidArgumentException;
                }

                return $date;
            }
            if (! $local) {
                return null;
            }
            $date = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s', $local, $zone);
            if (! $date || $date->format('Y-m-d\TH:i:s') !== $local) {
                throw new \InvalidArgumentException;
            }

            return $date->utc();
        } catch (\Throwable) {
            $errors[$field] = ['Use a valid local time and timezone, or a matching instant with an explicit UTC offset.'];

            return null;
        }
    }
}
