# Phase 6 API handoff

24 November 2026. Backend implementation, preserving Phases 1-5. Authentication, Google sign-in, sessions, CORS and account login behavior were not changed. The separate frontend session remains responsible for Vue integration. No live payment, refund, bank onboarding or email operation was performed during verification.

## Verified problems and implementation

Existing seller allocations represented expected proceeds, not verified payouts. There were no bank-verification endpoints, confirmed refund ledger, refund-safe inventory/admission transitions or explicit event lifecycle operations. Payment success alone did not establish provider fees or settlement. Phase 6 extends the existing orders/items/payments/tickets/allocations/accounts, rather than introducing another commerce system.

The new forward-only migration is `2026_11_24_000005_add_financial_and_event_lifecycle.php`, after the Phase 5 migration. It retains original commercial amounts/credentials and adds refund/fee/settlement facts, versioned bank-account records, financial audit and notification records, durable cancellation work and provider settlement evidence. Existing allocation `gross_minor` retains its historical net-of-discount meaning; new `gross_before_discount_minor` and `discount_minor` explicitly separate gross and savings. Historical allocations are backfilled from their existing immutable items. Bank accounts become versioned records so changing a payout destination does not mutate the provider subaccount captured by older allocations.

A financial-history rollback is intentionally rejected; deploy forward migrations, never reset an application database. Local verification and isolated relational tests are listed at the end.

## Financial definitions

`GET /api/event-operations/{event}/earnings` returns `{data: metrics}`, with the same currency-partitioned metrics also exposed by dashboard overview. Owner access only. Never sum financial values across currencies.

- `gross_minor`: original item line totals on paid/registered/partially-refunded/refunded commerce records.
- `discounts_minor`: historical item savings; editing an offer does not reprice purchases.
- `charged_minor`: gross minus discounts, before refunds.
- `refunds_minor`: confirmed processed refund amounts allocated to this event's items; new zero-charge voids do not pretend to be provider refunds. `refund_tracking_supported: true` supersedes the Phase 5 unavailable marker.
- `legacy_refund_amounts_unknown`: an old refunded status exceeds confirmed item refund evidence. Do not claim legacy unknown refunds are zero or confirmed merely from an order status.
- `platform_fee_minor`: original platform fee snapshot. `platform_fee_reversed_minor`: the fee portion returned under the full-item refund policy. Original values remain unchanged.
- `provider_fee_minor`: independently observed provider fees, allocated exactly in minor units across item snapshots. Null if any charge-bearing item still has no verified fee evidence; `unknown_provider_fee_items` explains coverage. A zero fee is not invented for historical payments. Provider fees are currently borne by the platform and are not assumed returned on refund.
- `seller_net_before_refunds_minor`: original discounted seller proceeds less original platform fee.
- `seller_net_minor`: seller proceeds after confirmed full-item refunds. It is not proof of settlement.
- `confirmed_settled_minor`: original seller proceeds matched to successful provider subaccount settlement evidence. A refund after settlement does not erase money already paid to the seller.
- `pending_settlement_minor`: unrefunded seller proceeds lacking completed seller-settlement evidence. Platform receipt does not count as seller payout.
- `seller_recovery_due_minor`: refunded seller proceeds that were already paid out; requires operational recovery/reconciliation.
- `platform_net_minor`: platform fees less reversed platform fees and observed provider fees; null when fees are unknown. May be negative after a refund. This is not bank balance or withdrawable funds.

Historical gross/discount/charge totals remain visible after refunds. Inventory uses remaining product quantity, `quantity - refunded_quantity`, only once refunds are confirmed. Admissions use immutable item/table entitlements and ticket validity, never editable current ticket settings. `Order::isFulfilled()` now includes `partially_refunded`, preserving access to remaining valid tickets.

## Bank verification and payout onboarding

Existing Sanctum session; no authentication-flow changes:

- `GET /api/seller/banks?page=1`: provider-supported Nigerian NGN bank list, cached per page; maximum page 100.
- `GET /api/seller/payout-accounts?page=1`: only the current user's records, 20 per page, masked output.
- `POST /api/seller/payout-accounts`: `{ "bank_code": "058", "account_number": "0123456789" }`, 202 `{data: account}`. Bank code is 3-9 digits; account number exactly 10. Current launch onboarding supports Nigerian NGN accounts.

