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

        if ($event->ticket_mode === 'free' && ! $ticketPerformance) {
            $salesCapacity = $availableUnits = max(0, (int) $event->total_tickets);
        }

        $admissionCapacity = collect($ticketPerformance)->sum(
            fn (array $tier) => (int) $tier['effective_capacity_units']
                * max(1, (int) $tier['people_per_unit'])
        );

        if ($event->ticket_mode === 'free') {
            $admissionCapacity = max(0, (int) $event->total_tickets);
        }

        $metrics = app(EventMetricsService::class)->metrics($event);
        $admissionEntriesCovered = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.event_id', $event->id)->whereIn('orders.status', ['paid', 'registered'])
            ->selectRaw('COALESCE(SUM((order_items.quantity - order_items.refunded_quantity) * order_items.people_per_unit),0) AS total')->value('total');

        return [
            'metrics' => $metrics,
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
                'previous_comparable_units' => (int) $timeline['previous_comparable_units'],
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
                'admission_entries_covered' => (int) $admissionEntriesCovered,
                'actual_attendance' => $metrics['actual_attendance'],
                'currently_inside' => $metrics['currently_inside'],
                'tracking_supported' => true,
            ],
            'revenue' => [
                'currency' => strtoupper((string) config('commerce.currency', 'NGN')),
                'total_minor' => (int) $timeline['total_revenue_minor'],
                'this_week_minor' => (int) $timeline['this_week_revenue_minor'],
                'previous_comparable_minor' => (int) $timeline['previous_comparable_revenue_minor'],
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
                'max_days' => 1095,
                'truncated' => $timeline['truncated'],
                'from' => $timeline['series_all'][0]['date'] ?? null,
            ],
            'best_sales_day' => [
                'scope' => 'chart_window',
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
                    'buyer_share_percent' => (float) ($stats['buyer_share_percent'] ?? 0),
                    'conversion_percent' => (float) ($stats['conversion_percent'] ?? 0),
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
            ->whereIn('orders.status', [Order::STATUS_PAID, 'registered', 'partially_refunded'])
            ->groupBy('order_items.ticket_type_id')
            ->selectRaw(
                'order_items.ticket_type_id,
                 COALESCE(SUM(order_items.quantity), 0) AS sold_units,
                 COALESCE(SUM(order_items.line_total_minor - order_items.discount_minor), 0) AS revenue_minor'
            )
            ->get()
            ->keyBy(fn ($row) => (int) $row->ticket_type_id);

        $commitments = app(\App\Services\Commerce\InventoryService::class)->commitments($ids);

        return $ticketTypes
            ->map(function ($type) use ($paid, $commitments) {
                $sale = $paid->get((int) $type->id);

                $sold = (int) ($commitments[$type->id]['sold_units'] ?? 0);
                $reservedUnits = (int) ($commitments[$type->id]['reserved_units'] ?? 0);
                $configuredCapacity = max(0, (int) $type->quantity);

                // A hidden tier with history is preserved by CreateEventService.
                // Once hidden, its unsold inventory is no longer offered, so the
                // dashboard denominator becomes the number already sold.
                $effectiveCapacity = ($type->visible || $type->is_registration)
                    ? max($configuredCapacity, $sold)
                    : $sold + $reservedUnits;

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

    /** SQL aggregates at most 1,095 local days into daily and buying-time buckets. */
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

        // Aggregate in SQL after converting UTC instants with IANA transition offsets.
        // Numeric offsets avoid optional MySQL timezone tables and preserve DST and half-hour zones.
        $instant = 'COALESCE(orders.paid_at, orders.created_at)';
        $first = $this->paidEventOrdersQuery($event)->min(DB::raw($instant));
        $chartStart = $first ? CarbonImmutable::parse($first, 'UTC')->setTimezone($timezone)->startOfDay()->max($now->startOfDay()->subDays(1094)) : $now->startOfDay();
        $local = $this->localTimeSql($instant, $timezone, $chartStart->utc(), $now->utc()->addDay());
        $hour = DB::getDriverName() === 'sqlite' ? "CAST(strftime('%H', {$local}) AS INTEGER)" : "HOUR({$local})";
        $bucket = "CASE WHEN {$hour} < 4 THEN '00-04' WHEN {$hour} < 8 THEN '04-08' WHEN {$hour} < 12 THEN '08-12' WHEN {$hour} < 16 THEN '12-16' WHEN {$hour} < 19 THEN '16-19' WHEN {$hour} < 23 THEN '19-23' ELSE '23-24' END";
        $rows = $this->paidEventOrdersQuery($event)->whereRaw("{$instant} >= ?", [$chartStart->utc()])->whereRaw("{$instant} <= ?", [$now->utc()])
            ->selectRaw("DATE({$local}) AS day, {$bucket} AS bucket, SUM(order_items.quantity) AS units_sold, SUM(order_items.line_total_minor - order_items.discount_minor) AS revenue_minor, COUNT(DISTINCT orders.id) AS orders")
            ->groupBy('day', 'bucket')->orderBy('day')->get();
        foreach ($rows as $row) {
            $daily[$row->day] ??= ['date' => $row->day, 'units_sold' => 0, 'revenue_minor' => 0, 'orders' => 0];
            foreach (['units_sold', 'revenue_minor', 'orders'] as $key) {
                $daily[$row->day][$key] += (int) $row->$key;
                $buckets[$row->bucket][$key] += (int) $row->$key;
            }
        }
        $totals = $this->paidEventOrdersQuery($event)->selectRaw('COALESCE(SUM(order_items.quantity),0) AS units, COALESCE(SUM(order_items.line_total_minor - order_items.discount_minor),0) AS revenue')->first();
        $totalUnits = (int) $totals->units;
        $totalRevenue = (int) $totals->revenue;
        $firstSaleDate = $first ? CarbonImmutable::parse($first, 'UTC')->setTimezone($timezone)->startOfDay() : null;
        foreach ([[$currentWeekStart, $now, 'current'], [$previousWeekStart, $previousComparableEnd, 'previous']] as [$from,$to,$period]) {
            $sum = $this->paidEventOrdersQuery($event)->whereRaw("{$instant} BETWEEN ? AND ?", [$from->utc(), $to->utc()])->selectRaw('COALESCE(SUM(order_items.quantity),0) AS units, COALESCE(SUM(order_items.line_total_minor - order_items.discount_minor),0) AS revenue')->first();
            if ($period === 'current') {
                $thisWeekUnits = (int) $sum->units;
                $thisWeekRevenue = (int) $sum->revenue;
            } else {
                $previousUnits = (int) $sum->units;
                $previousRevenue = (int) $sum->revenue;
            }
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

        $allStart = $firstSaleDate && $firstSaleDate > $today->subDays(1094) ? $firstSaleDate : $today->subDays(1094);
        if (! $firstSaleDate) {
            $allStart = $today;
        }

        $seriesAll = $this->fillDailySeries(
            $daily,
            $allStart,
            $today
        );

        return [
            'truncated' => $firstSaleDate && $firstSaleDate < $today->subDays(1094),
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
                    fn (array $day) => $day['date'] >= $currentWeekStart->format('Y-m-d')
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
            ->whereIn('orders.status', [Order::STATUS_PAID, 'registered', 'partially_refunded'])
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
            ->count(DB::raw('LOWER(TRIM(orders.customer_email))'));
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

        $base = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('i.event_id', $event->id)->whereIn('o.status', ['paid', 'registered', 'partially_refunded', 'refunded']);
        $latest = (clone $base)->groupByRaw('LOWER(TRIM(o.customer_email))')->selectRaw('LOWER(TRIM(o.customer_email)) AS email, MAX(o.id) AS order_id');
        $lastSources = DB::query()->fromSub($latest, 'last_order')->join('order_items as i', function ($j) use ($event) {
            $j->on('i.order_id', '=', 'last_order.order_id')->where('i.event_id', $event->id);
        })
            ->groupBy('last_order.email')->selectRaw("last_order.email, COALESCE(MIN(i.source_code), 'unattributed') AS source_code");
        $buyerCounts = DB::query()->fromSub($lastSources, 'buyer_sources')->groupBy('source_code')->selectRaw('source_code, COUNT(*) AS buyers')->pluck('buyers', 'source_code')->all();
        $sales = (clone $base)->where('i.currency', strtoupper(config('commerce.currency', 'NGN')))
            ->groupByRaw("COALESCE(i.source_code, 'unattributed')")->selectRaw("COALESCE(i.source_code, 'unattributed') AS source_code, SUM(i.quantity) AS units, SUM(i.line_total_minor - i.discount_minor) AS revenue")->get();
        $sourceUnits = $sales->pluck('units', 'source_code')->all();
        $sourceRevenue = $sales->pluck('revenue', 'source_code')->all();
        $conversions = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->join('event_traffic_views as v', 'v.id', '=', 'i.attributed_event_view_id')
            ->where('i.event_id', $event->id)->where('v.event_id', $event->id)->whereIn('o.status', ['paid', 'registered', 'partially_refunded', 'refunded'])
            ->whereColumn('o.visitor_hash', 'v.visitor_hash')->groupBy('i.source_code')->selectRaw('i.source_code, COUNT(DISTINCT o.visitor_hash) AS visitors')->pluck('visitors', 'i.source_code');

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
                $totalUniqueBuyers, $conversions
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
                        (int) ($conversions[$code] ?? 0),
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
            ->whereIn('orders.status', [Order::STATUS_PAID, 'registered', 'partially_refunded', 'refunded'])
            ->where('order_items.currency', strtoupper(config('commerce.currency', 'NGN')));
    }

    private function localTimeSql(string $instant, string $timezone, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $zone = new \DateTimeZone($timezone);
        $transitions = $zone->getTransitions($from->getTimestamp(), $to->getTimestamp());
        $offset = (int) $zone->getOffset($from);
        $cases = [];
        foreach ($transitions ?: [] as $transition) {
            $date = gmdate('Y-m-d H:i:s', $transition['ts']);
            $cases[] = "WHEN {$instant} >= '{$date}' THEN ".(int) $transition['offset'];
        }
        $seconds = $cases ? '(CASE '.implode(' ', array_reverse($cases)).' ELSE '.$offset.' END)' : (string) $offset;

        return DB::getDriverName() === 'sqlite' ? "datetime({$instant}, printf('%+d seconds', {$seconds}))" : "DATE_ADD({$instant}, INTERVAL {$seconds} SECOND)";
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

        return '/events/'.$event->id.'-'.$slug;
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
