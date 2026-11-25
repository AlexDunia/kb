# Rush Hour Tickets — backend infrastructure audit

Audit date: 25 November 2026 (Africa/Lagos). Scope: existing Laravel backend and its actual Vue integration, before introducing event lifecycle notifications, email or scheduling features.

## 1. Scope, evidence and reading conventions

Backend root: `C:/Users/HP/kakabackend` (HEAD `80a88b7`). Frontend root: `C:/Users/HP/kaka` (HEAD `0843d97`). Paths beginning with `app/`, `routes/`, `config/`, `database/`, `tests/` or `docs/` below are relative to the backend unless explicitly prefixed `frontend:`. `frontend:src/...` means `C:/Users/HP/kaka/src/...`.

This report is based on source inspection, migrations, frontend callers, and read-only Artisan inventories. No application source, configuration, migration or existing data was changed. Only this Markdown document was created. No messages, payments, refunds, bank onboarding, scheduler work or queue jobs were executed for this audit. Credentials and environment secrets are intentionally excluded.

The backend working tree was clean at the start. The frontend contains existing uncommitted edits to `src/App.vue`, `src/pages/CreateEvent.vue`, `src/router/index.js`, `src/services/createEventService.js`, `src/utils/eventEditorSections.js`, and `src/utils/routePresentation.js`, plus new recurrence components, utilities, styles, checks and `frontend:docs/recurring-events-v12-integration.md`. This audit includes the on-disk frontend, not just its committed HEAD. Those changes were preserved.

Evidence labels:

- **Implemented:** a concrete execution path exists in inspected code. This does not assert successful production operation.
- **Partial:** foundations exist but a required integration, policy, delivery mechanism or operational verification is absent.
- **Missing:** no implementation was found in the inspected application paths and targeted searches.
- **Unverified:** requires runtime, provider, browser, infrastructure or data evidence not gathered here.

`php artisan migrate:status` confirms that all 33 checked-in migrations are recorded as applied in the configured local database. That is ledger evidence, not a full schema-drift check, inspection of business records, or proof of production migration state. Database constraints below are sourced from migrations; production schema and deployed infrastructure were not accessed.

Existing handoffs (`docs/api-spec.md`, `docs/phase-01-api-handoff.md` through `docs/phase-06-api-handoff.md`, and `docs/phase-07-release-report.md`) are useful secondary references. Current source takes precedence. In particular, the current frontend already reads saved lifecycle cutoff values, despite the older release report describing that wiring as outstanding.

## 2. Executive findings

The application already has considerably more than a basic event CRUD backend. It has a transactional event editor, server-priced checkout, Paystack verification, durable payment reconciliation, inventory reservations, immutable purchase snapshots, ticket issuance, named admission seats, staff invitations, online check-in, refunds, settlement reconciliation, attendee messaging preferences and several durable email delivery tables.

**None of the six requested future automations is implemented as an automatic lifecycle notification.** Their source data and much of their execution infrastructure already exist. Do not introduce a second checkout ledger, inventory calculator, attendee database, generic mail scheduler or payment-success handler without accounting for this existing code.

The most consequential distinctions are:

1. Publishing means `events.status = active`, not `published`. Operational lifecycle is a separate `lifecycle_state`. Time-based dashboard phase and Vue presentation stage are further separate calculations.
2. Modern commerce writes `orders`, `order_items`, `payments`, `tickets` and `attendees`. The older `purchases` table remains a read-side compatibility input; new Paystack checkout does not write it.
3. Existing `event_messages` target attendee mailboxes. Existing `financial_notifications` target order buyers. Neither is an organizer account notification feed.
4. The backend messaging API is implemented, but the Vue attendee message composer refuses live submission. The notification panel has no persisted backend notification/read-state API.
5. Queue and scheduler definitions exist. Their existence does not prove a worker, cron or mail transport is running. Local mail is configured to log rather than deliver externally.
6. Existing email delivery is at least once around the mail-provider boundary. Unique rows and leases reduce duplicates but do not guarantee exactly-once external email.
7. The frontend has in-progress explicit recurrence scheduling that is incompatible with the backend's existing recurrence validation. Occurrence-specific booking/admission is explicitly unsupported.

## 3. Architecture and ownership of responsibilities

### 3.1 Runtime and packages

`composer.json` requires PHP `^8.2`, Laravel `^12.0`, Sanctum `^4.1`, Socialite `^5.23`, Spatie Laravel Permission `^6.17`, and `voku/anti-xss`. `php artisan --version` reports **Laravel Framework 12.10.1** in this checkout. Paystack integration uses Laravel's HTTP client through `PaystackService`; no Paystack SDK is required by Composer.

Development tooling includes Pest, PHPUnit dependencies, Pint, Sail and Pail. No PHPStan/Larastan configuration was found in the root inventory. Spatie permission installation and permission tables are not evidence that event authorization uses that package: the inspected event operations use an ownership policy and custom `EventAccessService`. `User` does not use Spatie's `HasRoles` trait or Sanctum's `HasApiTokens` trait.

`frontend:package.json` declares Vue `^3.5.46`, Pinia `^3.0.1`, Vue Router `^4.5.0`, Axios `^1.8.4`, Vite `^6.2.4`, `qrcode.vue`, UUID and EmailJS. These are declared ranges, not claims about every installed frontend package version.

### 3.2 Directory and execution map

| Location | Actual responsibility |
|---|---|
| `public/index.php`, `bootstrap/app.php` | HTTP entry point and Laravel 12 application configuration |
| `bootstrap/providers.php` | Registers `AppServiceProvider` |
| `routes/api.php` | API routes, route-level authentication and throttles; 79 application API routes in the executed inventory |
| `routes/web.php` | Root welcome page |
| `routes/console.php` | Closure commands and four minute-based scheduled dispatchers |
| `app/Http/Controllers/Api/` | Auth, event editor/public catalog, checkout/webhooks, dashboard, admission/staff, messaging/discounts, finance/payout HTTP boundaries |
| `app/Http/Requests/` | Checkout quote/initialize validation |
| `app/Http/Resources/` | Public event allowlist and an existing current-user resource; auth controller currently returns raw User instead of that resource |
| `app/Models/` | Eloquent event, commerce, attendee, attribution and related models |
| `app/Services/` | Event editor validation/media, lifecycle, messaging, access, admission and recommendations |
| `app/Services/Commerce/` | Checkout, inventory, Paystack, finalization, registration, recovery, discounts, refunds, financial audit, fees and splits |
| `app/Services/Dashboard/` | SQL-based dashboard metrics, timeline and attribution |
| `app/Jobs/` | Durable reconciliation, delivery, audience building and financial/background work |
| `database/migrations/` | Versioned schema and historical backfills |
| `config/` | Queue/mail/session/cache/storage/provider/business configuration |
| `resources/views/` | Welcome view; no application-owned event-email template library found |
| `tests/Feature/`, `tests/Unit/`, `tests/Support/` | Existing integrity/security/commerce/financial/analytics/admission tests and process fixtures |
| `scripts/` | Isolated database and HTTP verification helpers |

`app/Http/Kernel.php` and custom middleware files remain in the tree, but the configured Laravel 12 bootstrap controls the live middleware path. Do not infer that the legacy Kernel's custom CORS stack is active. Likewise, `RouteServiceProvider` is retained with a legacy constant and does not register the routes. `AuthServiceProvider` declares an Event policy mapping but is not listed in `bootstrap/providers.php`; the conventionally named `App\Policies\EventPolicy` can be discovered by Laravel. Owner controllers actually call `Gate::authorize('update', $event)`.

### 3.3 HTTP, authentication and authorization

`bootstrap/app.php` registers the API group with Sanctum `EnsureFrontendRequestsAreStateful`, `throttle:api`, and route-model bindings. Stateful frontend requests receive sessions/cookies/CSRF through Sanctum. `auth:sanctum` protects organizer and account APIs; public checkout instead requires explicit order capabilities for private reads and verification.

The API exception handler normalizes 401, 403, 404, 405, 419, 429 and 5xx responses to JSON messages; validation responses retain Laravel's `message`/`errors` structure. Not every API uses one identical envelope: resource mutations commonly return `{data: ...}`, public discovery returns `{data, meta, links}`, and several listings use Laravel paginator fields at the top level.