The account number is encrypted at rest, hidden from serialization and absent from queue payloads/audits. API responses include ID, masked last four digits, bank/account name after verification, status, onboarding state and safe error summary. An HMAC fingerprint makes repeated submission of the same account idempotent for that user. Changing details creates a new provider account; the old destination stays active until the new account is actually verified and active. An older job cannot supersede a newer active account.

`OnboardPayoutAccount` independently resolves bank/account ownership with Paystack, creates a subaccount, then checks provider identity, currency, activation and verification. Bank resolution alone is not represented as completed provider onboarding. Provider-created but unverified accounts remain `pending`; only verified/active ones become `active` for new checkout allocations. Provider subaccount creation has no assumed idempotency guarantee: a timeout/crash after the creating marker enters `review`, and is never blindly repeated. An operator must locate/reconcile that subaccount in Paystack before safely linking/re-enabling processing. Encrypted account details remain restricted during that review, and are cleared after successful creation.

Subaccounts retain captured identities for historical splits. Dynamic splits remain controlled by existing configuration. Only platform-borne provider fees (`paystack_fee_bearer=account`) currently have supported accounting semantics. Other fee-bearer configuration is rejected instead of silently assigning costs incorrectly. Neither onboarding nor allocations imply funds were settled.

## Settlement reconciliation

`finance:dispatch` creates durable feeds for the platform and provider-identified payout accounts. Feed jobs retrieve bounded pages of settlements, then independent jobs retrieve transaction membership and compare successful status, provider transaction ID/reference, original captured amount, currency and captured subaccount allocation.

A seller allocation is marked `settled` only after every statement page is present, every transaction matches, and expected seller proceeds exactly equal provider total/effective settlement amounts. Duplicate allocation or same-scope payment evidence cannot be applied twice. Unknown transactions, deductions, unsupported partial distributions, amount/currency differences or changed reconciled statements enter `review`; no payout is fabricated. A successful main-account settlement only records `platform_received` and `platform_received_at` on platform-held allocations, leaving seller settled amount zero. Pending settlements can progress to successful provider evidence without being mistaken for completed payouts prematurely.

First feed scan covers available provider history page by page. Subsequent scans overlap the previous watermark by seven days, and retain older pending settlement dates in the scan range. Each job performs one bounded provider page; the scheduler resumes work after crashes/provider failure. Reconciled data retains minimal financial references, not provider customer/card authorization payloads.

There is **no withdrawal button/API** and no unsupported transfer of a seller's allocated balance. Platform-held seller disbursement, seller recovery after an already settled refund, account-creation ambiguity and statement mismatches require operator/provider reconciliation. The application deliberately leaves such obligations pending/review. Do not offer a manual UI control that simply marks money paid.

## Refund API and product rules

Owner-only, event-scoped:

- `GET /api/event-operations/{event}/refunds?page=1&per_page=30`, maximum 100; safe status/amount/reason identifiers, no other event's contacts.
- `POST /api/event-operations/{event}/orders/{orderPublicId}/refunds`.
- `POST /api/event-operations/{event}/refunds/{refundPublicId}/retry`: schedules another verification of an attention record with the **same** provider reference; it does not create a fresh refund charge/reference.

Request:

```json
{"request_key":"a-new-uuid","order_item_ids":[123],"reason":"Buyer requested a refund"}
```

202 `{data: {public_id, amount_minor, currency, status, reason, processed_at, created_at}}`. The amount comes from server snapshots. Keep the same UUID for retries of the same operation; changing items/reason with a reused key returns 409. Different keys cannot refund the same line twice. Foreign/invalid order items return 422; another owner is 403.

Supported partial refund means **complete order-item lines**: all product units/admission seats on selected lines, including one organiser's lines in a multi-event order. There is no arbitrary-amount refund, partial table-seat refund or partial quantity within one line. A normal refund is rejected after any seat on that line has been admitted; event cancellation applies the explicit event-wide refund policy, including already admitted lines. These are the implemented launch rules, not inferred provider limitations.

Request processing locks order, sorted products, offer and payment, then admission credentials. It reserves the line refund and changes valid credentials to `refund_pending`, so scans cannot race admission against a refund. Inventory remains committed until confirmed completion. The durable job calls Paystack outside those locks, using a unique server refund reference. It first fetches an existing reference, then creates only when absent; timeout retries retain that reference. Provider identity, original transaction, amount, currency, refund reference and processed status must match before confirmation.

Statuses:

