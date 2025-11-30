<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Models\Purchase;
use App\Models\EventView;
use App\Models\EventLike;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class RecommendationEngine
{
    private const CACHE_TTL = 30;
    private const MAX_RECOMMENDATIONS = 10;
    private const TOP_CATEGORIES_LIMIT = 3;

    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getRecommendations(): array
    {
        $cacheKey = "recommendations.user.{$this->user->id}." . now()->format('YmdH');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            return [
                'because_you_liked' => $this->getBecauseYouLikedRecommendations(),
                'jump_back_in' => $this->getJumpBackInRecommendations(),
                'made_for_you' => $this->getMadeForYouRecommendations(),
            ];
        });
    }

    private function getBecauseYouLikedRecommendations(): array
    {
        $categoryIds = $this->getUserPreferredCategoryIds();

        if ($categoryIds->isEmpty()) {
            return $this->formatResponse('Because you liked...', collect());
        }

        $purchasedEventIds = $this->getUserPurchasedEventIds();

        $events = Event::select([
                'events.id',
                'events.title',
                'events.category_id',
                'events.price',
                'events.date',
                'events.main_image',
                'events.total_tickets',
                'categories.name as category_name'
            ])
            ->join('categories', 'events.category_id', '=', 'categories.id')
            ->whereIn('events.category_id', $categoryIds)
            ->whereNotIn('events.id', $purchasedEventIds)
            ->where('events.date', '>=', now())
            ->where('events.total_tickets', '>', 0)
            ->where('events.status', 'active')
            ->orderBy('events.date', 'asc')
            ->limit(self::MAX_RECOMMENDATIONS)
            ->get();

        return $this->formatResponse('Because you liked...', $events);
    }

    private function getJumpBackInRecommendations(): array
    {
        $purchasedEventIds = $this->getUserPurchasedEventIds();

        $events = Event::select([
                'events.id',
                'events.title',
                'events.category_id',
                'events.price',
                'events.date',
                'events.main_image',
                'events.total_tickets',
                'categories.name as category_name',
                DB::raw('MAX(event_views.viewed_at) as last_viewed_at')
            ])
            ->join('categories', 'events.category_id', '=', 'categories.id')
            ->join('event_views', 'events.id', '=', 'event_views.event_id')
            ->where('event_views.user_id', $this->user->id)
            ->whereNotIn('events.id', $purchasedEventIds)
            ->where('events.date', '>=', now())
            ->where('events.total_tickets', '>', 0)
            ->where('events.status', 'active')
            ->groupBy([
                'events.id',
                'events.title',
                'events.category_id',
                'events.price',
                'events.date',
                'events.main_image',
                'events.total_tickets',
                'categories.name'
            ])
            ->orderBy('last_viewed_at', 'desc')
            ->limit(self::MAX_RECOMMENDATIONS)
            ->get();

        return $this->formatResponse('Jump back in...', $events);
    }

    private function getMadeForYouRecommendations(): array
    {
        $topCategoryIds = $this->getTopCategoriesByInteraction();

        if ($topCategoryIds->isEmpty()) {
            return $this->formatResponse('Made for you...', collect());
        }

        $interactedEventIds = $this->getUserInteractedEventIds();

        $events = Event::select([
                'events.id',
                'events.title',
                'events.category_id',
                'events.price',
                'events.date',
                'events.main_image',
                'events.total_tickets',
                'events.featured',
                'categories.name as category_name'
            ])
            ->join('categories', 'events.category_id', '=', 'categories.id')
            ->whereIn('events.category_id', $topCategoryIds)
            ->whereNotIn('events.id', $interactedEventIds)
            ->where('events.date', '>=', now())
            ->where('events.total_tickets', '>', 0)
            ->where('events.status', 'active')
            ->orderByRaw('events.featured DESC, events.date ASC')
            ->limit(self::MAX_RECOMMENDATIONS)
            ->get();

        return $this->formatResponse('Made for you...', $events);
    }

    private function getUserPreferredCategoryIds(): Collection
    {
        return DB::table('events')
            ->select('category_id')
            ->whereIn('id', function ($query) {
                $query->select('event_id')
                    ->from('purchases')
                    ->where('user_id', $this->user->id)
                    ->union(
                        DB::table('event_likes')
                            ->select('event_id')
                            ->where('user_id', $this->user->id)
                    );
            })
            ->distinct()
            ->pluck('category_id');
    }

    private function getUserPurchasedEventIds(): Collection
    {
        return Purchase::where('user_id', $this->user->id)
            ->pluck('event_id');
    }

    private function getUserInteractedEventIds(): Collection
    {
        $purchased = Purchase::where('user_id', $this->user->id)
            ->pluck('event_id');

        $viewed = EventView::where('user_id', $this->user->id)
            ->pluck('event_id');

        $liked = EventLike::where('user_id', $this->user->id)
            ->pluck('event_id');

        return $purchased->merge($viewed)->merge($liked)->unique();
    }

    private function getTopCategoriesByInteraction(): Collection
    {
        $topCategories = DB::table('events')
            ->select('events.category_id', DB::raw('
                (
                    COALESCE(SUM(CASE WHEN purchases.id IS NOT NULL THEN 10 ELSE 0 END), 0) +
                    COALESCE(SUM(CASE WHEN event_likes.id IS NOT NULL THEN 5 ELSE 0 END), 0) +
                    COALESCE(SUM(CASE WHEN event_views.id IS NOT NULL THEN 1 ELSE 0 END), 0)
                ) as interaction_score
            '))
            ->leftJoin('purchases', function ($join) {
                $join->on('events.id', '=', 'purchases.event_id')
                    ->where('purchases.user_id', '=', $this->user->id);
            })
            ->leftJoin('event_likes', function ($join) {
                $join->on('events.id', '=', 'event_likes.event_id')
                    ->where('event_likes.user_id', '=', $this->user->id);
            })
            ->leftJoin('event_views', function ($join) {
                $join->on('events.id', '=', 'event_views.event_id')
                    ->where('event_views.user_id', '=', $this->user->id);
            })
            ->groupBy('events.category_id')
            ->having('interaction_score', '>', 0)
            ->orderBy('interaction_score', 'desc')
            ->limit(self::TOP_CATEGORIES_LIMIT)
            ->pluck('category_id');

        return $topCategories;
    }

    private function formatResponse(string $label, Collection $events): array
    {
        return [
            'label' => $label,
            'count' => $events->count(),
            'events' => $events->map(function ($event) {
                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'category_id' => $event->category_id,
                    'category_name' => $event->category_name ?? null,
                    'price' => (float) $event->price,
                    'formatted_price' => '$' . number_format($event->price, 2),
                    'date' => $event->date,
                    'formatted_date' => Carbon::parse($event->date)->format('M d, Y'),
                    'main_image' => $event->main_image,
                    'tickets_available' => $event->total_tickets,
                    'is_featured' => $event->featured ?? false,
                ];
            })->values()->all()
        ];
    }

    public function clearCache(): void
    {
        $cacheKey = "recommendations.user.{$this->user->id}." . now()->format('YmdH');
        Cache::forget($cacheKey);
    }
}
