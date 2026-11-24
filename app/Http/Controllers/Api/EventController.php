<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicEventResource;
use App\Models\Event;
use Illuminate\Http\Request;

class EventController extends Controller
{
    /**
     * Get a listing of events with optional filtering
     */
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1',
            'category_id' => 'sometimes|integer|exists:categories,id', 'subcategory_id' => 'sometimes|integer|exists:sub_categories,id',
            'category' => 'sometimes|string|max:100', 'q' => ['sometimes', 'nullable', 'string', 'max:200', function ($attribute, $value, $fail) {
                if (is_string($value) && count(preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY)) > 20) {
                    $fail('Search supports at most 20 words.');
                }
            }], 'city' => 'sometimes|nullable|string|max:255',
            'date_from' => 'sometimes|date', 'date_to' => $request->filled('date_from') ? 'sometimes|date|after_or_equal:date_from' : 'sometimes|date',
            'sort' => 'sometimes|in:date-asc,date-desc,price-asc,price-desc,latest', 'featured' => 'sometimes|boolean',
        ]);
        $query = Event::publiclyVisible()->with(['category', 'organizer', 'address', 'subCategories']);
        if ($request->filled('city')) {
            $query->whereHas('address', fn ($address) => $address->where('city_search', mb_strtolower(trim($request->city))));
        }
        if ($request->filled('category')) {
            $query->whereHas('category', fn ($category) => $category->where('name', $request->category));
        }
        $words = preg_split('/\s+/u', mb_strtolower(trim((string) $request->input('q', ''))), -1, PREG_SPLIT_NO_EMPTY);
        foreach (array_slice($words, 0, 20) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->where(function ($search) use ($pattern) {
                foreach (['title', 'description', 'tags'] as $field) {
                    $search->orWhereRaw("LOWER(events.$field) LIKE ? ESCAPE '!'", [$pattern]);
                }
                $search->orWhereHas('organizer', fn ($organizer) => $organizer->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
                $search->orWhereHas('category', fn ($category) => $category->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
                $search->orWhereHas('address', fn ($address) => $address->whereRaw("LOWER(venue_name) LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("LOWER(city) LIKE ? ESCAPE '!'", [$pattern]));
            });
        }

        // Apply filters if provided
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('subcategory_id')) {
            $query->whereHas('subCategories', function ($q) use ($request) {
                $q->where('sub_categories.id', $request->subcategory_id);
            });
        }

        if ($request->has('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        if ($request->has('featured') && $request->featured) {
            $query->where('featured', true);
        }

        // Sort options - DEFAULT TO LATEST FIRST
        if ($request->has('sort')) {
            switch ($request->sort) {
                case 'date-asc':
                    $query->orderBy('date', 'asc');
                    break;
                case 'date-desc':
                    $query->orderBy('date', 'desc');
                    break;
                case 'price-asc':
                    $query->orderBy('price', 'asc');
                    break;
                case 'price-desc':
                    $query->orderBy('price', 'desc');
                    break;
                default:
                    $query->latest(); // Show newest first
                    break;
            }
        } else {
            $query->latest(); // Show newest first by default
        }

        $perPage = (int) $request->input('per_page', 10);

        $events = $query->orderBy('id', in_array($request->input('sort'), ['date-asc', 'price-asc'], true) ? 'asc' : 'desc')->paginate($perPage);

        return response()->json([
            'data' => PublicEventResource::collection($events->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $events->currentPage(),
                'from' => $events->firstItem(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'to' => $events->lastItem(),
                'total' => $events->total(),
            ],
            'links' => [
                'first' => $events->url(1),
                'last' => $events->url($events->lastPage()),
                'prev' => $events->previousPageUrl(),
                'next' => $events->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Get the specified event with all related data
     */
    public function show(Event $event)
    {
        abort_unless($event->status === 'active', 404);
        $event->load([
            'publicProfile',
            'media',
            'category',
            'organizer',
            'address',
            'subCategories',
            'ticketTypes' => function ($query) {
                $query->where('visible', true)->whereNull('retired_at');
            },
            'faqs',
            'eventOptions',
        ]);

        $this->attachInventory($event->ticketTypes);

        $relatedIds = $event->publicProfile?->related_event_ids ?? [];
        $event->setRelation('relatedEvents', Event::publiclyVisible()
            ->with(['organizer', 'address'])
            ->whereIn('id', $relatedIds)
            ->where('id', '!=', $event->id)
            ->get());

        return response()->json([
            'data' => (new PublicEventResource($event))->resolve(),
        ]);
    }

    /** The editor is the sole supported event writer. */
    public function retiredMutation()
    {
        return response()->json([
            'message' => 'This legacy event mutation endpoint has been retired. Use the event editor endpoints.',
            'editor_endpoint' => '/api/create-event',
        ], 410);
    }

    /**
     * Toggle like/favorite on an event
     */
    public function toggleLike(Event $event)
    {
        abort_unless($event->status === 'active', 404);
        try {
            if (! auth()->check()) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'Please log in to like events',
                ], 401);
            }

            $liked = \App\Models\EventLike::toggleLike(auth()->id(), $event->id);

            if ($liked) {
                $engine = new \App\Services\RecommendationEngine(auth()->user());
                $engine->clearCache();
            }

            return response()->json([
                'liked' => $liked,
                'likes_count' => \App\Models\EventLike::where('event_id', $event->id)->count(),
                'message' => $liked ? 'Event liked' : 'Event unliked',
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Failed to toggle event like', [
                'event_id' => $event->id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to toggle like. Please try again later.',
            ], 500);
        }
    }

    /**
     * Check if user has liked an event
     */
    public function checkLiked(Event $event)
    {
        abort_unless($event->status === 'active', 404);
        if (! auth()->check()) {
            return response()->json(['liked' => false, 'likes_count' => 0], 200);
        }

        $liked = \App\Models\EventLike::where('user_id', auth()->id())
            ->where('event_id', $event->id)
            ->exists();

        $count = \App\Models\EventLike::where('event_id', $event->id)->count();

        return response()->json(['liked' => $liked, 'likes_count' => $count], 200);
    }

    public function getLikedEvents(Request $request)
    {
        $user = $request->user();

        $likedEventIds = \App\Models\EventLike::where('user_id', $user->id)->select('event_id');

        $events = Event::publiclyVisible()->whereIn('id', $likedEventIds)
            ->with(['category', 'address', 'ticketTypes' => fn ($query) => $query->where('visible', true)->whereNull('retired_at')])
            ->latest()
            ->paginate(100);

        $this->attachInventory($events->getCollection()->flatMap(fn ($event) => $event->ticketTypes));

        return response()->json(['data' => PublicEventResource::collection($events->getCollection())->resolve($request), 'meta' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'per_page' => $events->perPage(), 'total' => $events->total()]]);
    }

    private function attachInventory(\Illuminate\Support\Collection $tickets): void
    {
        $inventory = app(\App\Services\Commerce\InventoryService::class);
        $commitments = $inventory->commitments($tickets->pluck('id')->all());
        foreach ($tickets as $ticketType) {
            $ticketType->setAttribute('sold_units', $commitments[$ticketType->id]['sold_units'] ?? 0);
            $ticketType->setAttribute('reserved_units', $commitments[$ticketType->id]['reserved_units'] ?? 0);
            $ticketType->setAttribute('configured_capacity_units', (int) $ticketType->quantity);
            $ticketType->setAttribute('admission_capacity', (int) $ticketType->quantity * (int) $ticketType->people_per_unit);
            $ticketType->setAttribute('available_quantity', $inventory->availableQuantity(
                (int) $ticketType->quantity, (int) ($commitments[$ticketType->id]['sold_units'] ?? 0) + (int) ($commitments[$ticketType->id]['reserved_units'] ?? 0)
            ));
        }

    }
}
