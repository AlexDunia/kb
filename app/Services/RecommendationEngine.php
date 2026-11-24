<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Services\Commerce\InventoryService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RecommendationEngine
{
    public function __construct(private readonly User $user) {}

    private function key(): string
    {
        return 'recommendations.v5.user.'.$this->user->id;
    }

    public function getRecommendations(): array
    {
        return Cache::remember($this->key(), 30, function () {
            $categories = $this->categories();
            $base = fn () => Event::query()->with('category')->where('status', 'active')->where('lifecycle_state', 'open')->where(fn ($q) => $q->whereNull('booking_cutoff_at')->orWhere('booking_cutoff_at', '>', now()))->where('date', '>=', now())
                ->whereNotIn('id', $this->purchased())->where('event_type', '!=', 'recurring');
            $because = $categories ? $base()->whereIn('category_id', $categories)->orderBy('date')->orderBy('id')->limit(10)->get() : collect();
            $lastViews = DB::query()->fromSub($this->views(), 'v')->groupBy('event_id')->selectRaw('event_id, MAX(viewed_at) AS last_viewed_at');
            $jump = $base()->joinSub($lastViews, 'last_view', fn ($j) => $j->on('events.id', '=', 'last_view.event_id'))->select('events.*')->orderByDesc('last_view.last_viewed_at')->orderBy('events.id')->limit(10)->get();
            $made = $categories ? $base()->whereIn('category_id', $categories)->whereNotIn('id', DB::query()->fromSub($this->views(), 'v')->select('event_id'))->whereNotIn('id', DB::table('event_likes')->where('user_id', $this->user->id)->select('event_id'))->orderByDesc('featured')->orderBy('date')->limit(10)->get() : collect();

            return ['because_you_liked' => $this->format('Because you liked...', $because), 'jump_back_in' => $this->format('Jump back in...', $jump), 'made_for_you' => $this->format('Made for you...', $made)];
        });
    }

    private function purchased(): Builder
    {
        // Read-only legacy compatibility: preserve old completed purchase signals without copying or double-counting events.
        return DB::table('purchases')->where('user_id', $this->user->id)->where('status', 'completed')->select('event_id')
            ->union(DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')->where('o.user_id', $this->user->id)->whereIn('o.status', ['paid', 'registered', 'partially_refunded'])->select('i.event_id'));
    }

    private function views(): Builder
    {
        return DB::table('event_views')->where('user_id', $this->user->id)->select(['event_id', 'viewed_at'])
            ->union(DB::table('event_traffic_views')->where('user_id', $this->user->id)->select(['event_id', 'viewed_at']));
    }

    private function categories(): array
    {
        // Independent signals are aggregated before combining, avoiding purchase x like x view join multiplication.
        $purchases = DB::query()->fromSub($this->purchased(), 'p')->selectRaw('event_id, 10 AS weight');
        $likes = DB::table('event_likes')->where('user_id', $this->user->id)->selectRaw('event_id, 5 AS weight');
        $views = DB::query()->fromSub($this->views(), 'v')->groupBy('event_id')->selectRaw('event_id, 1 AS weight');

        return DB::query()->fromSub($purchases->unionAll($likes)->unionAll($views), 'signals')->join('events as e', 'e.id', '=', 'signals.event_id')
            ->whereNotNull('e.category_id')->groupBy('e.category_id')->orderByRaw('SUM(signals.weight) DESC')->orderBy('e.category_id')->limit(3)->pluck('e.category_id')->all();
    }

    private function format(string $label, Collection $events): array
    {
        $events->load('ticketTypes');
        $ids = $events->flatMap(fn ($e) => $e->ticketTypes->pluck('id'))->unique()->all();
        $committed = app(InventoryService::class)->reservedQuantities($ids);
        $currency = strtoupper(config('commerce.currency', 'NGN'));
        $rows = $events->map(function ($e) use ($committed, $currency) {
            $types = $e->ticketTypes->filter(fn ($t) => ! $t->retired_at && ($t->visible || $t->is_registration) && (! $t->sales_start_date || $t->sales_start_date->lte(now())) && (! $t->sales_end_date || $t->sales_end_date->gte(now())));
            $available = $types->sum(fn ($t) => max(0, $t->quantity - ($committed[$t->id] ?? 0)));
            if ($e->ticket_mode === 'free' && $types->isEmpty()) {
                $available = max(0, (int) $e->total_tickets);
            }
            if (! $available) {
                return null;
            }
            $minor = $e->ticket_mode === 'free' ? 0 : $types->min(fn ($t) => Money::decimalToMinor($t->price));

            return ['id' => $e->id, 'title' => $e->title, 'category_id' => $e->category_id, 'category_name' => $e->category?->name, 'price' => $minor / 100, 'price_minor' => $minor, 'currency' => $currency, 'formatted_price' => $currency.' '.number_format($minor / 100, 2), 'date' => $e->date, 'formatted_date' => Carbon::parse($e->date)->format('M d, Y'), 'main_image' => $e->main_image, 'tickets_available' => $available, 'is_featured' => (bool) $e->featured];
        })->filter()->values()->all();

        return ['label' => $label, 'count' => count($rows), 'events' => $rows];
    }

    public static function invalidate(int $userId): void
    {
        Cache::forget('recommendations.v5.user.'.$userId);
    }

    public function clearCache(): void
    {
        self::invalidate($this->user->id);
    }
}