- `queued`: durable request; not a completed refund.
- `processing`: provider pending/processing; no refund amount recognized yet.
- `processed`: independently verified provider completion; immutable refund item amounts become confirmed, tickets become `refunded`, and only those product units leave inventory commitment.
- `voided`: zero-charge entitlement cancelled without a Paystack operation; no fake financial transaction.
- `attention`: mismatch, unsupported provider state or retry exhaustion; credentials remain suspended and money is not falsely recorded as returned. Retry only re-verifies/reuses the existing reference. A provider `needs-attention` customer-bank-details flow is handled operationally in Paystack; no insecure customer bank collection form was invented.

Full confirmed monetary refund moves the order to `refunded`; a partial order refund is `partially_refunded`, retaining valid unrelated tickets. A fully voided zero-charge registration becomes `cancelled`. Payment status remains the successful original charge with a separate `refunded_minor` fact; original price/discount/fee/allocation snapshots remain unchanged. Redeemed discount uses remain consumed after refunds. Entry/exit timestamps/history and inside flags are retained; revocation does not fabricate a physical exit.

Signed Paystack refund notifications only make durable verification due; duplicate notifications cannot confirm or repeat a refund directly. Existing webhook signature protection is preserved. Buyer refund/cancellation notifications use a separate durable transactional outbox to the stored buyer, with retries, independently of organiser marketing opt-out. `sent` means accepted by the mail transport, not confirmed inbox delivery. As with other mail, a crash after SMTP acceptance can cause an email repeat, never another financial refund or credential issuance.

## Event lifecycle

`POST /api/event-operations/{event}/lifecycle`, owner only, current event `version` (same `lock_version` used by the editor):

```json
{"version":2,"action":"set_cutoff","booking_cutoff_at":"2030-06-21T17:00:00+01:00"}
```

Supported `action`: `close_sales`, `open_sales`, `set_cutoff`, `complete`, `cancel`, `archive`. Cancellation requires a 3-300 character `reason`. Cutoff is nullable or an explicit-offset ISO instant and cannot exceed event end. Stale versions return 409. Response includes event ID, new version, lifecycle state and UTC cutoff. Preserve the new version in editor/dashboard stores.

Lifecycle states are separate from existing draft/active publication status:

- `open`: new paid/free checkout allowed only for published events, before cutoff and event end, subject to existing rules.
- `sales_closed`: no new checkout or failed-reservation reacquisition. Existing valid payment holds may complete before expiry. Reopening requires a published, unended event with a future/unset cutoff.
- `completed`: explicitly permitted after scheduled end; closes sales and blocks new entry. Existing commercial/admission history remains.
- `cancelled`: immediately closes new sales/entry and creates durable order-processing work. Published public details remain a cancellation notice with `booking_supported:false`; discovery excludes cancelled events.
- `archived`: retained in storage/owner records, hidden from public discovery/details, immutable in the editor. No destructive event deletion is introduced; existing retired delete mutations remain retired.

Paid and free booking validation share these state/cutoff rules; pending checkout retries cannot bypass them. Cancellation serializes with inventory and door scans. Existing unpaid multi-event carts are cancelled as a whole because the original quoted cart can no longer be fulfilled; valid paid orders are refunded only for this event's full lines. Paid unrelated event products remain valid. Free/complimentary entitlements are voided without a fake refund. Late independently verified successful charges for cancelled events enter the existing payment-resolution ledger and queue a same-reference refund without issuing admission credentials.

Cancellation worker batches 50 orders, with durable cursor/leases, financial audit and buyer notifications. An old paid record lacking a verified provider transaction becomes an auditable refund-attention obligation while the batch continues; no refund is invented. Legacy purchase records are preserved and audited as reconciliation obligations. Archiving waits for cancellation processing, unresolved refunds/registrations, pending seller settlement, legacy obligations and seller recovery obligations. Such records require real operational evidence before retention state can advance.

Door access takes a shared event lock before membership/credential locks, so cancellation/closure and in-flight scans have an actual serial order. New entry after cancellation/completion is denied. Sales closure alone does not revoke a valid issued ticket. Authorised staff can still record a physical exit for an already-inside seat after revocation/refund/cancellation; this does not restore entry entitlement. Admission lookup optionally accepts `direction: "out"` for that exit flow and returns minimal `ticket_status`; entry lookup stays restricted. Event-local timezone behavior and the existing recurring-booking block remain unchanged.

`GET /api/event-operations/{event}/financial-audit?page=1&per_page=30` exposes owner-scoped event audit records, maximum 100. Global payment/settlement/onboarding audits remain internal. Do not expose them through a public recipient or staff endpoint.

## Operations and frontend integration

