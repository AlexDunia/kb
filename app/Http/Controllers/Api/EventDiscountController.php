<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDiscount;
use App\Services\Commerce\DiscountService;
use App\Services\EventAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventDiscountController extends Controller
{
    public function index(Request $request, Event $event)
    {
        app(EventAccessService::class)->authorize($event, $request->user()->id, 'owner');
        $v = $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $page = EventDiscount::where('event_id', $event->id)->orderByDesc('id')->paginate($v['per_page'] ?? 30);
        $ids = $page->getCollection()->pluck('id');
        $counts = DB::table('discount_redemptions')->whereIn('discount_id', $ids)->groupBy('discount_id')
            ->selectRaw('discount_id, SUM(CASE WHEN redeemed_at IS NOT NULL THEN 1 ELSE 0 END) AS used, SUM(CASE WHEN redeemed_at IS NULL AND held_until > ? THEN 1 ELSE 0 END) AS reserved', [now()])->get()->keyBy('discount_id');
        $page->through(fn ($o) => [...$o->toArray(), 'used' => (int) ($counts[$o->id]->used ?? 0), 'reserved' => (int) ($counts[$o->id]->reserved ?? 0)]);

        return response()->json($page);
    }

    public function save(Request $request, Event $event, ?int $discount = null)
    {
        app(EventAccessService::class)->authorize($event, $request->user()->id, 'owner');
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $v = $request->validate([
            'version' => [$discount ? 'required' : 'sometimes', 'integer', 'min:1'],
            'code' => ['required', 'regex:/^[A-Z0-9-]{3,20}$/', Rule::unique('event_discounts', 'code')->where('event_id', $event->id)->ignore($discount)],
            'name' => 'required|string|min:2|max:100', 'partner' => 'nullable|string|max:100',
            'type' => 'required|in:percent,fixed', 'value' => ['required', 'integer', 'min:1', $request->input('type') === 'percent' ? 'max:100' : 'max:100000000'],
            'usage_limit' => 'required|integer|min:1|max:100000', 'active' => 'required|boolean',
            'starts_at' => ['nullable', 'date', 'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/'], 'ends_at' => ['nullable', 'date', 'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/', ...($request->filled('starts_at') ? ['after:starts_at'] : [])],
            'ticket_type_ids' => 'nullable|array|min:1|max:100',
            'ticket_type_ids.*' => ['integer', 'distinct', Rule::exists('ticket_types', 'id')->where('event_id', $event->id)->where('is_registration', false)],
        ]);

        return DB::transaction(function () use ($event, $discount, $v) {
            // Same inventory-before-offer lock order as checkout and payment finalization.
            DB::table('events')->where('id', $event->id)->lockForUpdate()->first();
            if (! $discount && EventDiscount::where('event_id', $event->id)->where('code', $v['code'])->exists()) {
                throw ValidationException::withMessages(['code' => ['This code already exists for this event.']]);
            }
            $offer = $discount ? EventDiscount::where('event_id', $event->id)->whereKey($discount)->lockForUpdate()->firstOrFail() : new EventDiscount(['event_id' => $event->id]);
            abort_if($discount && $offer->version !== (int) $v['version'], 409, 'This discount changed. Reload before saving.');
            if ($discount && app(DiscountService::class)->committed($offer->id) > $v['usage_limit']) {
                throw ValidationException::withMessages(['usage_limit' => ['The limit must cover redeemed and reserved uses.']]);
            }
            unset($v['version']);
            $v['ticket_type_ids'] = isset($v['ticket_type_ids']) ? array_map('intval', $v['ticket_type_ids']) : null;
            foreach (['starts_at', 'ends_at'] as $key) {
                $v[$key] = empty($v[$key]) ? null : \Carbon\CarbonImmutable::parse($v[$key])->utc();
            }
            $offer->fill([...$v, 'currency' => $offer->currency ?: strtoupper(config('commerce.currency', 'NGN')), 'version' => $discount ? $offer->version + 1 : 1])->save();

            return response()->json(['data' => $offer], $discount ? 200 : 201);
        }, 3);
    }
}
