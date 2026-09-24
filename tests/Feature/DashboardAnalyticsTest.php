<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\EventAttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => 'sk_test_dashboard',
            'services.paystack.base_url' => 'https://api.paystack.co',
            'commerce.currency' => 'NGN',
            'commerce.reservation_minutes' => 20,
            'commerce.platform_fee_bps' => 0,
            'commerce.dynamic_splits_enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_only_lists_and_opens_owned_events(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        [$ownedEvent] = $this->paidEvent($owner, 'Owned Event');
        [$foreignEvent] = $this->paidEvent($other, 'Private Event');

        Sanctum::actingAs($owner);

        $this->getJson('/api/dashboard/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedEvent);

        $this->getJson(
            "/api/dashboard/events/{$ownedEvent}/overview"
        )->assertOk();

        $this->getJson(
            "/api/dashboard/events/{$foreignEvent}/overview"
        )->assertForbidden();
    }

    public function test_table_purchase_counts_as_one_sale_but_keeps_admission_capacity_separate(): void
    {
        $owner = User::factory()->create();

        [$eventId, $ticketTypeId] = $this->paidEvent(
            $owner,
            'Table Event',
            '250000.00',
            30,
            'table',
            5
        );

        $this->insertPaidOrder(
            $ticketTypeId,
            1,
            25000000,
            Carbon::parse('2026-09-23 18:00:00', 'UTC')
        );

        Sanctum::actingAs($owner);

        $response = $this->getJson(
            "/api/dashboard/events/{$eventId}/overview"
        )->assertOk();

        $response
            ->assertJsonPath('data.sales.units_sold', 1)
            ->assertJsonPath('data.sales.capacity_units', 30)
            ->assertJsonPath('data.sales.admission_entries_covered', 5)
            ->assertJsonPath('data.sales.admission_capacity', 150)
            ->assertJsonPath('data.ticket_types.0.sold_units', 1)
            ->assertJsonPath('data.ticket_types.0.available_units', 29);
    }

    public function test_guest_view_is_deduplicated_and_checkout_gets_server_side_source_attribution(): void
    {
        $owner = User::factory()->create();

        [$eventId, $ticketTypeId] = $this->paidEvent(
            $owner,
            'Attributed Event'
        );

        Sanctum::actingAs($owner);

        $this->getJson(
            "/api/dashboard/events/{$eventId}/share-links"
        )->assertOk();

        auth('sanctum')->forgetUser();

        $visitorId = (string) Str::uuid();

        $payload = [
            'visitor_id' => $visitorId,
            'source_code' => 'wa',
        ];

        $this->postJson(
            "/api/events/{$eventId}/track-view",
            $payload
        )->assertOk();

        $this->postJson(
            "/api/events/{$eventId}/track-view",
            $payload
        )->assertOk();

        $this->assertDatabaseCount('event_traffic_views', 1);

        Http::fake(function (HttpRequest $request) {
            if (str_contains(
                $request->url(),
                '/transaction/initialize'
            )) {
                $reference = (string) $request['reference'];

                return Http::response([
                    'status' => true,
                    'data' => [
                        'authorization_url' =>
                            'https://checkout.paystack.test/' . $reference,
                        'access_code' =>
                            'access_' . $reference,
                        'reference' => $reference,
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $checkoutToken = (string) Str::uuid();

        $initialize = $this->postJson(
            '/api/checkout/initialize',
            [
                'checkout_token' => $checkoutToken,
                'visitor_id' => $visitorId,
                'customer' => [
                    'first_name' => 'Ada',
                    'last_name' => 'Buyer',
                    'email' => 'ada@example.com',
                    'phone' => '+2348000000000',
                ],
                'items' => [
                    [
                        'ticket_type_id' => $ticketTypeId,
                        'quantity' => 1,
                    ],
                ],
            ]
        )->assertCreated();

        $order = Order::query()
            ->where(
                'public_id',
                $initialize->json('data.order.public_id')
            )
            ->firstOrFail();

        $this->assertNotSame(
            $visitorId,
            $order->visitor_hash
        );

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'event_id' => $eventId,
            'source_code' => 'wa',
        ]);
    }

    public function test_overview_calculates_sales_revenue_week_comparison_days_peak_time_and_conversion(): void
    {
        Carbon::setTestNow(
            Carbon::parse(
                '2026-09-23 20:00:00',
                'Africa/Lagos'
            )
        );

        $owner = User::factory()->create();

        [$eventId, $ticketTypeId] = $this->paidEvent(
            $owner,
            'Metrics Event',
            '1000.00',
            100
        );

        $this->insertPaidOrder(
            $ticketTypeId,
            2,
            200000,
            Carbon::parse(
                '2026-09-22 19:30:00',
                'Africa/Lagos'
            )->utc(),
            'buyer1@example.com'
        );

        $this->insertPaidOrder(
            $ticketTypeId,
            3,
            300000,
            Carbon::parse(
                '2026-09-23 19:30:00',
                'Africa/Lagos'
            )->utc(),
            'buyer2@example.com'
        );

        $this->insertPaidOrder(
            $ticketTypeId,
            1,
            100000,
            Carbon::parse(
                '2026-09-16 20:00:00',
                'Africa/Lagos'
            )->utc(),
            'old@example.com'
        );

        $attribution = app(EventAttributionService::class);

        $visitorA = (string) Str::uuid();
        $visitorB = (string) Str::uuid();

        $event = Event::query()->findOrFail($eventId);

        $attribution->recordView(
            $event,
            null,
            $visitorA,
            'wa',
            '127.0.0.1',
            'test-agent'
        );

        $attribution->recordView(
            $event,
            null,
            $visitorB,
            'ig',
            '127.0.0.2',
            'test-agent'
        );

        $visitorHash = $attribution->hashVisitor($visitorA);

        DB::table('orders')
            ->where('customer_email', 'buyer2@example.com')
            ->update(['visitor_hash' => $visitorHash]);

        $buyer2OrderId = DB::table('orders')
            ->where('customer_email', 'buyer2@example.com')
            ->value('id');

        DB::table('order_items')
            ->where('order_id', $buyer2OrderId)
            ->where('event_id', $eventId)
            ->update(['source_code' => 'wa']);

        Sanctum::actingAs($owner);

        $response = $this->getJson(
            "/api/dashboard/events/{$eventId}/overview"
        )->assertOk();

        $response
            ->assertJsonPath('data.sales.units_sold', 6)
            ->assertJsonPath('data.sales.this_week_units', 5)
            ->assertJsonPath('data.sales.previous_comparable_units', 1)
            ->assertJsonPath('data.revenue.total_minor', 600000)
            ->assertJsonPath('data.revenue.this_week_minor', 500000)
            ->assertJsonPath('data.buyers.unique_buyers', 3)
            ->assertJsonPath('data.buyers.average_spend_minor', 200000)
            ->assertJsonPath('data.best_sales_day.this_week.weekday', 'Wednesday')
            ->assertJsonPath('data.best_sales_day.overall.weekday', 'Wednesday')
            ->assertJsonPath('data.peak_buying_time.label', '7-11 PM')
            ->assertJsonPath('data.traffic.page_views', 2)
            ->assertJsonPath('data.traffic.unique_visitors', 2)
            ->assertJsonPath('data.traffic.converted_visitors', 1)
            ->assertJsonPath('data.traffic.conversion_percent', 50.0);
    }

    public function test_custom_share_links_are_owner_only_and_codes_are_sanitized(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        [$eventId] = $this->paidEvent(
            $owner,
            'Share Event'
        );

        Sanctum::actingAs($other);

        $this->postJson(
            "/api/dashboard/events/{$eventId}/share-links",
            ['label' => 'Church Alumni Group']
        )->assertForbidden();

        Sanctum::actingAs($owner);

        $response = $this->postJson(
            "/api/dashboard/events/{$eventId}/share-links",
            ['label' => 'Church Alumni Group']
        )->assertCreated();

        $codes = collect($response->json('data'))
            ->pluck('source_code');

        $this->assertTrue(
            $codes->contains(
                fn ($code) =>
                    is_string($code)
                    && str_starts_with(
                        $code,
                        'church-alumni-group'
                    )
            )
        );
    }

    private function paidEvent(
        User $owner,
        string $title = 'Test Event',
        string $price = '1000.00',
        int $capacity = 10,
        string $unitType = 'individual',
        int $peoplePerUnit = 1
    ): array {
        $categoryId = DB::table('categories')
            ->insertGetId([
                'name' => 'Category ' . Str::ulid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $organizerId = DB::table('organizers')
            ->insertGetId([
                'name' => 'Organizer ' . Str::ulid(),
                'email' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $addressId = DB::table('event_addresses')
            ->insertGetId([
                'venue_name' => 'Eko Hotel & Suites',
                'address_line1' => '',
                'address_line2' => null,
                'city' => 'Lagos',
                'state' => 'Lagos',
                'postal_code' => '',
                'country' => 'Nigeria',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $eventId = DB::table('events')
            ->insertGetId([
                'title' => $title,
                'description' => 'Dashboard analytics test event',
                'category_id' => $categoryId,
                'organizer_id' => $organizerId,
                'address_id' => $addressId,
                'date' => now()->addMonth(),
                'ends_at' => now()->addMonth()->addHours(3),
                'event_timezone' => 'Africa/Lagos',
                'event_type' => 'one_time',
                'event_format' => 'in-person',
                'price' => $price,
                'total_tickets' => $capacity * $peoplePerUnit,
                'duration' => null,
                'featured' => false,
                'main_image' => 'https://example.com/event.jpg',
                'banner_image' => null,
                'created_by' => $owner->id,
                'status' => 'active',
                'ticket_mode' => 'paid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $ticketTypeId = DB::table('ticket_types')
            ->insertGetId([
                'event_id' => $eventId,
                'name' => $unitType === 'table'
                    ? 'Gold Table'
                    : 'General Admission',
                'unit_type' => $unitType,
                'color' => '#ec4899',
                'price' => $price,
                'quantity' => $capacity,
                'people_per_unit' => $peoplePerUnit,
                'max_per_person' => 10,
                'visible' => true,
                'description' => null,
                'sales_start_date' => now()->subDay(),
                'sales_end_date' => now()->addWeeks(2),
                'is_featured' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return [$eventId, $ticketTypeId];
    }

    private function insertPaidOrder(
        int $ticketTypeId,
        int $quantity,
        int $lineTotalMinor,
        Carbon $paidAt,
        string $email = 'buyer@example.com'
    ): int {
        $ticketType = DB::table('ticket_types')
            ->where('id', $ticketTypeId)
            ->first();

        $event = DB::table('events')
            ->where('id', $ticketType->event_id)
            ->first();

        $orderId = DB::table('orders')
            ->insertGetId([
                'public_id' => (string) Str::ulid(),
                'checkout_token_hash' => hash(
                    'sha256',
                    (string) Str::uuid()
                ),
                'visitor_hash' => null,
                'cart_fingerprint' => hash(
                    'sha256',
                    (string) Str::uuid()
                ),
                'user_id' => null,
                'customer_first_name' => 'Test',
                'customer_last_name' => 'Buyer',
                'customer_email' => $email,
                'customer_phone' => '+2348111111111',
                'currency' => 'NGN',
                'subtotal_minor' => $lineTotalMinor,
                'discount_minor' => 0,
                'total_minor' => $lineTotalMinor,
                'status' => Order::STATUS_PAID,
                'expires_at' => $paidAt->copy()->addMinutes(20),
                'paid_at' => $paidAt,
                'created_at' => $paidAt,
                'updated_at' => $paidAt,
            ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'event_id' => $event->id,
            'ticket_type_id' => $ticketTypeId,
            'seller_user_id' => $event->created_by,
            'source_code' => null,
            'attributed_event_view_id' => null,
            'event_title' => $event->title,
            'ticket_name' => $ticketType->name,
            'unit_type' => $ticketType->unit_type,
            'people_per_unit' => $ticketType->people_per_unit,
            'unit_price_minor' => intdiv(
                $lineTotalMinor,
                max(1, $quantity)
            ),
            'quantity' => $quantity,
            'line_total_minor' => $lineTotalMinor,
            'currency' => 'NGN',
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);

        return $orderId;
    }
}
