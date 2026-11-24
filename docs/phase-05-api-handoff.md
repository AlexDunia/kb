# Phase 5 API handoff

Backend implementation: 24 November 2026. Phase 6 extends financial reporting/refunds/settlement; see `phase-06-api-handoff.md` for the current financial fields (confirmed refund tracking now supported). Authentication, Google sign-in, sessions and CORS are unchanged. Phase 4 remains intact. The user confirmed a separate session owns Vue integration; no frontend files were edited here.

## Deployment

Apply the forward migration `2026_11_24_000004_add_promotion_and_messaging.php` after Phase 4. It adds event discounts, redemption reservations, message drafts/preferences/deliveries, immutable item discount/fee allocations and reporting/queue indexes. Existing order totals, prices, tickets, credentials and entitlements are preserved. Historical order discounts and seller allocation fees are distributed to their item snapshots once, with exact integer allocation. Invalid historical discounts greater than item gross stop migration before DDL; reconcile such data rather than resetting databases.

Run the existing durable queue worker and scheduler. `messages:dispatch` runs every minute, alongside `commerce:dispatch` and `admission:dispatch-invitations`. Each tick queues up to 100 audience-build batches (200 attendee rows each), up to 200 due deliveries and completion checks. Configure a real queued connection, working mail transport, public HTTPS APP_URL and the existing frontend URL in deployment. Do not use the synchronous queue for production delivery. No live payments or mail were used during verification.

## Discounts

Owner-only endpoints, under the existing Sanctum session:

- `GET /api/event-operations/{event}/discounts?page=1&per_page=30` (maximum 100).
- `POST /api/event-operations/{event}/discounts` creates a single offer, 201 `{data: offer}`.
- `PUT /api/event-operations/{event}/discounts/{id}` replaces that offer's configuration, 200 `{data: offer}`. Full configuration plus current `version` required; stale version returns 409. There is no array replacement or deletion endpoint. Historical redemptions persist.

Configuration:

```json
{"code":"EARLY20","name":"Early booking","partner":"","type":"percent","value":20,"usage_limit":100,"active":true,"starts_at":null,"ends_at":null,"ticket_type_ids":[42],"version":1}
```

Code: uppercase letters, digits and hyphens, 3-20 characters, unique within an event. Name: 2-100 characters. Partner: optional, maximum 100. `type`: `percent` or `fixed`. Percentage `value` is an integer 1-100. Fixed `value` is an integer **minor currency amount**, 1-100,000,000; do not send display currency decimals. Currency is server-assigned. `ticket_type_ids: null` means all of this event's paid products; a supplied nonempty list must belong to the event. Foreign product IDs return 422. Strings representing integer IDs are normalized to integers.

`starts_at` and `ends_at` are optional ISO instants with explicit UTC/offset, not timezone-free dates. Start is inclusive; end exclusive. When both exist, end must follow start. Vue's date-only inputs should map start-of-day and day-after-end start-of-day in the event timezone to explicit instants. Do not append Z to an event-local wall time.

List rows include `used` (redeemed orders), `reserved` (unexpired offer holds), version and immutable identifier. Quota counts one **order** per use, irrespective of product quantity. Remaining uses = `usage_limit - used - reserved`. Pause/resume uses PUT with `active`; changing dates or limits requires the current version. A limit cannot be reduced below redeemed plus reserved uses. Use the server clock and dates for scheduled/expired/paused/used-up display states. Do not treat an offer as available solely from a cached browser count.

Checkout quote and initialization accept the existing payload plus:

```json
{"discount":{"event_id":123,"code":"EARLY20"}}
```

One event-scoped code per checkout; it affects only eligible lines for that event, including in a multi-event cart. Backend prices determine all amounts. Quotes return `discount_minor`, net `total_minor`, per-line `discount_minor` and the offer snapshot. Quote is advisory and does not reserve anything. Initialization repeats validation under inventory and offer locks and persists the offer version/code/type/value/currency plus allocated savings. Keep the existing server order/ticket IDs and checkout-token protections. Changing a code changes cart identity and requires a new checkout token.