`EventPolicy::update()` compares `events.created_by` with the authenticated user's ID. `EventAccessService::authorize()` grants the creator owner access, consults active `event_staff` for other users, permits managers for non-owner abilities, and permits door staff for admission. An optional entrance scope constrains admission. Owner-only messaging, finance and configuration must not be delegated merely because a user has a frontend role label.

`AppServiceProvider::boot()` defines generic API throttling (60/minute), dashboard read (120/minute), dashboard write (20/minute), checkout quote (60/minute), initialize (15/minute by IP and 8/minute by email/IP), verify (30/minute), public view (120/minute), and Paystack webhook (600/minute). Additional routes use inline limits for recovery, staff acceptance and seller operations. Some route-specific limits compose with the generic API limiter; dashboard read/view/webhook groups explicitly remove it where configured.

CSRF exclusions are `api/events/*/track-view` and `api/payments/paystack/webhook`. The signed unsubscribe route has separate considerations in section 9.

## 4. Event creation, persistence, editing and publication

### 4.1 Exact frontend-to-database path

1. `frontend:src/pages/CreateEvent.vue` uses `frontend:src/services/createEventService.js`. `buildCreateEventPayload()` sanitizes text, normalizes ticket products, emits dates/timezone, recurrence, registration fields and event details. Full wizard updates request `ticketCollectionMode: replace`; smaller workspace patches merge products.
2. `createEventDraft()` obtains the CSRF cookie and POSTs `/api/create-event`. `updateEventDraft()`/`patchEvent()` PATCH `/api/create-event/{id}` with `version`. `getEventForEditing()` GETs that editor route. `publishEvent()` POSTs `/api/create-event/{id}/publish` with the saved version.
3. `CreateEventController::{store,update,publish,showForEditing}` dispatches to `CreateEventService::saveEvent()`/`editorPayload()`. Existing event operations authorize the owner. The authenticated ID, not a submitted seller/creator field, establishes ownership.
4. `saveEvent()` runs a database transaction with up to three attempts. It locks the existing event and ticket types in ID order, enforces matching `lock_version`, checks editable status/lifecycle, merges saved and supplied data, validates, then saves related data.
5. It resolves an existing category; uses `Organizer::firstOrCreate(['name' => ...], ['email' => null])`; creates a new address when venue/city changes rather than mutating a shared historical address; saves the event; synchronizes ticket types; saves media/public profile; recalculates summary price/capacity; and returns a fresh event.
6. Controller response includes `id`, `status`, `title`, integer `version`, `lifecycle_state`, ISO `booking_cutoff_at`, and canonical `form`.
7. Frontend `mutationResult()` requires ID/version/form, invalidates public-event and dashboard Pinia caches, and returns the canonical response. UI success must follow that response. It does not establish publication by editing localStorage.

### 4.2 Ownership versus display organizer

**Account authority is `events.created_by -> users.id`.** `order_items.seller_user_id` is snapshotted from that value during checkout. `events.organizer_id -> organizers.id` supplies public organizer identity; organizers are name-based display records with nullable email and no `user_id` relationship in the inspected model/migrations. Multiple accounts may reference the same organizer name. Do not send account-level publication/first-sale mail to `organizers.email` or derive account ownership from an organizer name. Resolve the owner via `Event::createdBy()` and explicitly handle nullable historical creators.

### 4.3 Draft and publish rules

New saves explicitly use `draft`, even though the old status migration default is `active`. Drafts permit incomplete category/date data. `EventEditorValidation::validate()` applies stricter requirements on publish and on edits to already active events: title, category, cover, start/end ordering, format-specific venue or meeting URL, positive paid ticket prices and recurrence rules. Ticket sales windows and capacities are validated. Event times are converted to UTC with `event_timezone` retained; validation includes local-time/offset consistency handling. Local date fields and ISO instant fields are distinct parts of the editor contract.

Publishing calls `saveEvent()` with only the supplied version and `publish=true`, validates persisted content and changes `draft -> active`. Repeating publish on an already active event is rejected; this is not a successful idempotent publish endpoint. Missing version returns 428 and stale version 409. Every accepted edit increments `lock_version`.

There is **no publication event class, listener, mail enqueue, `published_at`, first-publication marker or publication notification outbox write** in this path. `updated_at` changes on ordinary edits, so it cannot reliably reconstruct first publication.

### 4.4 Ticket/history and media behavior

`CreateEventService::syncTicketTypes()` preserves product IDs, validates foreign product IDs, prevents capacity shrinking below sold plus held commitments, and prevents changing `unit_type`/`people_per_unit` after commercial history. Removed products become invisible and receive `retired_at`; they are not deleted. Existing order-item price/name/admission snapshots are not rewritten. Ticket mode and recurrence semantics have commitment/history restrictions.

`EventMediaService::image()` accepts validated JPEG/PNG/GIF/WebP base64 uploads (5 MiB and 40 million pixel bounds), verifies decoded image type, generates event-scoped content-hash paths on the public disk, and validates reused managed URLs against the event. External HTTP(S) URLs are stored, not downloaded by this method. Cleanup occurs after commit and on failure with reference checks. File writes themselves are not transactional database writes; cleanup is compensating logic.

Public metadata lives in `event_public_profiles`, including JSON guests, crew, moments, schedule, FAQs and related IDs. These are presentation/programme data, not an occurrence ledger, attendee registry or scheduler.

## 5. Event lifecycle: four different representations

| Representation | Stored or computed | Actual values and use |
|---|---|---|
| `events.status` | Stored | Schema enum `draft`, `active`, `cancelled`, `completed`; editor publishes with `active` |
| `events.lifecycle_state` | Stored | Default `open`; service transitions `sales_closed`, `completed`, `cancelled`, `archived` separately from status |
| Dashboard `event.phase` | Computed on read | `draft`/other non-active status, or `upcoming`, `live`, `ended` based on start/end/timezone |
| Vue `overviewStage()` | Computed presentation | `draft`, `on-sale`, `approaching`, `event-day`, `completed`; approaching is within 14 local calendar days |

### 5.1 Booking eligibility

`Event::bookingsOpen()` requires `status === active`, lifecycle `open`, future/null booking cutoff, and future/null end. It does **not** require the start time to be in the future, and does not itself check product inventory or ticket sales windows. A one-time event may sell while ongoing until its cutoff/end.

`CheckoutService::buildQuote()` additionally checks paid mode, non-recurring event, creator presence, visible/non-retired product, ticket sales start/end, available quantity, per-request product limit and positive price. Free registration checks free mode, supported one-time event, future end and capacity. `PublicEventResource.booking_supported` checks recurrence and event booking eligibility, not whether every paid product has available stock or an open sales window.

Therefore a dashboard label **“on sale” is not authoritative proof that checkout is possible**. Sold out, not-yet-open sales and lifecycle closure need the server's actual checks.

### 5.2 Time phase and completion

`DashboardAnalyticsService::{eventListItem,eventPhase,daysToGo}` converts instants into the event timezone (fallback Africa/Lagos). Active events before start are `upcoming`; between start and end are `live`; after end are `ended`. With no end, the start's local day is treated as live. This calculation does not update a database row and does not account for `lifecycle_state` in `eventPhase()`.

`frontend:src/components/event-workspace/overviewModel.js` uses event-local calendar days. An upcoming event within 14 days becomes `approaching`; the calendar event day can be `event-day` before the actual start instant. These calculations run when evaluated; they are not a background timer or durable transition record.

No scheduled command automatically persists `completed` after event end. `EventLifecycleService::change()` handles owner-requested close/open sales, cutoff, completion, cancellation and archive under event/ticket locks and version checks. Completion requires an active event whose end has passed. Cancellation records `cancelled_at`, creates unique `event_cancellation_work`, and immediately affects entry/booking decisions. Archive requires completion/cancellation or an empty draft and rejects unresolved cancellation, refund, settlement, recovery and legacy purchase obligations. Accepted actions write `financial_audits` and increment event version.