Deploy Phases 4, 5 and 6 forward migrations in order, then restart existing workers. Run scheduler and a durable queue connection. `finance:dispatch` runs every minute alongside commerce, invitation and message dispatchers. Configure Paystack credentials/provider entitlement to bank resolution, subaccount creation, refund and settlement APIs; configure real mail transport and public HTTPS URLs. Test/live provider domains are separate. This implementation was tested with fakes, not real charges or disbursements.

Frontend session:

1. Keep authentication and approved layouts intact. Add services for the documented owner-only lifecycle/refund/earnings and current-user bank routes; no invented withdrawal endpoint.
2. Financial cards must display gross, discounts, confirmed refunds, platform fees, known/unknown provider fees, remaining seller proceeds, pending settlement, confirmed seller settlement and recovery obligations distinctly. Platform receipt is not seller payout. Refresh overview/earnings/purchases/tickets after confirmed changes.
3. Bank form sends details only to the backend; never persist account numbers in browser storage or expose them in logging. Display masked status, provider verification pending and review states truthfully. Editing creates a new destination record, not a mutation of old split history.
4. Refund controls select complete persisted item lines and display the server-derived amount/rule. Generate one UUID per intention and preserve it across retries. A 202 is queued, not refunded; poll bounded owner lists and handle processing/attention. Re-fetch protected orders for `partially_refunded` and per-line `refunded_minor`/`refunded_quantity` and ticket validity.
5. Lifecycle controls send current event version, preserve reason/cutoff timezone, refresh event/editor/dashboard caches on success, retain unsaved values on 409. Show published cancellation notices and disable booking when `booking_supported:false`. Archived public detail is 404; buyer order history remains protected and available.
6. Browser E2E needs bank onboarding with provider test facilities/fakes, full and multi-event partial refund, duplicate requests, processing/attention, cancellation with pending/paid/free orders, late payment, rejected door entry after cancellation, cutoff timezone, completion/archive obligations and owner isolation. Do not make live charges/refunds for verification.

## Files and checks

Core changes: existing OrderAllocation/PayoutAccount/Order/OrderItem/Payment/Event models; CheckoutService, PaymentFinalizer, PaystackService/PaystackSplitService, InventoryService, FreeRegistrationService, CreateEventService, AdmissionService and DashboardAnalyticsService/EventMetricsService. New RefundService, FinancialAudit, ProviderFeeService, FinancialWebhookService and EventLifecycleService; EventFinanceController and PayoutAccountController; six durable financial/lifecycle jobs plus routes/schedules. Phase 5 promotion/messaging files and existing Phase 4 tests were preserved/extended. See repository diff for exact paths; no auth files were modified.

Final executed checks and local migration preservation evidence are appended below. Backend contracts are ready for integration; full browser end-to-end sign-off belongs to the separate Vue session. Provider operational review, platform-held seller disbursement, legacy unknown financial facts and already-settled seller recovery remain explicit operational dependencies rather than fabricated success states.