`line_total_minor` stays **gross historical line price**. Line net = line total minus line discount. Order subtotal is gross; total is net. Seller allocation fees/net use discounted proceeds. Exact integer proportional allocation handles large totals without floating point or overflowing intermediate multiplication.

Lifecycle:

- New checkout reserves inventory and one offer use atomically before provider initialization, outside which no inventory/offer database locks are held.
- A hold lasts until the existing order expiry. Pause or offer edits do not reprice a held order or revoke its valid hold.
- Latest-attempt definitive failure releases an unredeemed offer hold. An older failed attempt cannot release a newer valid reservation.
- Failed-payment retry reacquires inventory and quota; the offer must still be active/in date. Historical order totals remain unchanged. A retry that cannot reacquire returns 422.
- Expiry releases the offer by its stored hold deadline even if the expiration scheduler has not run.
- `POST /api/checkout/orders/{publicId}/cancel`, protected by existing buyer/checkout/recovery access, cancels pending/failed/expired orders and releases holds. Idempotent for already cancelled orders. Fulfilled/review orders return 409; this is not a refund endpoint.
- Successful finalization redeems once. A late charge must reacquire quota as well as inventory; otherwise the existing payment resolution ledger records `discount_limit_charge` and no tickets are issued. A delayed charge for a held offer is honored at its historical discount even after an offer edit/pause.
- Redeemed uses are **never restored by cancellation/refund/status changes**. Refunds do not permit repeat use of limited offers. No refund or settlement is fabricated.
- A fully discounted order is fulfilled as existing `registered`, with zero net total and no Payment or Paystack call. Normal immutable items, attendee entitlements, admission credentials and purchase-delivery outbox are issued once. Vue must handle `payment: null` and show the protected fulfilled order instead of opening Paystack.

## Analytics

Existing overview/share-link endpoints remain owner-only. `GET /api/dashboard/events` now uses Laravel pagination (`data`, `current_page`, `last_page`, `per_page`, `total`, URLs); default 30, maximum 100. Keep `data` array use, add page controls instead of assuming a complete owner catalog.

Overview retains existing keys and adds `metrics.by_currency`, `metrics.actual_attendance`, `metrics.currently_inside`, and attendance fields under `sales`.

| Metric | Definition |
| --- | --- |
| Buyers | Distinct normalized stored buyer email on completed commerce records, never inferred from attendee names. One buyer may have multiple orders and need not attend. |
| Orders | Distinct existing order IDs containing event lines. Multi-event orders count once for each relevant event, not once per item. |
| Product units | Sum immutable order-item quantity. One table is one product unit. |
| Admissions covered | Sum immutable quantity times historical people-per-unit; never derive historical entitlement from current product configuration. Current `sales.admission_entries_covered` excludes refunded orders. |
| Actual attendance | Number of attendee entitlement seats with at least one recorded entry. Re-entry does not increase it. This measures seats admitted, not verified unique physical identities. |
| Currently inside | Entitlement seats with stored `inside=true`. |
| Gross | Sum historical line totals before discounts on completed paid/registered/refunded records. |
| Discounts | Sum immutable allocated line discounts on those records. |
| Charged | Gross minus discounts; before refunds. Complimentary/free registered records have zero net. |
| Refunds | `refunds_minor: null`, `refund_tracking_supported: false`. Existing data has no confirmed amount ledger. `refunded_order_totals_minor` is the original net price of orders flagged refunded, not proof of refund amount or settlement. |
| Seller net | Historical line net minus snapshotted platform fee, **before refunds**, also explicitly `seller_net_before_refunds_minor`; `seller_net_is_settled: false`. No claim that Paystack has settled it. |
| Views | One persisted traffic view per event/visitor/hour, not every browser page open. |
| Visitors | Distinct pseudonymous visitor hash for the event; cookies/UUIDs are not verified people. |
| Conversion | Event traffic visitors associated with completed commerce; per-source conversion uses the stored checkout attribution and matching visitor hash, not unrelated guest buyer email counts. |