The newer lifecycle actions generally leave `status` unchanged. An event may remain `status=active,lifecycle_state=cancelled`; code that checks only status will be wrong. Public discovery excludes cancelled/archived lifecycle states. Direct public detail permits active cancelled events for the cancellation notice; archived detail is unavailable. Completed events are not universally hidden by the public scope.

`frontend:src/components/event-workspace/WorkspaceEventLifecycle.vue` reloads version, lifecycle and saved cutoff through the editor read, submits `eventFinanceService.changeEventLifecycle()`, and invalidates caches on success. **Read limitation:** `CreateEventController::showForEditing()` permits only status `draft` or `active`; the underlying edit service rejects completed/cancelled/archived lifecycle writes, even if their status remains active. Historical records using old status `completed`/`cancelled` also do not follow all newer paths.

There are no backend stored states named `approaching` or `ongoing`, and no occurrence table for recurring lifecycle calculations.

## 6. Purchases, Paystack and attendees

### 6.1 Paid checkout execution flow

```text
Vue ticket selection/cart -> Checkout.vue
  -> POST /api/checkout/quote
  -> QuoteCheckoutRequest -> CheckoutService::quote/buildQuote
  <- authoritative currency, integer minor totals, lines, registration schemas

  -> CSRF cookie -> POST /api/checkout/initialize
  -> InitializeCheckoutRequest -> CheckoutService::initialize
  -> cache lock(checkout token hash)
  -> DB transaction: sorted ticket locks, inventory/discount validation
     orders + order_items + attendees + order_allocations
     payments + payment_reconciliations (when amount > 0)
  -> commit -> PaystackService::initialize (external HTTP)
  <- order + payment reference/access_code/authorization_url
  -> Paystack popup resumes provider transaction

Vue verify request OR signed Paystack webhook -> reconciliation job
  -> PaystackService::verify (external HTTP)
  -> PaymentFinalizer::finalizePaystack (DB transaction)
  -> payment/order state + ticket issuance + attendee linking + delivery outbox
  <- protected order response -> Vue shows server-confirmed order/tickets
```

`InitializeCheckoutRequest` permits at most 25 distinct product lines, quantity 1–20 per line, a UUID `checkout_token`, optional visitor UUID, customer details, optional discount and up to 500 attendee entries. `RegistrationService` also bounds actual admission seats to 500. `QuoteCheckoutRequest` accepts items and optional discount. The current Vue service does not forward discounts in quote or initialize, even though backend support exists.

`CheckoutService::initialize()` hashes the checkout token, normalizes customer/items, fingerprints cart/customer/discount/attendees, and uses a 20-second cache lock with a bounded wait. Reusing the same token with the same fingerprint continues the existing order; a changed fingerprint is rejected. New orders snapshot event title, ticket name, seller, product ID, unit price, quantity, people per unit, currency, registration schema and fee/discount allocation. Prices and seller identity come from server records. Reservations normally last 20 minutes (configurable; minimum five).

The Paystack initialize network call is outside inventory transactions. A timeout is treated as ambiguous: the same payment reference remains unresolved and durable reconciliation is retained, rather than creating a second payable attempt blindly. `continueExistingOrder()` controls retry/reacquisition, expiry and terminal-state handling.

### 6.2 Verification and webhook authenticity

`POST /api/checkout/payments/{reference}/verify` calls `CheckoutController::verify()` -> `CheckoutService::verify()` -> protected order authorization -> Paystack verify -> `PaymentFinalizer::finalizePaystack()`.

`PaystackWebhookController::__invoke()` validates the raw body with HMAC-SHA512 and `x-paystack-signature`. For `charge.success`, it persists/reopens `payment_reconciliations` before attempting job dispatch. Unknown signed references create `payment_resolutions` evidence. A signed webhook is not by itself accepted as proof for issuing tickets: `ReconcilePayment` independently fetches provider verification. Refund notifications only advance reconciliation due time through `FinancialWebhookService::record()`.

The finalizer locks order -> sorted ticket types -> discount -> payment. It validates reference, exact integer amount, currency and provider transaction ID uniqueness; successful repeat finalization returns the existing order. Extra successful payments, mismatches, unsupported recurrence, terminal event/order charges, invalid timestamps and unfulfillable inventory become review records rather than fabricated fulfillment. Some cancelled-event late charges enqueue controlled refunds. It rechecks capacity under locks even when a reservation expired; late payment may be fulfilled only if commitments remain satisfiable. `paid_at` comes from verified provider data (fallback now if absent).

On accepted success it records provider fees, marks payment `success`, order `paid`, redeems discounts and calls `issueTickets()`. That creates one ticket per purchased product unit, links the relevant attendee seats, and `insertOrIgnore`s a purchase delivery with key `purchase:{order_id}` **inside the same transaction**. A table product can admit multiple people; one product unit is not one attendee.

### 6.3 Free and fully discounted registration

`POST /api/events/{event}/registrations` -> `EventOperationsController::register()` -> `FreeRegistrationService::register()` is a real server path. It validates a UUID/customer/quantity/answers, locks the event, creates or reuses a hidden registration product, enforces capacity, creates `registered` zero-total order/items/attendees/tickets and a commerce delivery. Retry token/fingerprint rules prevent repeat issuance for that registration.

Paid checkout can also reach zero total through a valid 100% discount; it uses `registered`, redeems the discount, and issues tickets without a payment. **Neither path represents a confirmed paid sale.** Any first-sale automation must explicitly decide how to treat them; filtering merely on `Order::isFulfilled()` would include both.

### 6.4 Private order access and recovery

`CheckoutService::authorizeOrderAccess()` permits the authenticated linked user, a matching `X-Checkout-Token` capability, or a valid exchanged `X-Order-Recovery-Token`. Public IDs alone do not authorize private order/ticket access. Guest orders have nullable `user_id`; entering the same email as an account does not link an order to that account.

`BuyerOrderController::index()` lists linked account orders with pagination. `ticket()` requires order access and fulfillment. `OrderRecoveryService` stores token/access hashes; emailed links expire after 24 hours and are exchanged once under row lock for a 30-minute access capability. Recovery request responses are generic and use a ten-minute per-order deduplication window.

`Ticket` encrypts the raw token with Laravel encryption and hides token/hash from default serialization. Protected order payloads deliberately return decrypted tokens for QR display. Recovery tokens retained for retries and staff invitation tokens are encrypted; queue payloads generally carry row IDs. Preserve `APP_KEY` and credential privacy when adding notifications.

### 6.5 Attendee and check-in data

`RegistrationService::{schema,answers,attach,link}` snapshots registration questions per order item, validates answers, creates one `attendees` row per entitlement seat, and links tickets on fulfillment. Buyer/customer identity remains separate from attendee identity; unnamed seats stay unnamed. Attendee rows can exist before payment and must not be included in guest mail without fulfillment/ticket checks.

`EventOperationsController` implements paginated attendee/purchase listings, optimistic attendee answer edits, streamed CSV export (including spreadsheet formula escaping), and durable ticket-link resend to the original buyer. The attendee listing currently includes only orders `paid`/`registered`, while messaging includes `partially_refunded` with valid tickets. This difference matters for reminders and displayed guest totals.

`AdmissionService::move()` uses an event shared lock, scoped staff checks, active entrance checks, unique request key plus payload fingerprint, a ticket row serialization lock and attendee seat locks. It checks entry windows (default start minus two hours through end), credential/order validity, lifecycle, remaining entitlements and reentry policy, then persists movement and replayable response in one transaction. Exits remain possible for invalidated tickets so revocation does not invent an exit. Scans are server-authoritative and online; no offline admission authority exists.

### 6.6 Financial lifecycle and earnings

`CheckoutService::createAllocations()` snapshots expected seller proceeds/platform fees by seller, plus the payout destination available then. `PaystackSplitService::build()` optionally supplies flat subaccount splits when enabled and all required allocations have subaccount codes; otherwise funds are platform-held. Only platform-borne provider fees are supported by this code path.

`PayoutAccountController` exposes banks, owner-scoped masked payout accounts and pending onboarding. `OnboardPayoutAccount` resolves a Nigerian account and creates/verifies the Paystack subaccount. An ambiguous non-idempotent creation is held for review rather than repeated blindly.

