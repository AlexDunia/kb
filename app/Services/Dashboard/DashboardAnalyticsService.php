<?php

namespace App\Services\Dashboard;

use App\Models\Event;
use App\Models\EventShareLink;
use App\Models\EventTrafficView;
use App\Models\Order;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardAnalyticsService
{
    public function __construct(
        private readonly EventAttributionService $attribution
    ) {}

    public function eventListItem(Event $event): array
    {
        $event->loadMissing('address');

        $timezone = $this->eventTimezone($event);
        $now = CarbonImmutable::now($timezone);
        $start = $this->toEventTime($event->date, $timezone);
        $end = $this->toEventTime($event->ends_at, $timezone);

        return [
            'id' => (int) $event->id,
            'title' => (string) $event->title,
            'status' => (string) ($event->status ?? 'draft'),
            'phase' => $this->eventPhase($event, $now, $start, $end),
            'ticket_mode' => (string) ($event->ticket_mode ?? 'free'),
            'starts_at' => $start?->toIso8601String(),
            'ends_at' => $end?->toIso8601String(),
            'timezone' => $timezone,
            'venue' => $event->address?->venue_name,
            'cover_image' => $event->main_image,
            'days_to_go' => $this->daysToGo($now, $start),
            'public_path' => $this->publicPath($event),
        ];
    }

    public function overview(Event $event): array
    {
        $event->loadMissing(['address', 'ticketTypes']);

        $this->attribution->ensureDefaultLinks($event);

        $timezone = $this->eventTimezone($event);
        $now = CarbonImmutable::now($timezone);
        $start = $this->toEventTime($event->date, $timezone);
        $end = $this->toEventTime($event->ends_at, $timezone);

        $timeline = $this->salesTimeline($event, $timezone, $now);
        $ticketPerformance = $this->ticketPerformance($event);
        $traffic = $this->trafficMetrics($event);
        $buyers = $this->buyerMetrics($event, (int) $timeline['total_revenue_minor']);
        $sources = $this->sourceBreakdown($event, (int) $buyers['unique_buyers']);

        $salesCapacity = collect($ticketPerformance)->sum('effective_capacity_units');
        $soldUnits = collect($ticketPerformance)->sum('sold_units');
        $reservedUnits = collect($ticketPerformance)->sum('reserved_units');
        $availableUnits = collect($ticketPerformance)->sum('available_units');

        if (($event->ticket_mode ?? 'free') !== 'paid') {
            $salesCapacity = (int) ($event->total_tickets ?? 0);
            $soldUnits = 0;
            $reservedUnits = 0;
            $availableUnits = $salesCapacity;
        }

        $admissionCapacity = collect($ticketPerformance)->sum(
            fn (array $tier) =>
                (int) $tier['effective_capacity_units']
                * max(1, (int) $tier['people_per_unit'])
        );

        $admissionEntriesCovered = collect($ticketPerformance)->sum(
            fn (array $tier) =>
                (int) $tier['sold_units']
                * max(1, (int) $tier['people_per_unit'])
        );

        if (($event->ticket_mode ?? 'free') !== 'paid') {
            $admissionCapacity = (int) ($event->total_tickets ?? 0);
            $admissionEntriesCovered = 0;
        }

        return [
            'event' => [
                ...$this->eventListItem($event),
                'currency' => strtoupper((string) config('commerce.currency', 'NGN')),
            ],
            'sales' => [
                'units_sold' => $soldUnits,
                'capacity_units' => $salesCapacity,
                'reserved_units' => $reservedUnits,
                'available_units' => $availableUnits,
                'sold_percent' => $this->percent($soldUnits, $salesCapacity),
                'this_week_units' => (int) $timeline['this_week_units'],
                'previous_comparable_units' =>
                    (int) $timeline['previous_comparable_units'],
                'week_growth_percent' => $this->growthPercent(
                    (int) $timeline['this_week_units'],
                    (int) $timeline['previous_comparable_units']
                ),
                'week_growth_state' => $this->growthState(
                    (int) $timeline['this_week_units'],
                    (int) $timeline['previous_comparable_units']
                ),
                // A table is still one sold unit. These admission fields are
                // separate because people_per_unit is useful for door capacity,
                // not for saying how many ticket products were sold.
                'admission_capacity' => $admissionCapacity,
                'admission_entries_covered' => $admissionEntriesCovered,
                'tracking_supported' =>
                    ($event->ticket_mode ?? 'free') === 'paid',
            ],
            'revenue' => [
                'currency' => strtoupper((string) config('commerce.currency', 'NGN')),
                'total_minor' => (int) $timeline['total_revenue_minor'],
                'this_week_minor' => (int) $timeline['this_week_revenue_minor'],
                'previous_comparable_minor' =>
                    (int) $timeline['previous_comparable_revenue_minor'],
                'week_growth_percent' => $this->growthPercent(
                    (int) $timeline['this_week_revenue_minor'],
                    (int) $timeline['previous_comparable_revenue_minor']
                ),
                'week_growth_state' => $this->growthState(
                    (int) $timeline['this_week_revenue_minor'],
                    (int) $timeline['previous_comparable_revenue_minor']
                ),
            ],
            'traffic' => $traffic,
            'buyers' => $buyers,
            'ticket_types' => $ticketPerformance,
            'sales_trend' => [
                '7d' => $timeline['series_7d'],
                '30d' => $timeline['series_30d'],
                'all' => $timeline['series_all'],
            ],
            'best_sales_day' => [
                'this_week' => $timeline['best_this_week'],
                'overall' => $timeline['best_overall'],
            ],
            'peak_buying_time' => $timeline['peak_buying_time'],
            'sources' => $sources,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function shareLinks(Event $event): array
    {
        $links = $this->attribution->ensureDefaultLinks($event);
        $performance = collect($this->sourceBreakdown($event, $this->uniqueBuyerCount($event)))
            ->keyBy('source_code');

        return $links
            ->map(function (EventShareLink $link) use ($performance) {
                $stats = $performance->get($link->source_code, []);

                return [
                    'id' => (int) $link->id,
                    'label' => (string) $link->label,
                    'source_code' => (string) $link->source_code,
                    'is_system' => (bool) $link->is_system,
                    'views' => (int) ($stats['views'] ?? 0),
                    'unique_visitors' => (int) ($stats['unique_visitors'] ?? 0),
                    'buyers' => (int) ($stats['buyers'] ?? 0),
                    'buyer_share_percent' =>
                        (float) ($stats['buyer_share_percent'] ?? 0),
                    'conversion_percent' =>
                        (float) ($stats['conversion_percent'] ?? 0),
                    'units_sold' => (int) ($stats['units_sold'] ?? 0),
                    'revenue_minor' => (int) ($stats['revenue_minor'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function ticketPerformance(Event $event): array
    {
        $ticketTypes = $event->ticketTypes
            ->sortBy('id')
            ->values();

        if ($ticketTypes->isEmpty()) {
            return [];
        }

        $ids = $ticketTypes->pluck('id')->map(fn ($id) => (int) $id)->all();

        $paid = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.event_id', $event->id)
            ->whereIn('order_items.ticket_type_id', $ids)
            ->where('orders.status', Order::STATUS_PAID)
            ->groupBy('order_items.ticket_type_id')
            ->selectRaw(
                'order_items.ticket_type_id,
                 COALESCE(SUM(order_items.quantity), 0) AS sold_units,
                 COALESCE(SUM(order_items.line_total_minor), 0) AS revenue_minor'
            )
            ->get()
            ->keyBy(fn ($row) => (int) $row->ticket_type_id);

        $reserved = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.event_id', $event->id)
            ->whereIn('order_items.ticket_type_id', $ids)
            ->where('orders.status', Order::STATUS_PENDING_PAYMENT)
            ->where('orders.expires_at', '>', now())
            ->groupBy('order_items.ticket_type_id')
            ->selectRaw(
                'order_items.ticket_type_id,
                 COALESCE(SUM(order_items.quantity), 0) AS reserved_units'
            )
            ->get()
            ->keyBy(fn ($row) => (int) $row->ticket_type_id);

        return $ticketTypes
            ->map(function ($type) use ($paid, $reserved) {
                $sale = $paid->get((int) $type->id);
                $hold = $reserved->get((int) $type->id);

                $sold = (int) ($sale->sold_units ?? 0);
                $reservedUnits = (int) ($hold->reserved_units ?? 0);
                $configuredCapacity = max(0, (int) $type->quantity);

                // A hidden tier with history is preserved by CreateEventService.
                // Once hidden, its unsold inventory is no longer offered, so the
                // dashboard denominator becomes the number already sold.
                $effectiveCapacity = $type->visible
                    ? max($configuredCapacity, $sold)
                    : $sold;

                return [
                    'id' => (int) $type->id,
                    'name' => (string) $type->name,
                    'unit_type' => (string) ($type->unit_type ?: 'individual'),
                    'people_per_unit' => max(1, (int) $type->people_per_unit),
                    'price_minor' => Money::decimalToMinor($type->price),
                    'configured_capacity_units' => $configuredCapacity,
                    'effective_capacity_units' => $effectiveCapacity,
                    'sold_units' => $sold,
                    'reserved_units' => $reservedUnits,
                    'available_units' => max(
                        0,
                        $effectiveCapacity - $sold - $reservedUnits
                    ),
                    'sold_percent' => $this->percent(
                        $sold,
                        $effectiveCapacity
                    ),
                    'revenue_minor' => (int) ($sale->revenue_minor ?? 0),
                    'visible' => (bool) $type->visible,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Build every time-based sales metric from one streaming event-order query.
     * One order may contain several ticket types for the event, so the database
     * first collapses those rows into one event total per order.
     */
    private function salesTimeline(
        Event $event,
        string $timezone,
        CarbonImmutable $now
    ): array {
        $daily = [];
        $buckets = $this->emptyBuyingTimeBuckets();

        $currentWeekStart = $now->startOfWeek(
            CarbonInterface::MONDAY
        );
        $elapsedSeconds = (int) max(
            0,
            $currentWeekStart->diffInSeconds($now)
        );

        $previousWeekStart = $currentWeekStart->subWeek();
        $previousComparableEnd = $previousWeekStart->addSeconds($elapsedSeconds);

        $thisWeekUnits = 0;
        $thisWeekRevenue = 0;
        $previousUnits = 0;
        $previousRevenue = 0;
        $totalUnits = 0;
        $totalRevenue = 0;

        $firstSaleDate = null;

        $rows = $this->paidEventOrdersQuery($event)
            ->groupBy(
                'orders.id',
                'orders.paid_at'
            )
            ->selectRaw(
                'orders.id,
                 orders.paid_at,
                 COALESCE(SUM(order_items.quantity), 0) AS units_sold,
                 COALESCE(SUM(order_items.line_total_minor), 0) AS revenue_minor'
            )
            ->orderBy('orders.paid_at')
            ->cursor();

        foreach ($rows as $row) {
            if (! $row->paid_at) {
                continue;
            }

            $paidAt = CarbonImmutable::parse(
                (string) $row->paid_at,
                'UTC'
            )->setTimezone($timezone);

            $units = (int) $row->units_sold;
            $revenue = (int) $row->revenue_minor;
            $dateKey = $paidAt->format('Y-m-d');

            $firstSaleDate ??= $paidAt->startOfDay();

            if (! isset($daily[$dateKey])) {
                $daily[$dateKey] = [
                    'date' => $dateKey,
                    'units_sold' => 0,
                    'revenue_minor' => 0,
                    'orders' => 0,
                ];
            }

            $daily[$dateKey]['units_sold'] += $units;
            $daily[$dateKey]['revenue_minor'] += $revenue;
            $daily[$dateKey]['orders']++;

            $totalUnits += $units;
            $totalRevenue += $revenue;

            if ($paidAt >= $currentWeekStart && $paidAt <= $now) {
                $thisWeekUnits += $units;
                $thisWeekRevenue += $revenue;
            }

            if (
                $paidAt >= $previousWeekStart
                && $paidAt <= $previousComparableEnd
            ) {
                $previousUnits += $units;
                $previousRevenue += $revenue;
            }

            $bucketKey = $this->buyingTimeBucketKey($paidAt->hour);
            $buckets[$bucketKey]['orders']++;
            $buckets[$bucketKey]['units_sold'] += $units;
            $buckets[$bucketKey]['revenue_minor'] += $revenue;
        }

        ksort($daily);

        $today = $now->startOfDay();

        $series7 = $this->fillDailySeries(
            $daily,
            $today->subDays(6),
            $today
        );

        $series30 = $this->fillDailySeries(
            $daily,
            $today->subDays(29),
            $today
        );

        $allStart = $firstSaleDate ?? $today;

        $seriesAll = $this->fillDailySeries(
            $daily,
            $allStart,
            $today
        );

        return [
            'total_units' => $totalUnits,
            'total_revenue_minor' => $totalRevenue,
            'this_week_units' => $thisWeekUnits,
            'this_week_revenue_minor' => $thisWeekRevenue,
            'previous_comparable_units' => $previousUnits,
            'previous_comparable_revenue_minor' => $previousRevenue,
            'series_7d' => $series7,
            'series_30d' => $series30,
            'series_all' => $seriesAll,
            'best_this_week' => $this->bestDay(
                collect($daily)->filter(
                    fn (array $day) =>
                        $day['date'] >= $currentWeekStart->format('Y-m-d')
                        && $day['date'] <= $now->format('Y-m-d')
                ),
                $timezone
            ),
            'best_overall' => $this->bestDay(
                collect($daily),
                $timezone
            ),
            'peak_buying_time' => $this->peakBuyingTime($buckets),
        ];
    }

    private function trafficMetrics(Event $event): array
    {
        $pageViews = EventTrafficView::query()
            ->where('event_id', $event->id)
            ->count();

        $uniqueVisitors = EventTrafficView::query()
            ->where('event_id', $event->id)
            ->distinct()
            ->count('visitor_hash');

        $convertedVisitors = DB::table('orders')
            ->join(
                'order_items',
                'order_items.order_id',
                '=',
                'orders.id'
            )
            ->join(
                'event_traffic_views',
                function ($join) use ($event) {
                    $join
                        ->on(
                            'event_traffic_views.visitor_hash',
                            '=',
                            'orders.visitor_hash'
                        )
                        ->where(
                            'event_traffic_views.event_id',
                            '=',
                            $event->id
                        );
                }
            )
            ->where('order_items.event_id', $event->id)
            ->where('orders.status', Order::STATUS_PAID)
            ->whereNotNull('orders.visitor_hash')
            ->distinct()
            ->count('orders.visitor_hash');

        return [
            'page_views' => (int) $pageViews,
            'unique_visitors' => (int) $uniqueVisitors,
            'converted_visitors' => (int) $convertedVisitors,
            'conversion_percent' => $this->percent(
                (int) $convertedVisitors,
                (int) $uniqueVisitors
            ),
        ];
    }

    private function buyerMetrics(
        Event $event,
        int $totalRevenueMinor
    ): array {
        $uniqueBuyers = $this->uniqueBuyerCount($event);

        return [
            'unique_buyers' => $uniqueBuyers,
            'average_spend_minor' => $uniqueBuyers > 0
                ? (int) round($totalRevenueMinor / $uniqueBuyers)
                : 0,
        ];
    }

    private function uniqueBuyerCount(Event $event): int
    {
        return (int) $this->paidEventOrdersQuery($event)
            ->whereNotNull('orders.customer_email')
            ->distinct()
            ->count('orders.customer_email');
    }

    /**
     * Each buyer is assigned to one source: the source on their most recent
     * paid purchase for this event. That prevents one repeat buyer being counted
     * in several channels at once and keeps percentages understandable.
     *
     * @return array<int, array<string,mixed>>
     */
    private function sourceBreakdown(
        Event $event,
        int $totalUniqueBuyers
    ): array {
        $labels = EventShareLink::query()
            ->where('event_id', $event->id)
            ->pluck('label', 'source_code');

        $latestBuyerSource = [];
        $sourceUnits = [];
        $sourceRevenue = [];

        $purchaseRows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.event_id', $event->id)
            ->where('orders.status', Order::STATUS_PAID)
            ->whereNotNull('orders.customer_email')
            ->orderByDesc('orders.paid_at')
            ->orderByDesc('orders.id')
            ->select([
                'orders.customer_email',
                'orders.paid_at',
                'order_items.source_code',
                'order_items.quantity',
                'order_items.line_total_minor',
            ])
            ->cursor();

        foreach ($purchaseRows as $row) {
            $email = strtolower(trim((string) $row->customer_email));

            if ($email === '') {
                continue;
            }

            $sourceCode = $row->source_code
                ? (string) $row->source_code
                : 'unattributed';

            $sourceUnits[$sourceCode] =
                ($sourceUnits[$sourceCode] ?? 0)
                + (int) $row->quantity;

            $sourceRevenue[$sourceCode] =
                ($sourceRevenue[$sourceCode] ?? 0)
                + (int) $row->line_total_minor;

            if (! isset($latestBuyerSource[$email])) {
                $latestBuyerSource[$email] = $sourceCode;
            }
        }

        $buyerCounts = [];

        foreach ($latestBuyerSource as $sourceCode) {
            $buyerCounts[$sourceCode] =
                ($buyerCounts[$sourceCode] ?? 0) + 1;
        }

        $trafficBySource = EventTrafficView::query()
            ->where('event_id', $event->id)
            ->whereNotNull('source_code')
            ->groupBy('source_code')
            ->selectRaw(
                'source_code,
                 COUNT(*) AS views_count,
                 COUNT(DISTINCT visitor_hash) AS unique_visitors_count'
            )
            ->get()
            ->keyBy('source_code');

        $viewsBySource = $trafficBySource
            ->map(
                fn ($row) => (int) $row->views_count
            );

        $uniqueVisitorsBySource = $trafficBySource
            ->map(
                fn ($row) => (int) $row->unique_visitors_count
            );

        $codes = collect([
            ...array_keys($buyerCounts),
            ...array_keys($sourceUnits),
            ...$viewsBySource->keys()->all(),
            ...$labels->keys()->all(),
        ])->unique()->values();

        return $codes
            ->map(function (string $code) use (
                $labels,
                $buyerCounts,
                $sourceUnits,
                $sourceRevenue,
                $viewsBySource,
                $uniqueVisitorsBySource,
                $totalUniqueBuyers
            ) {
                $buyers = (int) ($buyerCounts[$code] ?? 0);
                $uniqueVisitors =
                    (int) ($uniqueVisitorsBySource->get($code, 0));

                return [
                    'source_code' => $code,
                    'label' => $code === 'unattributed'
                        ? 'Unattributed'
                        : (string) ($labels->get($code) ?? Str::headline($code)),
                    'buyers' => $buyers,
                    'buyer_share_percent' => $this->percent(
                        $buyers,
                        $totalUniqueBuyers
                    ),
                    'views' => (int) $viewsBySource->get($code, 0),
                    'unique_visitors' => $uniqueVisitors,
                    'conversion_percent' => $this->percent(
                        $buyers,
                        $uniqueVisitors
                    ),
                    'units_sold' => (int) ($sourceUnits[$code] ?? 0),
                    'revenue_minor' => (int) ($sourceRevenue[$code] ?? 0),
                ];
            })
            ->sortByDesc('buyers')
            ->values()
            ->all();
    }

    private function paidEventOrdersQuery(Event $event): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.event_id', $event->id)
            ->where('orders.status', Order::STATUS_PAID)
            ->whereNotNull('orders.paid_at');
    }

    private function fillDailySeries(
        array $daily,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if ($end < $start) {
            return [];
        }

        $result = [];
        $cursor = $start->startOfDay();
        $end = $end->startOfDay();

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');

            $result[] = $daily[$key] ?? [
                'date' => $key,
                'units_sold' => 0,
                'revenue_minor' => 0,
                'orders' => 0,
            ];

            $cursor = $cursor->addDay();
        }

        return $result;
    }

    private function bestDay(
        Collection $days,
        string $timezone
    ): ?array {
        if ($days->isEmpty()) {
            return null;
        }

        $best = $days
            ->sort(function (array $a, array $b) {
                if ($a['units_sold'] === $b['units_sold']) {
                    return $b['revenue_minor'] <=> $a['revenue_minor'];
                }

                return $b['units_sold'] <=> $a['units_sold'];
            })
            ->first();

        $date = CarbonImmutable::parse(
            $best['date'],
            $timezone
        );

        return [
            'date' => $best['date'],
            'weekday' => $date->format('l'),
            'label' => $date->format('D, j M'),
            'units_sold' => (int) $best['units_sold'],
            'revenue_minor' => (int) $best['revenue_minor'],
            'orders' => (int) $best['orders'],
        ];
    }

    private function emptyBuyingTimeBuckets(): array
    {
        return [
            '00-04' => [
                'label' => '12-4 AM',
                'orders' => 0,
                'units_sold' => 0,
                'revenue_minor' => 0,
            ],
            '04-08' => [
                'label' => '4-8 AM',
                'orders' => 0,
                'units_sold' => 0,
                'revenue_minor' => 0,
            ],
            '08-12' => [
                'label' => '8 AM-12 PM',
                'orders' => 0,
                'units_sold' => 0,
                'revenue_minor' => 0,
            ],
            '12-16' => [
                'label' => '12-4 PM',
                'orders' => 0,
                'units_sold' => 0,
                'revenue_minor' => 0,
            ],
            '16-19' => [
                'label' => '4-7 PM',
                'orders' => 0,
                'units_sold' => 0,
                'revenue_minor' => 0,
            ],
            '19-23' => [
                'label' => '7-11 PM',
                'orders' => 0,
                'units_sold' => 0,
                'revenue_minor' => 0,
            ],
            '23-24' => [
                'label' => '11 PM-12 AM',
                'orders' => 0,
                'units_sold' => 0,
                'revenue_minor' => 0,
            ],
        ];
    }

    private function buyingTimeBucketKey(int $hour): string
    {
        return match (true) {
            $hour < 4 => '00-04',
            $hour < 8 => '04-08',
            $hour < 12 => '08-12',
            $hour < 16 => '12-16',
            $hour < 19 => '16-19',
            $hour < 23 => '19-23',
            default => '23-24',
        };
    }

    private function peakBuyingTime(array $buckets): ?array
    {
        $bestKey = null;
        $best = null;

        foreach ($buckets as $key => $bucket) {
            if ($best === null) {
                $bestKey = $key;
                $best = $bucket;
                continue;
            }

            if (
                $bucket['orders'] > $best['orders']
                || (
                    $bucket['orders'] === $best['orders']
                    && $bucket['units_sold'] > $best['units_sold']
                )
            ) {
                $bestKey = $key;
                $best = $bucket;
            }
        }

        if ($best === null || $best['orders'] === 0) {
            return null;
        }

        return [
            'bucket' => $bestKey,
            ...$best,
        ];
    }

    private function toEventTime(
        mixed $value,
        string $timezone
    ): ?CarbonImmutable {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)
                ->setTimezone($timezone);
        }

        return CarbonImmutable::parse(
            (string) $value,
            'UTC'
        )->setTimezone($timezone);
    }

    private function eventTimezone(Event $event): string
    {
        $timezone = (string) ($event->event_timezone ?: 'Africa/Lagos');

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return 'Africa/Lagos';
        }
    }

    private function eventPhase(
        Event $event,
        CarbonImmutable $now,
        ?CarbonImmutable $start,
        ?CarbonImmutable $end
    ): string {
        if (($event->status ?? 'draft') !== 'active') {
            return (string) ($event->status ?? 'draft');
        }

        if ($start === null) {
            return 'upcoming';
        }

        if ($now < $start) {
            return 'upcoming';
        }

        if ($end !== null && $now <= $end) {
            return 'live';
        }

        if ($end === null && $now->isSameDay($start)) {
            return 'live';
        }

        return 'ended';
    }

    private function daysToGo(
        CarbonImmutable $now,
        ?CarbonImmutable $start
    ): ?int {
        if ($start === null) {
            return null;
        }

        if ($start <= $now) {
            return 0;
        }

        return (int) $now
            ->startOfDay()
            ->diffInDays($start->startOfDay());
    }

    private function publicPath(Event $event): string
    {
        $slug = Str::slug((string) $event->title);

        return '/events/' . $event->id . '-' . $slug;
    }

    private function percent(int $numerator, int $denominator): float
    {
        if ($denominator <= 0 || $numerator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 1);
    }

    private function growthPercent(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function growthState(int $current, int $previous): string
    {
        if ($previous === 0 && $current > 0) {
            return 'new';
        }

        if ($current > $previous) {
            return 'up';
        }

        if ($current < $previous) {
            return 'down';
        }

        return 'flat';
    }
}