`metrics.by_currency` never sums different currencies. Financial reporting includes historical refunded orders so a status change does not erase original gross/discounts. Current inventory sales/availability remain aligned with InventoryService (including explicitly preserved legacy commitments), while historical commerce metrics use orders/items. Legacy purchase commitments can therefore affect inventory utilization without inventing legacy admission or financial snapshots. Existing scalar revenue/trend/source-money fields are scoped to configured commerce currency and now use line net. Do not label them gross or combine them with another currency. Use the currency partition for financial cards; never total buyer counts across currencies.

Historical chart retrieval is SQL aggregated, maximum 1,095 local days. IANA timezone transition offsets preserve DST/half-hour day boundaries without depending on optional MySQL timezone tables. `sales_trend.max_days`, `truncated` and `from` disclose the window. `best_sales_day.scope: chart_window`; buying-time patterns use that same window. Lifetime revenue totals remain lifetime, independent of chart truncation. No precomputed aggregate tables were justified by the measured development fixture; this is not a production load-test claim.

Recommendations now combine current paid/registered commerce and traffic with read-only completed legacy purchases and legacy views. Legacy rows remain untouched. Each signal is independently combined before category aggregation, preventing multiplicative joins; view presence is one event signal across old/new tables. Completed purchase event IDs are union-deduplicated. Responses keep existing sections and add correct `currency`/`price_minor`; formatted price uses currency code instead of `$`. Available products/current inventory determine advisory availability. User cache is a single versioned 30-second key, invalidated on likes via the existing hook, new authenticated traffic, and committed ticket issuance; global event changes converge within 30 seconds. Checkout is always authoritative.

## Messaging

Owner-only (door staff and public crew labels confer no access):

- `GET /api/event-operations/{event}/messages?page=1&per_page=20` (maximum 100), persistent drafts/status/counts; never recipient addresses.
- `POST /api/event-operations/{event}/messages` creates draft, 201.
- `PUT /api/event-operations/{event}/messages/{id}` edits only a draft, full fields plus current `version`. Stale or already queued returns 409.
- `POST /api/event-operations/{event}/messages/audience` previews eligible unique address count, no contact data.
- `POST /api/event-operations/{event}/messages/{id}/send` with `{ "version": 1 }` queues once, 202. Repeated send returns existing status without re-enqueuing recipients.
- `POST /api/event-operations/{event}/messages/{id}/retry` retries only a failed campaign's failed deliveries; never resends successful rows.

Draft/preview fields:

```json
{"subject":"Your event details","body":"Doors open at 6 pm.","purpose":"event_update","audience":"ticket_types","ticket_type_ids":[42],"version":1}
```

Subject: maximum 150, no CR/LF. Body: plain text, maximum 20,000. Purpose is explicitly `event_update` (necessary event information) or `marketing` (requires opt-in). Do not send promotion as an event update. Audience: `all_ticket_holders`, `ticket_types` (requires nonempty actual product ID list), or `checked_in`. Existing VIP/general/early-bird labels must map to actual persisted ticket IDs, not hardcoded name guesses. `ticket_type_ids: null` for other audiences.

Recipients must have current valid tickets on fulfilled orders for this event. Named attendee email is used where provided; unnamed entitlement seats fall back to the stored buyer as an intermediary. A table does not send duplicate emails: unique `(message_id,email)` records deduplicate across seats and orders. The queued maximum attendee ID excludes later registrations; audience generation processes current eligible rows through that bound, and delivery rechecks current entitlement/preferences. Refund/revocation, unsubscribe or consent withdrawal before delivery suppresses the row. No arbitrary recipient email upload, no contact list exposure, no SMS provider invented.

Campaign states: `draft`, `preparing`, `sending`, `completed`, `failed`. `completed` means all recipient rows processed, including empty/suppressed audiences; it does not claim email delivery. Delivery counts: `pending`, `processing`, `sent`, `suppressed`, `failed`. `sent` means accepted by configured mail transport, not verified inbox delivery/read. Draft/list payloads use decoded ticket IDs and UTC ISO timestamps.