Provider contracts checked against primary documentation: [Refund API](https://paystack.com/docs/api/refund/), [Refund reference and retry behavior](https://paystack.com/docs/payments/refunds/), [Settlement API](https://paystack.com/docs/api/settlement/), [Subaccount API](https://paystack.com/docs/api/subaccount/), [Bank account resolution](https://paystack.com/docs/api/verification/).

## Final executed verification

```powershell
$phaseTests = @(rg --files tests/Feature | Where-Object { $_ -notmatch 'AuthenticationIntegrationTest|EventConcurrencyTest' })
php scripts/test-isolated-database.php @phaseTests tests/Unit --stop-on-failure
# 130 passed, 967 assertions; MariaDB 10.4.32, 47.31 seconds.

php scripts/test-isolated-database.php tests/Feature/EventConcurrencyTest.php --stop-on-failure
# 13 passed, 144 assertions; actual separate-process MariaDB contention, 108.89 seconds.

php scripts/test-isolated-database.php tests/Feature/FinancialLifecycleTest.php tests/Feature/RegistrationAdmissionTest.php tests/Feature/PromotionMessagingTest.php --stop-on-failure
# Final focused run: 51 passed, 354 assertions, 20.43 seconds.
```

The broad regression includes original commerce/security/editor/profile/dashboard/recovery tests, the restored password/Google callback contract, promotion/messaging/finance/admission tests and integer allocation units. `AuthenticationIntegrationTest` is deliberately excluded: it targets the superseded auth response (including a role field) and conflicted with the user-restored authentication before this work. It was not weakened or rewritten. Authentication rollback tests and baseline hashes pass; no claim is made that every legacy auth expectation passes.

Pint `--test` passed all 58 specifically touched PHP files. PHP syntax checks passed those 58 files. `git diff --check` passed. `php artisan schedule:list` confirmed all four durable dispatch schedules. No frontend build/browser run was claimed from this backend session.

Dashboard measurement: 501 completed fixture orders, 102.42 ms for overview, 38 queries. EXPLAIN verified available event/currency/order, event/visitor, discount and attendee cursor indexes. MariaDB chose a full scan for the single-event 501-row commerce fixture (every row matched), primary-key order joins, and indexed traffic/quota lookups. This small development measurement justifies keeping SQL aggregation rather than introducing precomputed totals; it is not evidence of production-scale latency.

`php artisan migrate --force` applied Phase 5 and 6 locally in batch 15. Counts and SHA-256 digests of historical columns stayed identical across 12 existing tables. The local database has 29 events, two users and zero commerce orders/tickets; dedicated seeded forward-migration tests separately proved old ticket credentials, original totals, discounts, fees, payout destinations and pending settlement facts survive. Neither migrations nor checks created live provider operations. Existing authentication controller/provider/bootstrap/session/CORS hashes stayed unchanged.

Backend integration readiness: ready for the separate frontend session against this contract. Full end-to-end release sign-off remains pending that session's browser/build verification, production queue/mail/provider configuration and explicit operator handling of the financial review cases described above.

### Exact PHP paths touched for Phases 5-6

Earlier uncommitted Phase 4 work remains preserved; this list identifies files changed during promotion/finance integration and its verification.

```text
app/Http/Controllers/Api/CheckoutController.php
app/Http/Controllers/Api/DashboardController.php
app/Http/Controllers/Api/EventDiscountController.php
app/Http/Controllers/Api/EventMessageController.php
app/Http/Controllers/Api/EventFinanceController.php
app/Http/Controllers/Api/PayoutAccountController.php
app/Http/Controllers/Api/EventController.php
app/Http/Controllers/Api/EventOperationsController.php
app/Http/Controllers/Api/PaystackWebhookController.php
app/Http/Resources/PublicEventResource.php
app/Http/Requests/QuoteCheckoutRequest.php
app/Http/Requests/InitializeCheckoutRequest.php
app/Models/EventDiscount.php
app/Models/OrderItem.php
app/Models/Order.php
app/Models/Event.php
app/Models/Payment.php
app/Models/PayoutAccount.php
app/Models/OrderAllocation.php
app/Services/Commerce/CheckoutService.php
app/Services/Commerce/PaymentFinalizer.php
app/Services/Commerce/DiscountService.php
app/Services/Commerce/InventoryService.php
app/Services/Commerce/FreeRegistrationService.php
app/Services/Commerce/RefundService.php
app/Services/Commerce/FinancialAudit.php
app/Services/Commerce/FinancialWebhookService.php
app/Services/Commerce/ProviderFeeService.php
app/Services/Commerce/PaystackService.php
app/Services/Commerce/PaystackSplitService.php
app/Services/Dashboard/DashboardAnalyticsService.php
app/Services/Dashboard/EventMetricsService.php
app/Services/Dashboard/EventAttributionService.php
app/Services/RecommendationEngine.php
app/Services/CreateEventService.php
app/Services/EventMessagingService.php
app/Services/EventLifecycleService.php
app/Services/AdmissionService.php
app/Jobs/BuildEventMessageAudience.php
app/Jobs/DeliverEventMessage.php
app/Jobs/ProcessRefund.php
app/Jobs/DeliverFinancialNotification.php
app/Jobs/OnboardPayoutAccount.php
app/Jobs/ReconcileSettlementFeed.php
app/Jobs/ReconcileSettlement.php
app/Jobs/ProcessEventCancellation.php
app/Support/Money.php
database/migrations/2026_11_24_000004_add_promotion_and_messaging.php
database/migrations/2026_11_24_000005_add_financial_and_event_lifecycle.php
routes/api.php
routes/console.php
tests/Feature/PromotionMessagingTest.php
tests/Feature/FinancialLifecycleTest.php
tests/Feature/EventConcurrencyTest.php
tests/Feature/RegistrationAdmissionTest.php
tests/Support/event-editor-worker.php
tests/Unit/MoneyTest.php
app/Http/Controllers/Api/AdmissionController.php
```