`RefundService::{request,apply,cancelledCharge}` supports complete order-item refunds, including selected event lines in multi-event orders. It uses request fingerprints/unique keys, locks, captured amount bounds, ticket suspension and provider-confirmed application. It does not support arbitrary refund amounts, individual units of a line or individual seats. Refunded entitlement releases inventory; entry/movement history is retained.

`ReconcileSettlementFeed` pages provider settlement feeds; `ReconcileSettlement` matches provider transaction identity, amount/currency and expected allocation totals. It distinguishes platform receipt from seller settlement. `EventMetricsService::metrics()` groups financial values by currency and distinguishes gross, discounts, charged value, fees, refunds, expected seller net, pending/confirmed settlements and recovery liabilities. Missing provider fees remain unknown rather than zero. Expected earnings do not establish that money reached a seller. Platform-held disbursement and already-settled seller recovery remain operational gaps.

## 7. Frontend synchronization and unfinished interfaces

### 7.1 API transport and deployment coupling

`frontend:src/api/axios.js` uses relative `/api`, `withCredentials: true`, and `withXSRFToken: true`. `ensureCsrfCookie()` deduplicates concurrent requests to `/sanctum/csrf-cookie`. The application uses Sanctum cookie sessions; a bearer token system is not the established frontend auth contract.

`frontend:vite.config.js` proxies `/api`, `/storage` and `/sanctum` to the backend on port 8000 for local development, with frontend port 5174. Production build base is `/kaka/`, while runtime API paths are still origin-root `/api`. Vite's development proxy is absent from a built static site. Hosting must supply the production routes/proxy and cookie arrangement explicitly.

`frontend:src/stores/auth.js` initializes from `/api/user`, not localStorage as proof of authentication; login/register obtain CSRF, then fetch server identity. Browser user/provider storage is a convenience cache. Router guards await initialized auth state.

### 7.2 Data paths and refresh behavior

| Feature | Vue caller/state | Laravel response/source |
|---|---|---|
| Public catalog | `services/publicCatalog.js`, `services/eventService.js`, `stores/events.js` | `EventController::{index,show}` -> `PublicEventResource`; paginated filters, safe public allowlist |
| Draft/editor/publish | `pages/CreateEvent.vue`, `services/createEventService.js` | `CreateEventController` -> transactional editor and canonical `form/version` |
| Organizer event list/overview | `pages/Dashboard.vue`, `stores/dashboard.js`, `services/dashboardService.js` | Owner-filtered `DashboardController`, analytics/inventory/traffic SQL aggregates |
| Buyer confirmation/tickets | `pages/Checkout.vue`, `pages/PaymentSuccess.vue`, `services/checkoutService.js` | Protected order read and server verify/finalizer; QR rendered from returned credential |
| Account orders | `pages/TransactionHistory.vue`, checkout service | `GET /api/buyer/orders` -> linked orders |
| Attendees/purchases/check-in | `components/event-workspace/liveAttendeesModel.js`, `services/eventOperationsService.js` | `EventOperationsController`, `AdmissionController`, `EventStaffController` |
| Earnings/refunds/payouts | Workspace earnings/financial/lifecycle components, `services/eventFinanceService.js` | `EventFinanceController`, `PayoutAccountController`, `EventMetricsService` |
| Tracking/share links | `services/eventViewService.js`, `dashboardShareService.js` | `EventTrackingController`, `EventAttributionService`, dashboard share-link APIs |

Dashboard overview cache TTL is 30 seconds in memory; event list remains loaded until invalidated/forced. TTL expiry permits a future fetch; it is not automatic polling. Store generation/request IDs prevent stale responses after resets. Mutation helpers invalidate event/dashboard caches. Dashboard pages load via route/account changes and explicit refresh rather than server broadcasts.

Checkout polls protected order status every 2.5 seconds for at most ten minutes, with overlap/terminal guards; explicit verification can query Paystack. `PaymentSuccess.vue` verifies callback references through Laravel and refreshes visible order state every 30 seconds and on window focus. A provider callback URL or popup closure never establishes payment success locally.

`liveAttendeesModel.js` refreshes visible state every five seconds and on focus/visibility changes; browser `BroadcastChannel` helps same-browser tabs refresh. It retains a request UUID for uncertain scans so retrying a request does not consume another entitlement. This channel is not a Laravel broadcast, WebSocket or cross-device push feed. No application lifecycle broadcasting infrastructure was found.

### 7.3 Analytics semantics that a weekly email must preserve

`DashboardAnalyticsService::salesTimeline()` aggregates in SQL, uses event-local Monday-start weeks and a same-elapsed-duration comparison to the previous week, and bounds charts to 1,095 days. UTC-to-local SQL conversion accounts for IANA timezone transitions without requiring MySQL timezone tables. Current totals and chart-window totals are different concepts.

`paidEventOrdersQuery()` includes `paid`, `registered`, `partially_refunded`, and `refunded`, and filters line currency to the currently configured commerce currency; the dashboard sales timeline therefore includes zero-charge registrations and historical charged line values, not exclusively positive confirmed paid sales or net post-refund earnings. It is not an all-currency historical summary. Inventory uses net non-refunded units; ticket performance revenue and financial metrics have different purposes. Some admission aggregates/listings exclude partially refunded orders while financial metrics include them. `purchases` compatibility affects inventory but is not included in all modern financial/timeline reports. Do not label all these quantities “sales” interchangeably.

`EventAttributionService`/`EventTrackingController` persist visitor/source data in `event_traffic_views`, deduplicate event+visitor+hour, and snapshot source attribution onto order items. The legacy `event_views`/`event_likes` and modern order/traffic signals are reused by `RecommendationEngine`; its results are bounded and cached per user for 30 seconds.

### 7.4 What is preview or unfinished

- `services/dashboardPreview.js` sets `DASHBOARD_PREVIEW` only for a development build explicitly using `VITE_DEMO_MODE=true`. It supplies fixture overview/event data; its `published` fixture status is not the backend publication value.
- `staticEventCatalog.js` and `eventProfilePreview.js` provide demo event content; live requests use the Laravel event service. The commerce service rejects real checkout while demo mode is selected.
- `WorkspaceViews.vue::handleSubmit()` refuses live `rh-message-form` submission via `unavailable()`. Preview drafts save only in localStorage. No connected frontend event-message list/save/send/retry/preference client was found. A visible composer is not proof that messages are queued.
- The discount API exists, but live authoring is unavailable in the inspected workspace path and `checkoutService.js` does not pass discounts to Laravel.
- `WorkspaceNotifications.vue` reads optional `overview.setup` and `overview.notifications`. `DashboardAnalyticsService::overview()` supplies neither. Only locally derivable known tasks (such as missing cover) can be shown; unknown setup tasks are filtered out. Read/dismiss state is sessionStorage-only. There is no backend inbox, unread count, persisted read state or lifecycle notification feed.
- `ContactPage.vue` uses browser EmailJS with public frontend configuration. This is a separate contact-form mechanism, not Laravel event messaging, order mail or a durable automation service.
- `CreateEvent.fixed.vue`, `temp1.vue`, legacy reference files and old integration examples are not evidence of the routed implementation. Current route imports and service calls are the relevant entry points.
- Current uncommitted explicit schedules use `schemaVersion`, `activeMode` and configuration objects via `utils/explicitSchedule.js`. Backend `EventEditorValidation` accepts the older recurrence keys (`frequency`, `weeklyDays`, `monthlyMode`, etc.) and rejects unknown recurrence array keys. The new payload cannot be assumed saveable. No backend v12 recurrence implementation is present.

## 8. Existing notification, email and automation infrastructure

### 8.1 Mail channels, templates and preferences

