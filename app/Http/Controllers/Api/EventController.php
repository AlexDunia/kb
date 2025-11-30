<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\EventAddress;
use App\Models\TicketType;
use App\Models\Faq;
use App\Models\EventOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Voku\Helper\AntiXSS;

class EventController extends Controller
{
    /**
     * Get a listing of events with optional filtering
     */
   public function index(Request $request)
{
    $query = Event::with(['category', 'organizer', 'address', 'subCategories']);

    // Apply filters if provided
    if ($request->has('category_id')) {
        $query->where('category_id', $request->category_id);
    }

    if ($request->has('subcategory_id')) {
        $query->whereHas('subCategories', function($q) use ($request) {
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

    // Pagination - FIX: Don't let page parameter break the query
    $perPage = min($request->input('per_page', 10), 100); // Max 100 items

    // ✅ ADD DEBUG LOG
    \Log::info('Events query', [
        'total_count' => Event::count(),
        'query_count' => $query->count(),
        'page' => $request->input('page', 1),
        'per_page' => $perPage,
        'filters' => $request->all()
    ]);

    $events = $query->paginate($perPage);

    return response()->json([
        'data' => $events->items(),
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
        ]
    ]);
}

    /**
     * Get the specified event with all related data
     */
    public function show(Event $event)
    {
        $event->load([
            'category',
            'organizer',
            'address',
            'subCategories',
            'ticketTypes',
            'faqs',
            'eventOptions'
        ]);

        return response()->json([
            'data' => $event
        ]);
    }

    /**
     * Store a newly created event in storage.
     */
    public function store(Request $request)
    {
        // Check authorization
        if (!auth()->user()->hasAnyRole(['admin', 'agent'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Validate input
        $validated = $request->validate([
            'title' => 'required|string|min:5|max:100',
            'description' => 'required|string|min:20|max:5000',
            'category_id' => 'required|exists:categories,id',
            'organizer.name' => 'required|string|min:5|max:100',
            'organizer.email' => 'nullable|email|max:255',
            'location.venue_name' => 'required|string|max:100',
            'location.address_line1' => 'required|string|max:255',
            'location.address_line2' => 'nullable|string|max:255',
            'location.city' => 'required|string|max:100',
            'location.state' => 'required|string|max:100',
            'location.postal_code' => 'required|string|max:20',
            'location.country' => 'required|string|max:100',
            'date' => 'required|date|after:today',
            'price' => 'required|numeric|min:0|max:100000',
            'total_tickets' => 'required|integer|min:1|max:100000',
            'duration' => 'nullable|string|max:50',
            'featured' => 'boolean',
            'main_image' => 'required|image|mimes:jpeg,png,gif|max:5120',
            'banner_image' => 'required|image|mimes:jpeg,png,gif|max:5120',
            'sub_categories' => 'array|max:5',
            'sub_categories.*' => 'exists:sub_categories,id',
            'ticket_types' => 'required|array|min:1',
            'ticket_types.*.name' => 'required|string|max:50',
            'ticket_types.*.price' => 'required|numeric|min:0',
            'ticket_types.*.quantity' => 'required|integer|min:1',
            'ticket_types.*.description' => 'nullable|string|max:1000',
            'ticket_types.*.sales_end_date' => 'nullable|date|before_or_equal:date',
            'ticket_types.*.is_featured' => 'boolean',
            'faqs' => 'array',
            'faqs.*.question' => 'nullable|string|max:255',
            'faqs.*.answer' => 'nullable|string|max:1000',
            'event_options' => 'array',
            'event_options.*' => 'string|max:50',
        ]);

        try {
            return DB::transaction(function () use ($request, $validated) {
                // Store images
                $mainImagePath = $request->file('main_image')->store('events/images', 'public');
                $bannerImagePath = $request->file('banner_image')->store('events/images', 'public');

                // Create organizer
                $organizer = Organizer::create([
                    'name' => $validated['organizer']['name'],
                    'email' => $validated['organizer']['email'],
                ]);

                // Create address
                $address = EventAddress::create($validated['location']);

                // Create event
                $event = Event::create([
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'category_id' => $validated['category_id'],
                    'organizer_id' => $organizer->id,
                    'address_id' => $address->id,
                    'date' => $validated['date'],
                    'price' => $validated['price'],
                    'total_tickets' => $validated['total_tickets'],
                    'duration' => $validated['duration'],
                    'featured' => $validated['featured'] ?? false,
                    'main_image' => $mainImagePath,
                    'banner_image' => $bannerImagePath,
                    'created_by' => auth()->id(),
                ]);

                // Attach subcategories
                if (!empty($validated['sub_categories'])) {
                    $event->subCategories()->sync($validated['sub_categories']);
                }

                // Create ticket types
                foreach ($validated['ticket_types'] as $ticket) {
                    TicketType::create([
                        'event_id' => $event->id,
                        'name' => $ticket['name'],
                        'price' => $ticket['price'],
                        'quantity' => $ticket['quantity'],
                        'description' => $ticket['description'] ?? null,
                        'sales_end_date' => $ticket['sales_end_date'] ?? null,
                        'is_featured' => $ticket['is_featured'] ?? false,
                    ]);
                }

                // Create FAQs
                if (!empty($validated['faqs'])) {
                    foreach ($validated['faqs'] as $faq) {
                        if (!empty($faq['question']) && !empty($faq['answer'])) {
                            Faq::create([
                                'event_id' => $event->id,
                                'question' => $faq['question'],
                                'answer' => $faq['answer'],
                            ]);
                        }
                    }
                }

                // Create or attach event options
                if (!empty($validated['event_options'])) {
                    foreach ($validated['event_options'] as $optionName) {
                        $option = EventOption::firstOrCreate(
                            ['name' => $optionName],
                            ['is_custom' => true]
                        );
                        $event->eventOptions()->attach($option->id);
                    }
                }

                // Return properly formatted response
                return response()->json([
                    'data' => [
                        'id' => $event->id,
                        'message' => 'Event created successfully'
                    ]
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create event',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified event in storage.
     */
    public function update(Request $request, Event $event)
    {
        // Check authorization
        if (!auth()->user()->hasAnyRole(['admin', 'agent']) && auth()->id() !== $event->created_by) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Validate input
        $validated = $request->validate([
            'title' => 'sometimes|required|string|min:5|max:100',
            'description' => 'sometimes|required|string|min:20|max:5000',
            'category_id' => 'sometimes|required|exists:categories,id',
            'date' => 'sometimes|required|date',
            'price' => 'sometimes|required|numeric|min:0|max:100000',
            'total_tickets' => 'sometimes|required|integer|min:1|max:100000',
            'duration' => 'nullable|string|max:50',
            'featured' => 'sometimes|boolean',
            'main_image' => 'sometimes|image|mimes:jpeg,png,gif|max:5120',
            'banner_image' => 'sometimes|image|mimes:jpeg,png,gif|max:5120',
            'sub_categories' => 'sometimes|array|max:5',
            'sub_categories.*' => 'exists:sub_categories,id',
        ]);

        try {
            return DB::transaction(function () use ($request, $validated, $event) {
                // Update event attributes
                if ($request->hasFile('main_image')) {
                    // Delete old image if it exists
                    if ($event->main_image) {
                        Storage::disk('public')->delete($event->main_image);
                    }
                    $validated['main_image'] = $request->file('main_image')->store('events/images', 'public');
                }

                if ($request->hasFile('banner_image')) {
                    // Delete old image if it exists
                    if ($event->banner_image) {
                        Storage::disk('public')->delete($event->banner_image);
                    }
                    $validated['banner_image'] = $request->file('banner_image')->store('events/images', 'public');
                }

                // Update event
                $event->update($validated);

                // Update subcategories if provided
                if (isset($validated['sub_categories'])) {
                    $event->subCategories()->sync($validated['sub_categories']);
                }

                // Load updated event with relations
                $event->load([
                    'category',
                    'organizer',
                    'address',
                    'subCategories',
                    'ticketTypes',
                    'faqs',
                    'eventOptions'
                ]);

                return response()->json([
                    'data' => $event,
                    'message' => 'Event updated successfully'
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update event',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified event from storage.
     */
    public function destroy(Event $event)
    {
        // Check authorization
        if (!auth()->user()->hasAnyRole(['admin']) && auth()->id() !== $event->created_by) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            return DB::transaction(function () use ($event) {
                // Delete related images
                if ($event->main_image) {
                    Storage::disk('public')->delete($event->main_image);
                }
                if ($event->banner_image) {
                    Storage::disk('public')->delete($event->banner_image);
                }

                // Delete the event (cascade delete should handle relations)
                $event->delete();

                return response()->json([
                    'data' => null,
                    'message' => 'Event deleted successfully'
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete event',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Track when a user views an event
     */
    public function trackView(Event $event)
    {
        try {
            if (auth()->check()) {
                \App\Models\EventView::trackView(
                    auth()->id(),
                    $event->id,
                    request()->ip()
                );
            }

            return response()->json(['success' => true], 200);
        } catch (\Exception $e) {
            \Log::warning('Failed to track event view', [
                'event_id' => $event->id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json(['success' => false], 200);
        }
    }

    /**
     * Toggle like/favorite on an event
     */
    public function toggleLike(Event $event)
    {
        try {
            if (!auth()->check()) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'Please log in to like events'
                ], 401);
            }

            $liked = \App\Models\EventLike::toggleLike(auth()->id(), $event->id);

            if ($liked) {
                $engine = new \App\Services\RecommendationEngine(auth()->user());
                $engine->clearCache();
            }

            return response()->json([
                'liked' => $liked,
                'message' => $liked ? 'Event liked' : 'Event unliked'
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Failed to toggle event like', [
                'event_id' => $event->id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to toggle like'
            ], 500);
        }
    }

    /**
     * Check if user has liked an event
     */
    public function checkLiked(Event $event)
    {
        if (!auth()->check()) {
            return response()->json(['liked' => false], 200);
        }

        $liked = \App\Models\EventLike::where('user_id', auth()->id())
            ->where('event_id', $event->id)
            ->exists();

        return response()->json(['liked' => $liked], 200);
    }
}
