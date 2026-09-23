<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercePaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'sk_test_kakatickets';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => $this->secret,
            'services.paystack.base_url' => 'https://api.paystack.co',
            'commerce.currency' => 'NGN',
            'commerce.reservation_minutes' => 20,
            'commerce.platform_fee_bps' => 0,
            'commerce.paystack_splits_enabled' => false,
        ]);
    }

    public function test_multi_event_quote_uses_server_prices(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        [$eventA, $ticketA] = $this->paidEvent(
            $ownerA,
            'Event A',
            '1000.00'
        );

        [$eventB, $ticketB] = $this->paidEvent(
            $ownerB,
            'Event B',
            '2500.00'
        );

        $response = $this->postJson(
            '/api/checkout/quote',
            [
                'items' => [
                    [
                        'ticket_type_id' => $ticketA,
                        'quantity' => 2,
                        'price' => 1,
                    ],
                    [
                        'ticket_type_id' => $ticketB,
                        'quantity' => 1,
                        'price' => 1,
                    ],
                ],
            ]
        )->assertOk();

        $response
            ->assertJsonPath('data.total_minor', 450000)
            ->assertJsonPath('data.items.0.event_id', $eventA)
            ->assertJsonPath('data.items.1.event_id', $eventB);
    }

    public function test_pending_reservation_blocks_oversell_without_decrementing_capacity(): void
    {
        $owner = User::factory()->create();

        [, $ticketTypeId] = $this->paidEvent(
            $owner,
            'One Seat',
            '1000.00',
            1
        );

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload(
                $ticketTypeId,
                $token
            )
        )->assertCreated();

        $this->postJson(
            '/api/checkout/quote',
            [
                'items' => [
                    [
                        'ticket_type_id' => $ticketTypeId,
                        'quantity' => 1,
                    ],
                ],
            ]
        )->assertUnprocessable();

        $this->assertDatabaseHas(
            'ticket_types',
            [
                'id' => $ticketTypeId,
                'quantity' => 1,
            ]
        );
    }

    public function test_initialize_is_idempotent_and_paystack_runs_after_db_transaction(): void
    {
        $owner = User::factory()->create();
        [, $ticketTypeId] = $this->paidEvent($owner);

        $baselineTransactionLevel = DB::transactionLevel();`r`n        $transactionLevel = null;

        Http::fake(function (HttpRequest $request) use (&$transactionLevel) {
            $transactionLevel = DB::transactionLevel();
            $reference = (string) $request['reference'];

            return Http::response(
                [
                    'status' => true,
                    'message' => 'Authorization URL created',
                    'data' => [
                        'authorization_url' =>
                            'https://checkout.paystack.test/' . $reference,
                        'access_code' => 'access_' . $reference,
                        'reference' => $reference,
                    ],
                ],
                200
            );
        });

        $token = (string) Str::uuid();
        $payload = $this->checkoutPayload(
            $ticketTypeId,
            $token
        );

        $first = $this->postJson(
            '/api/checkout/initialize',
            $payload
        )->assertCreated();

        $second = $this->postJson(
            '/api/checkout/initialize',
            $payload
        )->assertCreated();

        $this->assertSame($baselineTransactionLevel, $transactionLevel);
        $this->assertSame(
            $first->json('data.order.public_id'),
            $second->json('data.order.public_id')
        );
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
    }

    public function test_same_token_with_changed_cart_is_rejected(): void
    {
        $owner = User::factory()->create();
        [, $ticketA] = $this->paidEvent($owner, 'A');
        [, $ticketB] = $this->paidEvent($owner, 'B');

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload($ticketA, $token)
        )->assertCreated();

        $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload($ticketB, $token)
        )->assertUnprocessable();
    }

    public function test_guest_order_read_requires_correct_checkout_token(): void
    {
        $owner = User::factory()->create();
        [, $ticketTypeId] = $this->paidEvent($owner);

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        $result = $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload($ticketTypeId, $token)
        )->assertCreated();

        $publicId = $result->json('data.order.public_id');

        $this->withHeader(
            'X-Checkout-Token',
            (string) Str::uuid()
        )
            ->getJson("/api/checkout/orders/{$publicId}")
            ->assertForbidden();

        $this->withHeader(
            'X-Checkout-Token',
            $token
        )
            ->getJson("/api/checkout/orders/{$publicId}")
            ->assertOk()
            ->assertJsonPath('data.public_id', $publicId);
    }

    public function test_authenticated_buyer_can_read_own_order_without_guest_token(): void
    {
        $owner = User::factory()->create();
        $buyer = User::factory()->create();
        $other = User::factory()->create();

        [, $ticketTypeId] = $this->paidEvent($owner);

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        Sanctum::actingAs($buyer);

        $result = $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload($ticketTypeId, $token)
        )->assertCreated();

        $publicId = $result->json('data.order.public_id');

        Sanctum::actingAs($buyer);

        $this->getJson("/api/checkout/orders/{$publicId}")
            ->assertOk();

        Sanctum::actingAs($other);

        $this->getJson("/api/checkout/orders/{$publicId}")
            ->assertForbidden();
    }

    public function test_successful_verify_issues_exactly_one_ticket_per_unit_and_is_idempotent(): void
    {
        $owner = User::factory()->create();

        [, $ticketTypeId] = $this->paidEvent(
            $owner,
            'Seats',
            '1000.00',
            10
        );

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        $initialize = $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload(
                $ticketTypeId,
                $token,
                2
            )
        )->assertCreated();

        $reference = $initialize->json(
            'data.payment.reference'
        );

        $this->fakeVerify(
            $reference,
            200000
        );

        $this->withHeader(
            'X-Checkout-Token',
            $token
        )
            ->postJson(
                "/api/checkout/payments/{$reference}/verify"
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                Order::STATUS_PAID
            );

        $this->assertDatabaseCount('tickets', 2);

        $this->withHeader(
            'X-Checkout-Token',
            $token
        )
            ->postJson(
                "/api/checkout/payments/{$reference}/verify"
            )
            ->assertOk();

        $this->assertDatabaseCount('tickets', 2);
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_table_unit_issues_one_ticket_with_multi_person_admit_count(): void
    {
        $owner = User::factory()->create();

        [, $ticketTypeId] = $this->paidEvent(
            $owner,
            'Gold Table',
            '250000.00',
            5,
            'table',
            10
        );

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        $initialize = $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload(
                $ticketTypeId,
                $token
            )
        )->assertCreated();

        $reference = $initialize->json(
            'data.payment.reference'
        );

        $this->fakeVerify(
            $reference,
            25000000
        );

        $response = $this->withHeader(
            'X-Checkout-Token',
            $token
        )
            ->postJson(
                "/api/checkout/payments/{$reference}/verify"
            )
            ->assertOk();

        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseHas(
            'tickets',
            ['admit_count' => 10]
        );

        $this->assertCount(
            1,
            $response->json(
                'data.items.0.tickets'
            )
        );

        $this->assertSame(
            10,
            $response->json(
                'data.items.0.tickets.0.admit_count'
            )
        );
    }

    public function test_wrong_amount_persists_review_and_issues_no_tickets(): void
    {
        $owner = User::factory()->create();
        [, $ticketTypeId] = $this->paidEvent($owner);

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        $initialize = $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload($ticketTypeId, $token)
        )->assertCreated();

        $reference = $initialize->json(
            'data.payment.reference'
        );

        $this->fakeVerify(
            $reference,
            1
        );

        $this->withHeader(
            'X-Checkout-Token',
            $token
        )
            ->postJson(
                "/api/checkout/payments/{$reference}/verify"
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                Order::STATUS_PAYMENT_REVIEW
            );

        $this->assertDatabaseHas(
            'payments',
            [
                'reference' => $reference,
                'status' => Payment::STATUS_REVIEW,
            ]
        );

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_wrong_currency_persists_review_and_issues_no_tickets(): void
    {
        $owner = User::factory()->create();
        [, $ticketTypeId] = $this->paidEvent($owner);

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        $initialize = $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload($ticketTypeId, $token)
        )->assertCreated();

        $reference = $initialize->json(
            'data.payment.reference'
        );

        $this->fakeVerify(
            $reference,
            100000,
            now()->toIso8601String(),
            'USD'
        );

        $this->withHeader(
            'X-Checkout-Token',
            $token
        )
            ->postJson(
                "/api/checkout/payments/{$reference}/verify"
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                Order::STATUS_PAYMENT_REVIEW
            );

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        $body = json_encode(
            [
                'event' => 'charge.success',
                'data' => [
                    'reference' => 'KT-unknown',
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $this->call(
            'POST',
            '/api/payments/paystack/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => 'bad-signature',
            ],
            $body
        )->assertStatus(401);
    }

    public function test_valid_duplicate_webhook_is_idempotent(): void
    {
        $owner = User::factory()->create();
        [, $ticketTypeId] = $this->paidEvent($owner);

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        $initialize = $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload($ticketTypeId, $token)
        )->assertCreated();

        $reference = $initialize->json(
            'data.payment.reference'
        );

        $this->fakeVerify(
            $reference,
            100000
        );

        $body = json_encode(
            [
                'event' => 'charge.success',
                'data' => [
                    'reference' => $reference,
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $signature = hash_hmac(
            'sha512',
            $body,
            $this->secret
        );

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->call(
                'POST',
                '/api/payments/paystack/webhook',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
                ],
                $body
            )->assertOk();
        }

        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_unknown_valid_webhook_reference_is_acknowledged(): void
    {
        $body = json_encode(
            [
                'event' => 'charge.success',
                'data' => [
                    'reference' => 'KT-not-ours',
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $signature = hash_hmac(
            'sha512',
            $body,
            $this->secret
        );

        $this->call(
            'POST',
            '/api/payments/paystack/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
            ],
            $body
        )
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_late_payment_that_lost_inventory_moves_to_review(): void
    {
        $owner = User::factory()->create();

        [, $ticketTypeId] = $this->paidEvent(
            $owner,
            'Last Seat',
            '1000.00',
            1
        );

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        $initialize = $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload($ticketTypeId, $token)
        )->assertCreated();

        $reference = $initialize->json(
            'data.payment.reference'
        );

        $lateOrder = Order::query()
            ->where(
                'public_id',
                $initialize->json(
                    'data.order.public_id'
                )
            )
            ->firstOrFail();

        $lateOrder->update([
            'expires_at' => now()->subMinutes(5),
        ]);

        $this->insertPaidOrder(
            $ticketTypeId,
            1,
            100000
        );

        $this->fakeVerify(
            $reference,
            100000,
            now()->toIso8601String()
        );

        $this->withHeader(
            'X-Checkout-Token',
            $token
        )
            ->postJson(
                "/api/checkout/payments/{$reference}/verify"
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                Order::STATUS_PAYMENT_REVIEW
            );

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_late_delivery_is_honored_when_provider_paid_before_expiry(): void
    {
        $owner = User::factory()->create();
        [, $ticketTypeId] = $this->paidEvent($owner);

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        $initialize = $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload($ticketTypeId, $token)
        )->assertCreated();

        $reference = $initialize->json(
            'data.payment.reference'
        );

        $order = Order::query()
            ->where(
                'public_id',
                $initialize->json(
                    'data.order.public_id'
                )
            )
            ->firstOrFail();

        $order->update([
            'expires_at' => now()->subMinutes(5),
        ]);

        $paidAt = $order->fresh()->expires_at
            ->copy()
            ->subMinute();

        $this->fakeVerify(
            $reference,
            100000,
            $paidAt->toIso8601String()
        );

        $this->withHeader(
            'X-Checkout-Token',
            $token
        )
            ->postJson(
                "/api/checkout/payments/{$reference}/verify"
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                Order::STATUS_PAID
            );

        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_one_multi_event_initialize_creates_one_order_two_items_and_two_allocations(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        [, $ticketA] = $this->paidEvent(
            $ownerA,
            'Event A',
            '1000.00'
        );

        [, $ticketB] = $this->paidEvent(
            $ownerB,
            'Event B',
            '2500.00'
        );

        $sentAmount = null;

        Http::fake(function (HttpRequest $request) use (&$sentAmount) {
            $sentAmount = (int) $request['amount'];
            $reference = (string) $request['reference'];

            return Http::response(
                [
                    'status' => true,
                    'message' => 'Authorization URL created',
                    'data' => [
                        'authorization_url' =>
                            'https://checkout.paystack.test/' . $reference,
                        'access_code' => 'access_' . $reference,
                        'reference' => $reference,
                    ],
                ],
                200
            );
        });

        $this->postJson(
            '/api/checkout/initialize',
            [
                'checkout_token' => (string) Str::uuid(),
                'visitor_id' => (string) Str::uuid(),
                'customer' => [
                    'first_name' => 'Ada',
                    'last_name' => 'Buyer',
                    'email' => 'ada@example.com',
                    'phone' => '+2348000000000',
                ],
                'items' => [
                    [
                        'ticket_type_id' => $ticketA,
                        'quantity' => 2,
                    ],
                    [
                        'ticket_type_id' => $ticketB,
                        'quantity' => 1,
                    ],
                ],
            ]
        )->assertCreated();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseCount('order_allocations', 2);
        $this->assertSame(450000, $sentAmount);
    }

    public function test_expired_retry_marks_order_expired_and_requires_new_token(): void
    {
        $owner = User::factory()->create();
        [, $ticketTypeId] = $this->paidEvent($owner);

        $this->fakeInitialize();

        $token = (string) Str::uuid();
        $payload = $this->checkoutPayload(
            $ticketTypeId,
            $token
        );

        $initialize = $this->postJson(
            '/api/checkout/initialize',
            $payload
        )->assertCreated();

        Order::query()
            ->where(
                'public_id',
                $initialize->json(
                    'data.order.public_id'
                )
            )
            ->update([
                'expires_at' => now()->subMinute(),
            ]);

        $this->postJson(
            '/api/checkout/initialize',
            $payload
        )->assertUnprocessable();

        $this->assertDatabaseHas(
            'orders',
            [
                'public_id' =>
                    $initialize->json(
                        'data.order.public_id'
                    ),
                'status' => Order::STATUS_EXPIRED,
            ]
        );
    }

    public function test_failed_payment_can_retry_with_new_payment_attempt(): void
    {
        $owner = User::factory()->create();
        [, $ticketTypeId] = $this->paidEvent($owner);

        $this->fakeInitialize();

        $token = (string) Str::uuid();
        $payload = $this->checkoutPayload(
            $ticketTypeId,
            $token
        );

        $initialize = $this->postJson(
            '/api/checkout/initialize',
            $payload
        )->assertCreated();

        $reference = $initialize->json(
            'data.payment.reference'
        );

        $this->fakeVerifyStatus(
            $reference,
            'failed',
            100000
        );

        $this->withHeader(
            'X-Checkout-Token',
            $token
        )
            ->postJson(
                "/api/checkout/payments/{$reference}/verify"
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                Order::STATUS_PAYMENT_FAILED
            );

        $this->fakeInitialize();

        $retry = $this->postJson(
            '/api/checkout/initialize',
            $payload
        )->assertCreated();

        $this->assertNotSame(
            $reference,
            $retry->json(
                'data.payment.reference'
            )
        );

        $this->assertDatabaseCount('payments', 2);
    }

    public function test_second_successful_payment_for_paid_order_is_reviewed_without_extra_tickets(): void
    {
        $owner = User::factory()->create();
        [, $ticketTypeId] = $this->paidEvent($owner);

        $this->fakeInitialize();

        $token = (string) Str::uuid();

        $initialize = $this->postJson(
            '/api/checkout/initialize',
            $this->checkoutPayload($ticketTypeId, $token)
        )->assertCreated();

        $firstReference = $initialize->json(
            'data.payment.reference'
        );

        $this->fakeVerify(
            $firstReference,
            100000
        );

        $this->withHeader(
            'X-Checkout-Token',
            $token
        )->postJson(
            "/api/checkout/payments/{$firstReference}/verify"
        )->assertOk();

        $order = Order::query()
            ->where(
                'public_id',
                $initialize->json(
                    'data.order.public_id'
                )
            )
            ->firstOrFail();

        $secondReference = 'KT-' . Str::ulid();

        Payment::create([
            'order_id' => $order->id,
            'provider' => 'paystack',
            'reference' => $secondReference,
            'amount_minor' => 100000,
            'currency' => 'NGN',
            'status' => Payment::STATUS_INITIALIZED,
        ]);

        $this->fakeVerify(
            $secondReference,
            100000
        );

        $this->withHeader(
            'X-Checkout-Token',
            $token
        )->postJson(
            "/api/checkout/payments/{$secondReference}/verify"
        )->assertOk()
         ->assertJsonPath(
             'data.status',
             Order::STATUS_PAID
         );

        $this->assertDatabaseCount('tickets', 1);

        $this->assertDatabaseHas(
            'payments',
            [
                'reference' => $secondReference,
                'status' => Payment::STATUS_REVIEW,
            ]
        );
    }

    private function checkoutPayload(
        int $ticketTypeId,
        string $token,
        int $quantity = 1
    ): array {
        return [
            'checkout_token' => $token,
            'visitor_id' => (string) Str::uuid(),
            'customer' => [
                'first_name' => 'Ada',
                'last_name' => 'Buyer',
                'email' => 'ada@example.com',
                'phone' => '+2348000000000',
            ],
            'items' => [
                [
                    'ticket_type_id' => $ticketTypeId,
                    'quantity' => $quantity,
                ],
            ],
        ];
    }

    private function fakeInitialize(): void
    {
        Http::fake(function (HttpRequest $request) {
            if (str_contains(
                $request->url(),
                '/transaction/initialize'
            )) {
                $reference = (string) $request['reference'];

                return Http::response(
                    [
                        'status' => true,
                        'message' =>
                            'Authorization URL created',
                        'data' => [
                            'authorization_url' =>
                                'https://checkout.paystack.test/'
                                . $reference,
                            'access_code' =>
                                'access_' . $reference,
                            'reference' => $reference,
                        ],
                    ],
                    200
                );
            }

            return Http::response([], 404);
        });
    }

    private function fakeVerify(
        string $reference,
        int $amountMinor,
        ?string $paidAt = null,
        string $currency = 'NGN'
    ): void {
        $this->fakeVerifyStatus(
            $reference,
            'success',
            $amountMinor,
            $paidAt,
            $currency
        );
    }

    private function fakeVerifyStatus(
        string $reference,
        string $status,
        int $amountMinor,
        ?string $paidAt = null,
        string $currency = 'NGN'
    ): void {
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' =>
                Http::response(
                    [
                        'status' => true,
                        'message' => 'Verification complete',
                        'data' => [
                            'id' => 'txn_' . substr(hash('sha256', $reference), 0, 16),
                            'status' => $status,
                            'reference' => $reference,
                            'amount' => $amountMinor,
                            'currency' => $currency,
                            'channel' => 'card',
                            'gateway_response' => ucfirst(
                                $status
                            ),
                            'paid_at' => $paidAt
                                ?? now()->toIso8601String(),
                        ],
                    ],
                    200
                ),
        ]);
    }

    private function paidEvent(
        User $owner,
        string $title = 'Test Event',
        string $price = '1000.00',
        int $capacity = 10,
        string $unitType = 'individual',
        int $peoplePerUnit = 1
    ): array {
        $categoryId = DB::table(
            'categories'
        )->insertGetId([
            'name' => 'Category ' . Str::ulid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $organizerId = DB::table(
            'organizers'
        )->insertGetId([
            'name' => 'Organizer ' . Str::ulid(),
            'email' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $addressId = DB::table(
            'event_addresses'
        )->insertGetId([
            'venue_name' => 'Venue',
            'address_line1' => '',
            'address_line2' => null,
            'city' => 'Lagos',
            'state' => 'Lagos',
            'postal_code' => '',
            'country' => 'Nigeria',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table(
            'events'
        )->insertGetId([
            'title' => $title,
            'description' => 'Commerce test event',
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

        $ticketTypeId = DB::table(
            'ticket_types'
        )->insertGetId([
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
        int $lineTotalMinor
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
                'cart_fingerprint' => hash(
                    'sha256',
                    (string) Str::uuid()
                ),
                'user_id' => null,
                'customer_first_name' => 'Other',
                'customer_last_name' => 'Buyer',
                'customer_email' => 'other@example.com',
                'customer_phone' => '+2348111111111',
                'currency' => 'NGN',
                'subtotal_minor' => $lineTotalMinor,
                'discount_minor' => 0,
                'total_minor' => $lineTotalMinor,
                'status' => Order::STATUS_PAID,
                'expires_at' => now()->addMinutes(20),
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'event_id' => $event->id,
            'ticket_type_id' => $ticketTypeId,
            'seller_user_id' => $event->created_by,
            'event_title' => $event->title,
            'ticket_name' => $ticketType->name,
            'unit_type' => $ticketType->unit_type,
            'people_per_unit' => $ticketType->people_per_unit,
            'unit_price_minor' => intdiv(
                $lineTotalMinor,
                $quantity
            ),
            'quantity' => $quantity,
            'line_total_minor' => $lineTotalMinor,
            'currency' => 'NGN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }
}