| Capability | State | Evidence and limitation |
|---|---|---|
| Password-reset email | Implemented | Laravel password broker/default reset notification; `User` uses `Notifiable`; frontend reset URL configured in `AppServiceProvider` |
| Purchase/recovery email | Implemented delivery path | `commerce_deliveries` -> `DeliverOrder`; plain text status + secure order recovery link, not a PDF ticket attachment |
| Staff invitation email | Implemented delivery path | `event_staff_invitations` -> `DeliverStaffInvitation`; seven-day invitation expiry is not a seven-day event reminder |
| Attendee organizer mail | Implemented backend, partial product integration | `EventMessageController`, `EventMessagingService`, audience/delivery jobs; live Vue composer disconnected |
| Cancellation/refund buyer email | Implemented delivery path | `FinancialAudit::notify()` -> `financial_notifications` -> `DeliverFinancialNotification` |
| Branded event HTML/Mailable library | Missing | Inspected custom jobs use `Mail::raw`; no application `app/Mail` or event email Blade library found |
| Organizer lifecycle email/preferences/inbox | Missing | No owner-level preference, rule-run, read-state or automatic publication/first-sale/reminder records |
| SMS/push | Missing | No application sending path found |
| Provider delivery receipts/bounces/complaints | Missing | `sent_at` records mail transport acceptance, not inbox delivery; no receipt webhook/suppression system found |

`config/mail.php` defaults to `log`; SMTP, sendmail, SES, Postmark, Resend, array, failover and round-robin entries exist. Entries are configuration options, not proof their provider dependencies or credentials are installed/operational. Local `.env` selects **log**. A log mailer can return success and mark a delivery sent while sending no external email; failover from SMTP to log has the same semantic concern. Log contents can contain private recovery links and mail text and must be protected.

The password reset controller sends through Laravel's broker synchronously in the HTTP path, not via a custom event lifecycle job. `PasswordReset` is dispatched after reset; no custom event lifecycle listener was found.

### 8.2 Attendee messaging flow

Owner routes under `/api/event-operations/{event}/messages` support paginated index, POST save, PUT versioned draft update, POST audience estimate, POST send and POST retry. Draft fields: `subject` (150 characters, no CR/LF), plain-text `body` (20,000 characters), `purpose` (`event_update` or `marketing`), audience (`all_ticket_holders`, `ticket_types`, `checked_in`) and optional event-owned product IDs.

`send()` locks the message, checks draft/version and persists `preparing`, queue timestamp and upper attendee cursor. Repeated send on a non-draft returns its existing status (202). It does not send mail during the request. `messages:dispatch` later queues bounded audience work.

`EventMessagingService::audience()` joins attendees -> order items -> orders -> tickets, requires order `paid`/`registered`/`partially_refunded` and ticket `valid`, applies ticket type/checked-in filters, and chooses normalized attendee email or buyer email when absent. Multiple seats/emails collapse through unique delivery keys. A prepared audience is bounded by an initial maximum attendee ID and built in chunks of 200; it is not a permanently frozen entitlement snapshot. `permitted()` rechecks current eligibility before sending.

`event_message_preferences` is unique per event/email. Unsubscribe suppresses organizer messages; marketing requires explicit consent and `consented_at`. Preference opt-in requires an exchanged emailed recovery capability and applies only to the stored buyer email, never arbitrary attendee mailboxes. `PUT /api/checkout/orders/{publicId}/message-preferences` is order-authorized. No organizer-account preferences or verified consent collection UI is wired in the inspected Vue paths.

`DeliverEventMessage` claims a due row with a five-minute lease, increments attempts, rechecks eligibility, sends raw mail with a signed unsubscribe URL and deterministic Message-ID, then marks sent. Failures get exponential minute backoff, at most five durable attempts, then `failed`; permission failures become `suppressed`. Scheduler recovers exhausted processing leases. Message completion waits for no pending/processing deliveries and reports `failed` if any recipient failed. Owner retry resets only failed recipients, not sent recipients.

### 8.3 Scheduled commands (all every minute)

All are in `routes/console.php`; executed `php artisan schedule:list` confirms registration. Each uses `withoutOverlapping()`, not `onOneServer()`.

| Command | Durable work and bounds |
|---|---|
| `commerce:dispatch` | Expires pending holds; selects up to 200 due payment reconciliations and 200 unsent commerce deliveries |
| `admission:dispatch-invitations` | Selects up to 100 due, unexpired, unaccepted/unrevoked staff invitations |
| `messages:dispatch` | Recovers exhausted delivery leases; up to 100 preparing audience builders; up to 200 due sending deliveries; checks up to 100 sending messages for completion |
| `finance:dispatch` | Recovers ambiguous subaccount leases; bounded onboarding (100), refunds (100), financial notifications (200), cancellation work (20), settlement feed creation/checking (100), settlement reconciliation (100) |

Hold expiration is a bulk status update, not a 200-row paginated operation. Inventory also excludes expired pending holds by time, so correctness does not depend solely on expiration housekeeping running promptly.

There is no publication notifier, first-sale detector, low-stock watcher, weekly summary command, seven-day reminder selector or three-day preparation selector in this schedule. No custom `app/Events`, `app/Listeners`, application event subscriber or model observer implementing those automations was found. Closure commands make an `app/Console/Commands` class unnecessary for the existing schedules.

### 8.4 Jobs, leases and retry distinctions

| Job in `app/Jobs/` | Durable identity and recovery |
|---|---|
| `ReconcilePayment` | Payment ID; leases reconciliation row; retries provider status; success/review terminate; old unresolved results create review evidence after 14 days; repeated HTTP exceptions remain retry work |
| `DeliverOrder` | Commerce delivery ID; five-minute due lease; retries with persisted encrypted recovery token; records attempts/errors/sent time |
| `DeliverStaffInvitation` | Invitation ID; due lease and expiry/revocation checks; successful mail stamps `sent_at`; next scheduler selection recovers unsent eligible invitations |
| `BuildEventMessageAudience` | Message ID; locked cursor/status and unique recipients; bounded build |
| `DeliverEventMessage` | Delivery ID; finite durable attempt policy and permission recheck |
| `DeliverFinancialNotification` | Financial notification ID; due lease; success stamp, failures retry after an hour |
| `ProcessRefund` | Refund ID; lease; find by stable provider reference before create; reconciles provider evidence; repeated failures can enter attention |
| `ProcessEventCancellation` | Unique event work ID; calls cursor-based cancellation processing, 50 order IDs per pass |
| `OnboardPayoutAccount` | Account ID; lease/state machine; ambiguous subaccount creation requires review |
| `ReconcileSettlementFeed` | Feed ID; lease and provider pagination/checkpoint |
| `ReconcileSettlement` | Settlement ID; lease, transaction identity matching and reconciled totals |

All implement `ShouldQueue` and `ShouldBeUnique`. Uniqueness is cache-backed and time-limited (typically 300 seconds, audience build 120); it is not a permanent business idempotency key. Most email/reconcile jobs use 30-second timeout; financial jobs use 45 seconds. Some jobs use queue tries=3, others tries=1 because durable state governs retries. Durable retry behavior differs by subsystem; do not copy a queue `tries` setting and assume equivalent recovery.

`config/queue.php` defaults to database, queue `default`, retry_after 90 seconds, failed jobs in `failed_jobs`, and `after_commit=false`. Database outbox polling avoids dispatching uncommitted work for the principal paths. New code must explicitly preserve commit ordering rather than assume Laravel is globally configured for after-commit dispatch.

## 9. Database relationships, indexes and consistency

### 9.1 Relationship map

```text
users --< events.created_by                    (owner account)
organizers --< events.organizer_id              (display identity)
categories --< events.category_id
event_addresses --< events.address_id
events --< ticket_types, event_media, faqs
events --1 event_public_profiles
events >--< sub_categories via event_sub_category
events >--< event_options via event_event_option

users --< orders.user_id                       (nullable buyer account)
orders --< order_items >-- events / ticket_types / seller users
orders --< payments
orders --< order_allocations >-- payout_accounts / seller users
order_items --< tickets --< attendees
order_items --< attendees                      (seats exist before ticket linkage)

events --< event_entrances / event_staff / event_staff_invitations
events --< admission_requests --1 admission_movements
events --< event_messages --< event_message_deliveries
events --< event_message_preferences
orders --< commerce_deliveries / order_recoveries / financial_notifications
payments --1 payment_reconciliations; --< payment_resolutions
orders --< commerce_refunds --< commerce_refund_items >-- order_items
settlement_feeds --< provider_settlements --< settlement_transactions
```

Most messaging/staff/refund/settlement tables are accessed with `DB::table()` rather than dedicated Eloquent models. Their absence from `app/Models` does not mean the feature is missing. The graph describes intended relational links; not every inverse method exists on `User` or `Event`.