Jobs use unique queue keys plus atomic database leases, five durable attempts and backoff. Scheduler recovers expired processing leases, including crashes at the final attempt. Each email has a stable Message-ID. Duplicate jobs cannot intentionally resend an acknowledged successful delivery. SMTP acceptance followed by worker crash is inherently ambiguous: delivery is at least once and an email may repeat; this does not duplicate purchases/tickets. Do not promise exactly-once email.

Marketing opt-in defaults false; legacy purchases are not blanket consent. `PUT /api/checkout/orders/{publicId}/message-preferences` takes `{event_id, marketing_consent}` and existing order access. Opt-in **also** requires a valid exchanged `X-Order-Recovery-Token` from the existing emailed single-use recovery mechanism, proving control of the stored buyer mailbox. An authenticated account or raw checkout token alone cannot opt somebody else's email in. It affects only the stored buyer address, never another named attendee's. Named attendees without separately proven consent do not receive marketing. Opt-out requires existing order access but not an extra mailbox proof. Consent timestamp is persisted.

Each organiser email contains a signed backend unsubscribe URL. GET displays confirmation without mutating preferences (link scanners do not unsubscribe); POST to that signed URL persists event-specific suppression. Unsigned/altered links return 403. Unsubscribing suppresses organiser updates and marketing, while essential existing purchase/recovery/ticket-resend delivery remains available through the established commerce outbox. Sender policy and local consent/retention requirements still need deployment review; the software does not certify legal compliance.

## Vue integration sequence

1. Keep existing auth and the approved promotion/message layouts unchanged. Replace live browser-only discount/draft persistence with these endpoints; preview-mode sample data may stay isolated to preview mode.
2. Map current promotion UI: fixed `value` in display currency to integer minor units; percent unchanged; `limit` -> `usage_limit`; `eligible: ['all']` -> null, otherwise integer ticket IDs; date-only start/end -> event-local day boundaries converted to instants. Preserve server IDs/version and handle 409 by reloading while retaining unsaved form values.
3. Fetch discount pages and display both redeemed and reserved uses. Save/pause/resume a single offer; do not overwrite collections. Keep quotes advisory and surface `discount.code` validation messages from 422.
4. Include event/code in quote and initialize, regenerate checkout token when it changes, display gross/discount/net from server, and support fulfilled zero-total `payment:null` without Paystack.
5. Replace message local drafts with paginated server drafts. Provide explicit purpose, real-product audience mapping, count preview and an explicit send action. Saving does not send. Poll status/counts with bounded requests and show failed/suppressed counts, not fabricated success.
6. Add buyer preference integration only after emailed order recovery; never auto-opt-in at checkout. Use backend signed confirmation links as delivered.
7. Refresh event overview, promotions, relevant buyer/order and dashboard stores after successful mutations. Handle dashboard event pagination. Financial cards use currency-specific metrics and truthful unavailable refund/settlement labels. Show chart-window metadata when truncated.
8. Run frontend lint/build and browser verification: create/pause/resume offer; eligible/ineligible code; parallel last-use checkout; failed retry; 100% complimentary order; paid order and public ticket display; draft/save/send/retry; consent/unsubscribe; foreign-owner denial; currency/attendance dashboard; confirm Google auth is untouched.

## Verification and readiness

Backend tests use provider fakes and isolated MariaDB 10.4.32, never SQLite as proof of row-lock correctness. Actual command/results and measurement are recorded below after the final regression run. The backend contract is ready for the separate Vue session. End-to-end Phase 5 sign-off requires that session's integration/build/browser checks, production queue/mail setup, and an actual verified refund ledger before displaying confirmed refund amounts.

Final integrated verification: 130 regression tests / 967 assertions and 13 separate-process MariaDB concurrency tests / 144 assertions passed, plus the final 51-test promotion/finance/admission run. Pint and syntax passed 58 touched PHP files; migration preservation and auth hashes passed. See Phase 6 handoff for exact commands, the excluded pre-existing auth expectation and current financial extensions. Phase 5 migration applied locally in batch 15. The Vue session still owns browser/build integration.