### 9.2 Schema inventory relevant to automation

| Tables | Important fields, keys and constraints from migrations |
|---|---|
| `users`, `sessions`, `password_reset_tokens`, `personal_access_tokens` | Account/session/broker foundation; user email unique; personal access token table is not proof of token-based SPA auth |
| `categories`, `sub_categories`, `organizers`, `event_addresses` | Event classification/display/venue; normalized `city_search` index; no organizer account FK |
| `events` | Owner/category/organizer/address FKs; UTC date/end + timezone; status/lifecycle/cutoff and version; status/date/id, status/category/date/id, status/created_at/id and lifecycle/id indexes |
| `ticket_types` | Capacity, decimal price, people/unit, visibility, retirement, sales dates, registration flag; event/visible and registration indexes |
| `event_media`, `event_public_profiles` | Event media and unique per-event public profile; profile JSON is programme content |
| `faqs`, `event_options`, `event_event_option`, `event_sub_category` | Existing event detail relations; pivot composite primary keys; editor currently stores extra details/public profile rather than synchronizing all older relations |
| `purchases` | Legacy user/event/product IDs, decimal total, quantity, pending/completed/cancelled/refunded; nullable unique transaction ID; user/event, user/created_at, event and status indexes |
| `orders` | Unique public ULID and checkout token hash; customer, nullable user, minor totals, status, paid/expiry times; status/expiry, user/created_at, email/created_at, visitor/created_at indexes |
| `order_items` | Immutable commerce fields, source attribution, schema, fees/discount/refund amounts; unique order/product; event/created_at, product/created_at, seller/created_at, event/currency/order and event/source/created_at indexes |
| `payments` | Unique reference and nullable unique provider transaction ID; amount/currency/state, provider fees/refunded total, verified/paid times; order/status index |
| `order_allocations` | Unique order/seller; payout destination snapshot, fee/net, settlement/refund amounts/status; seller/settlement-status index |
| `payout_accounts` | Multiple history rows per user permitted after latest migration; unique user/fingerprint and provider subaccount code; masked/encrypted bank fields and onboarding state |
| `tickets` | Unique public ID/token hash and item/unit_index; encrypted token; admission count and state; event/status and item/status indexes; event/product deletion restricted |
| `attendees` | Unique item/unit/seat entitlement; event/item/ticket FKs, answers, entry state/version; event/name, event/email, event/id cursor, ticket/inside/entries indexes |
| `payment_reconciliations` | Unique payment, attempts, indexed next due, finished/error fields; payment deletion restricted |
| `payment_resolutions` | Unique payment/reason and provider-reference/reason, evidence and resolved timestamp; not a provider-wide webhook-event log |
| `order_recoveries` | Unique token hash and nullable access hash, expiry/exchange; order deletion cascades |
| `commerce_deliveries` | Unique dedup key; order FK restrict; kind, encrypted retry link, attempts, indexed next due, sent/error |
| `event_entrances`, `event_staff`, `event_staff_invitations` | Entrance state/window; unique event/user membership; unique invitation token hash, encrypted token, acceptance/revocation/expiry/due state |
| `admission_requests`, `admission_movements` | Unique event/request UUID; fingerprint and replay response; unique movement per request; event/id and ticket/id history indexes |
| `event_discounts`, `discount_redemptions` | Unique event/code; version, eligible products, window/quota; unique order redemption and discount/redeemed index; snapshot and hold/redeem times |
| `event_messages` | Event/creator, subject/body/purpose/audience, draft/preparing/sending/completed/failed state, version and audience cursors; event/id and status/id indexes |
| `event_message_preferences` | Unique event/email, marketing consent/time and unsubscribe time |
| `event_message_deliveries` | Unique message/email; status/next-attempt composite index, attempts/sent/error fields |
| `commerce_refunds`, `commerce_refund_items` | Unique public/reference/provider IDs, unique requester/request key; unique refund association per order item; status/next-due index |
| `financial_audits` | Actor/event/order, action/details/time; event/id and order/id indexes; no application immutability trigger found |
| `financial_notifications` | Required order, optional event, unique dedup key, kind/subject/body/attempts/sent/due/error; no due-time index declared in this migration |
| `event_cancellation_work` | Unique event, actor, cursor, finished/due/error |
| `settlement_feeds`, `provider_settlements`, `settlement_transactions` | Unique scope, feed/provider ID, settlement/reference, allocation and scope/payment keys; provider pagination and reconciled evidence |
| `event_traffic_views`, `event_share_links`, `event_views`, `event_likes` | Modern hourly deduped traffic and unique event/source links alongside legacy recommendation inputs; event/time/source/visitor and user/recent indexes |
| `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, permission tables | Laravel background/cache foundation and installed permission schema |

Schema chronology matters. Main additions are `2026_09_21_010000_create_commerce_foundation_tables.php`, `2026_09_23_120000_create_dashboard_analytics_foundation.php`, `2026_11_23_000001_create_event_public_profiles_table.php`, and `2026_11_24_000001` through `000005` (editor integrity, commerce hardening, registration/admission, promotion/messaging, finance/lifecycle). Read each full migration before proposing overlapping tables. `000005_add_financial_and_event_lifecycle.php` is explicitly forward-only and throws on rollback.

Older event/category/organizer/address/user relations include cascade deletion; newer financial/ticket/attendee relations introduce restrict behavior. There is no supported hard-delete event API: legacy POST/PUT/DELETE `/api/events` mutations return 410 and direct clients to the editor. Do not infer that every historical table is independently protected from arbitrary database deletion.

### 9.3 Existing reliability and residual risks

| Concern | Existing mechanism | Remaining limit |
|---|---|---|
| Duplicate checkout | UUID token hash unique, cart fingerprint, cache lock and existing-order continuation | New UUIDs are independent purchases; email identity is not purchase uniqueness |
| Oversell/concurrent edits | Sorted inventory row locks, transaction retry, commitment SQL, capacity checks | Real DB locking and shared cache are required; SQLite/single-process checks are insufficient |
| Duplicate provider success | Finalizer locks, terminal guards, unique provider transaction ID, review evidence | Review/operator workflow still required; no exactly-once provider webhook receipt ledger |
| Historical entitlement/price | Order snapshots and retired product retention | Current event metadata can change; notification content needs an explicit snapshot/current-data policy |
| Crash after purchase commit | Purchase delivery/reconciliation rows persist transactionally | Cron/worker outage delays delivery; mail success then process death can duplicate email |
| Duplicate attendee email | Unique message/email, audience cursor, leases, send-state guards | Two separate messages with the same text are distinct; no lifecycle-rule/event/period key exists |
| Duplicate scans | Unique request key/fingerprint, ticket/seat locks, stored replay response | Online connectivity required; uncertain requests must reuse their UUID |
| Refund/cancellation retry | Stable references/keys, item uniqueness, cursor/locks, verified provider application | Attention/review cases and seller recovery need operators |
| Unauthorized data | Owner Gate/custom scoped access, protected order capabilities, allowlisted public resources | Auth findings below remain; frontend visibility is not an authorization boundary |

Additional verified source concerns or bounded inferences:

1. **Auth release blockers outside this audit's implementation scope:** `AuthController` uses stateless Google OAuth (no state check), disables provider TLS verification in local mode, and returns caught exception messages in redirect query strings. Callback `updateOrCreate()` supplies password/name and Google fields; `User` only allows name/email/password for mass assignment, so provider fields/verification fields are not reliably persisted through that call. Existing local passwords may be replaced on an email match. No verified Google-email enforcement is present. Auth responses return raw User fields, and no dedicated login-specific limiter is configured beyond the generic API limiter. `User` does not implement `MustVerifyEmail`; mail ownership is not universally verified. These need targeted remediation before release; nothing was changed here.
2. **Subscription form reliability:** `EventMessageController::unsubscribe()` renders a signed GET confirmation form with no CSRF token, and the POST route is not in the bootstrap CSRF exclusions. Sanctum applies CSRF only when Origin/Referer identifies a stateful frontend. The form uses a no-referrer policy, so a direct email-link flow may work statelessly, but stateful-origin requests can require CSRF and fail with 419. This is an untested origin-dependent integration risk, not proof every unsubscribe fails.
3. **Email lease versus send duration:** database leases are five minutes, jobs have timeouts, but SMTP transport timeout is `null` in config. A deterministic Message-ID is not a provider idempotency guarantee. Review worker termination/mail timeouts before claiming duplicate-free delivery.
4. **Lifecycle/read mismatch:** computed dashboard phase ignores lifecycle cancellation/completion and event list payload omits lifecycle state. A cancelled event can still receive an upcoming/live/ended presentation phase. Owner editor read and lifecycle mutation have different allowed-state checks.
5. **Reporting/list mismatch:** partially refunded orders remain valid for some tickets, yet the current attendee listing excludes the order. Reminder audiences should reuse the eligibility query, not blindly email the listing or count raw attendees.
6. **Legacy first-sale ambiguity:** old completed `purchases` may reserve inventory but lack modern verified payment/ticket relationships. Do not infer a historical first confirmed Paystack sale from their creation date or silently migrate/copy them.
7. **Scale:** most selectors are bounded, but some due tables lack dedicated due indexes; hold expiry updates all matching rows, and audience build uses per-send eligibility queries. Measure representative backlog/query plans before adding indexes or redesigning outboxes. No million-user throughput claim is supported.

The earlier `docs/phase-07-release-report.md` reports backend dependency advisories and a red authentication suite. Those historical results were not rerun here and must not be treated as current counts or resolved issues. They remain release-verification work until independently rechecked.

## 10. Readiness for each proposed automation

The following are **reuse/gap findings**, not new implementations or approved product policy. Recipient, channel, timing and consent decisions remain unspecified where the request does not define them. The notification panel suggests organizer preparation prompts, while “guest reminders” could also mean attendee mail; these require different recipients and preference rules.

### 10.1 Successful event publication

- **Exists:** owner-authenticated publish endpoint, shared strict validation, transactional `draft -> active`, version conflict protection and canonical response/cache invalidation.
- **Partial:** owner account/email can be resolved through `created_by`; delivery infrastructure exists for other recipient domains.
- **Missing:** publication occurrence/timestamp, durable organizer notification/email record, rule-level unique key, template, owner preferences and feed/read state. No dispatch is attached to publishing.
- **Reuse:** `CreateEventService::saveEvent()` is the real transition point; use its transaction/commit boundary and existing queue/mail configuration. Attendee messaging is not a publication-to-organizer mail model. A future durable publication record must distinguish publish from later active edits and response retries.

### 10.2 First confirmed ticket sale

- **Exists:** authoritative success transition in `PaymentFinalizer::finalizePaystack()`, verified payment identity, order items connecting multiple events/sellers, purchase outbox and transactional ticket issuance.
- **Partial:** dashboard contains first-sale/chart information, but it is computed from broader fulfilled/history statuses and is not a concurrency-safe milestone claim.
- **Missing:** per-event first paid-sale marker, atomic milestone deduplication, organizer delivery, policy for historical sales/refunds/zero-charge orders and template.
- **Reuse:** accepted payment finalization, event-specific order items and creator linkage. Browser popup callbacks, checkout initialization, `purchases.pending`, ticket row creation and all `registered` orders are unsuitable as unconditional paid-sale triggers. One successful cart can be the first sale for several events; two simultaneous orders can compete for one event milestone. A COUNT-before-send outside the transaction would race.

### 10.3 Sales and ticket availability monitoring

- **Exists:** `InventoryService::{commitments,reservedQuantities,availableQuantity}`, server sales windows/booking checks, dashboard per-tier/net inventory, legacy inventory compatibility and current lifecycle state.
- **Partial:** monitoring is on-demand API/read calculation, not an alert loop. Inventory changes with expired reservations, refunds, retirement and capacity edits as well as sales.
- **Missing:** threshold definitions, low-stock/sold-out/reopened alert rules, persisted threshold crossings, cooldown/repeat policy, scheduler selection and organizer notifications.
- **Reuse:** inventory and booking logic; do not decrement a second stock counter or treat `total_tickets` as available product units. A sold-out reservation can expire without a purchase; rule design must distinguish held from confirmed sold units.

### 10.4 Weekly sales performance summaries

- **Exists:** SQL weekly sales timeline, event timezone, Monday-start comparison, currency-partitioned financial metrics, historical fee/discount snapshots and settlement distinctions.
- **Partial:** dashboard's week-to-date comparison is not a persisted weekly reporting period or a positive-paid-only report; `overview()` also ensures default links and generates unnecessary chart/traffic sections for a minimal mail summary.
- **Missing:** send day/time/timezone policy, event eligibility window, organizer preferences, period snapshot/key, catch-up handling, summary template and automatic delivery.
- **Reuse:** focused metric queries/services with clearly stated gross/net/refund/currency semantics. Do not call the full public/dashboard response the weekly accounting contract without selecting and validating its measures. A rule/event/period identity must survive retries and scheduler downtime.

### 10.5 Guest preparation reminders seven days before an event

- **Exists:** event UTC start/timezone, venue/extra details, confirmed attendee/ticket eligibility, manual attendee messaging with consent/unsubscribe and reusable bounded audience builder.
- **Partial:** Vue approaching stage starts at 14 days and is only a display hint. The “Write a guest reminder” notification task depends on missing `overview.setup.reminder_saved`. There is no exact seven-day backend trigger.
- **Missing:** recipient decision (organizer preparation prompt versus actual attendee reminder), exact local-day/instant delivery policy, automation identity, schedule-change handling, late-publication catch-up, cancellation suppression, template and owner preferences where relevant.
- **Reuse:** for attendee mail, existing `event_messages` delivery/audience logic is a strong foundation after defining automated message ownership and idempotency. For organizer prompts, use owner identity and a distinct account notification contract. A raw `whereDate(date, now()+7)` ignores event timezone and outage recovery.

### 10.6 Check-in preparation reminders three days before an event

- **Exists:** staff roles/invitations, active entrances/windows, reentry policy, ticket/admission backend, online scanner client and event start/timezone.
- **Partial:** the notification panel has a “Practise door check-in” task, but its `setup.checkin_ready` input is not supplied by the backend. Seven-day invitation expiry is unrelated to a three-day reminder.
- **Missing:** three-day selector, organizer versus staff recipient policy, readiness definition, durable notification identity, template, preferences and repeat/catch-up behavior.
- **Reuse:** entrance/staff/admission data and actual preparation links. Do not consider merely opening a page or having a fixture entrance proof that doors/staff/devices are ready. Never embed ticket QR credentials in a generic preparation email or notification feed.

### 10.7 Shared unresolved automation policies

Before a later implementation, specify: first publication versus republish semantics; local calendar days versus elapsed hours; owner timezone versus event timezone for summaries; events published less than seven/three days before start; event rescheduling and already-sent reminders; cancellation/archive suppression; whether sales-closed events still need preparation mail; recurrence occurrence semantics; backlog catch-up and delivery lateness; account/email changes; email verification; required versus optional transactional mail; recipient unsubscribes; historical milestones; and operational retention/replay rules.

These are gaps in the current system, not reasons to rebuild working commerce. Durable rule occurrence/delivery identities are needed for the new triggers; the existing queues, locks, mail config, eligibility queries and outbox patterns can be reused. No such tables or services were added in this audit.

## 11. Deployment, environment and background-processing capabilities

### 11.1 Verified local configuration and checked-in deployment evidence

Safe local flags inspected: `APP_ENV=local`, debug enabled, MySQL connection driver, database sessions/cache/queue, non-secure session cookie, SameSite lax, log mailer. `config/app.php` uses UTC. These describe development, not acceptable production configuration or proof of production settings. No secret values were printed or copied into this report.

`config/services.php` consumes Google credentials/redirect configuration and Paystack secret/base URL. `config/commerce.php` consumes currency, reservation duration, platform fee basis points, dynamic split enablement and fee bearer. `config/filesystems.php`, `database.php`, `session.php`, `cache.php`, `logging.php`, `queue.php` and `mail.php` are the runtime configuration sources. Production config-cache values can differ from `.env`; no deployed cache was inspected.

`config/cors.php` lists localhost/127.0.0.1 development origins and supports credentials. `config/sanctum.php` defines stateful domains from environment/default localhost entries. These need a coordinated production origin/session strategy, not just a frontend URL variable. Sanctum's inspected middleware forces HttpOnly/lax cookie sessions for its stateful pipeline.

`composer.json` development command starts `artisan serve`, `queue:listen --tries=1` and backend Vite concurrently. It does **not** start `schedule:work` or a cron trigger and does not select the specified backend port explicitly. Merely running it will not drain scheduler-only outboxes. Backend `resources/js` is Laravel scaffolding, not the separate Vue product application.

Frontend package scripts include a `gh-pages` deployment of `dist`, and Vite builds for `/kaka/`. This is static hosting tooling only; it cannot host Laravel, a queue worker or cron. No checked-in production Docker, Supervisor/systemd worker definition, actual cron configuration or GitHub workflow was found in the inspected root/workflow locations. A previous handoff describing operations is not proof they are provisioned. No hosting account, process inventory or production network was inspected.

### 11.2 Operational requirements implied by the code

- PHP satisfying Composer requirements, required extensions, Composer dependencies and an HTTP host whose document root is backend `public/`; the frontend build served with the expected base and SPA fallback.
- A relational database with transactions, foreign keys and actual row-lock semantics; enough connections for HTTP, queue and scheduler work. Local migration-ledger success establishes connectivity, not sustained load capacity.
- Shared persistent cache/atomic locks, sessions and queue storage when using multiple web/worker instances. Existing database drivers can supply this foundation; Redis/Horizon is not a current prerequisite or implemented deployment.
- Writable private `storage`/bootstrap cache paths; persistent public event assets with the configured URL and storage link. All application instances need the same assets and encryption keys. Protect database/asset backups and `APP_KEY` needed to decrypt tickets/payout/recovery material.
- A supervised long-running queue worker consuming the configured queue, and a separate minute scheduler trigger (`artisan schedule:run` via host cron or equivalent). These are required deployment actions, not actions performed by this audit. `schedule:list` only lists definitions.
- One scheduler instance or correctly shared scheduler locks; `withoutOverlapping()` is present but no explicit `onOneServer()` configuration exists. Worker/job timeouts and queue retry_after must be compatible; current jobs reach 45 seconds and default retry_after is 90.
- HTTPS, production mode/debug off, secure cookies, canonical backend/frontend URLs, trusted origin/proxy behavior and preserved authentication/CSRF headers. Root `/api`, `/sanctum` and `/storage` routing must reach the intended backend/assets.
- Outbound Paystack and email connectivity; externally reachable correctly configured Paystack callback/webhook; provider test/live separation; real SMTP/API transport with authenticated sending domain. Existing local log mail does not meet external delivery requirements.
- Operators/monitoring for oldest queue age, failed jobs, due-row backlog, payment review/resolutions, failed messages, refund attention, ambiguous onboarding, settlement mismatch and unfinished cancellation. `/up` alone is not a database/mail/queue end-to-end health check.

There is no verified current cron, daemon supervision, external email delivery, bounce processing, queue restart recovery, backup restoration, production scaling or failover capability. Do not describe these as deployed merely because Laravel supports them.

## 12. Verification evidence and limitations

Executed during this audit:

| Command/check | Actual result |
|---|---|
| `git status --short` (backend, initially) | Clean working tree |
| `git rev-parse --short HEAD` | `80a88b7` |
| Frontend Git inspection with per-command `-c safe.directory=C:/Users/HP/kaka` | HEAD `0843d97`; existing recurrence-related modifications/untracked files noted above; no global Git configuration changed |
| `php artisan --version` | Laravel Framework 12.10.1 |
| `php artisan route:list --path=api --except-vendor` | Completed successfully; 79 application API routes |
| `php artisan schedule:list` | Completed successfully; four every-minute scheduled commands listed |
| `php artisan migrate:status` | Completed successfully; all 33 checked-in migrations marked Ran, through finance/lifecycle batch 15 |
| Targeted source/migration/frontend/configuration searches and reads | Completed; evidence cited throughout |

An initial combined PowerShell read failed to parse a Bash-style brace list; it executed no source reads/mutations and was rerun with explicit paths. Initial frontend Git calls hit Git's ownership check; read-only calls succeeded with a per-command safe-directory setting. These did not block source inspection.

No application test suite, browser E2E, build, formatter, dependency audit, load test, email delivery or real provider operation was run for this documentation-only audit. No test pass is claimed. No scheduler dispatcher was run. Existing test sources provide reusable verification scenarios:

| Existing test file | Relevant coverage intent (source evidence, not a fresh pass) |
|---|---|
| `tests/Feature/CreateEventTest.php`, `EventLifecycleIntegrityTest.php` | Draft/publish validation, ownership/history and editor consistency |
| `tests/Feature/EventConcurrencyTest.php` | Separate-process contention for editor/commerce/admission and related integrity |
| `tests/Feature/CommercePaymentLifecycleTest.php` | Pricing, verification, retry/late-charge, reconciliation and delivery cases |
| `tests/Feature/RegistrationAdmissionTest.php` | Seats/free registration, staff, scanner scope, admission/reentry/history |
| `tests/Feature/PromotionMessagingTest.php` | Discounts, audience deduplication, consent/unsubscribe, retry suppression, metrics and local-day aggregation |
| `tests/Feature/FinancialLifecycleTest.php` | Refunds, provider evidence, cancellation, settlement and lifecycle obligations |
| `tests/Feature/DashboardAnalyticsTest.php`, `ReleaseReadinessTest.php` | Dashboard/discovery and lifecycle read contract |
| `tests/Feature/ApiSecurityTest.php`, auth/recovery/integration/rollback tests | Authentication/resource isolation/recovery contracts; known historical auth disagreements remain |
| `tests/Unit/MoneyTest.php` | Integer monetary allocation behavior |

For a later implementation, `phpunit.xml` selects an in-memory SQLite testing database, array mail/cache/session and sync queue; that does not prove production queue or row locking. `scripts/test-isolated-database.php` creates a random disposable MySQL/PostgreSQL database, passes its name to the tests and removes only that generated database. Review its prerequisites before using it. Never run RefreshDatabase/migration reset tests against existing application records. `composer test` clears configuration first; it was deliberately not run during this read-only review.

The prior release report records 144 passed/11 failed in its historical full run and identifies authentication failures and advisory work. Those are explicitly historical, not this audit's results. Current frontend changes make a frozen cross-repository regression run necessary before implementation/release sign-off.

## 13. Handoff and completion record

**Problems verified:** the six automations have no automatic trigger/delivery identity; organizer notification persistence/preferences/feed are absent; backend attendee mail is disconnected from its Vue composer; notification UI relies on missing overview fields; lifecycle/status/phase and sales/financial metrics differ; recurrence formats are currently mismatched; local mail only logs; production worker/cron/provider operation is unverified; auth security findings and the noted integration risks remain.

**Changes made:** documentation only. File created: `C:/Users/HP/kakabackend/RUSH_HOUR_BACKEND_INFRASTRUCTURE_AUDIT.md`. No database migrations, API additions/changes, application refactors, configuration changes or frontend edits.

**Exact instructions for the frontend repository at this phase:** no integration change is required for this audit. Continue using `src/api/axios.js` and the existing service contracts; preserve approved design. In a separately authorized implementation phase, connect the message composer to the already implemented `/api/event-operations/{event}/messages` APIs before creating duplicate messaging endpoints; consume any newly specified organizer inbox/setup contract explicitly rather than fabricating overview fields; keep owner notifications separate from attendee/buyer delivery; reconcile the uncommitted recurrence payload with backend support; preserve version checks, server verification and cache invalidation. No new notification API contract is defined by this report.

**Ready for end-to-end testing?** The audit is ready for developer handoff. Existing implemented flows can be exercised in an isolated/staging environment, but the six future automations are **not implemented and therefore not ready for E2E acceptance**. Existing live messaging frontend integration, real mail/queue/scheduler operation, recurrence compatibility, provider/browser verification and identified release blockers must be resolved or explicitly scoped before claiming an automated lifecycle system works.
