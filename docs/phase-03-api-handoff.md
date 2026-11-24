# Phase 3 ? commerce, inventory and payment hardening

## Verified problems and changes

Older failed payment attempts could change an order back to payment_failed after a newer attempt acquired a reservation. Initialization responses could overwrite successful payments, and cancellation/refund states could be revived by finalization. Initialization network failures were incorrectly treated as definitive payment failures. Released capacity must be reacquired even when a provider reports an earlier payment timestamp.

Existing order mutations now lock the order, sorted ticket rows, then payment rows. New checkout locks sorted ticket rows before creating its order. Provider initialization and verification happen outside these transactions. Retry reacquires capacity and creates its attempt in one transaction. Prices, names and admission counts remain immutable order-item snapshots. Finalization validates reference, integer minor amount, currency and success; checks all inventory lines; and serializes ticket issuance. Cancelled/refunded orders retain their status. Failed notifications affect reservation state only for the latest attempt.

Initialization failures retain the same unresolved reference and reservation until verified failure or expiry. Retry with the same checkout token does not reopen an ambiguous initialization. The UI must offer payment-status checking without creating another order automatically. Verified failed attempts may retry the same order while its original expiry remains in the future, subject to fresh capacity checks. Expired attempts require a new checkout token.

## Migration

`2026_11_24_000002_harden_commerce_processing.php` adds payment_reconciliations, payment_resolutions, order_recoveries and commerce_deliveries. Existing unresolved payments are backfilled in bounded batches. Existing commerce records and ticket capacities are preserved. Apply with `php artisan migrate --force` after backup/review in deployment; never reset an existing database.

## Existing checkout contracts

POST /api/checkout/quote and /initialize retain their existing payloads and responses. Prices in payloads are ignored; server prices are authoritative. X-Checkout-Token retains its existing capability protection. Authenticated order owners can read their orders without this token.

GET /api/checkout/orders/{orderPublicId} and POST /api/checkout/payments/{reference}/verify return `{data: order}`. Order fields include public_id, status, currency, subtotal_minor, discount_minor, total_minor, created_at, expires_at, paid_at and items. Each item retains historical names, integer money, quantity, people_per_unit and ticket identifiers. Tickets with token, admit_count and status are returned only for paid orders to authorized callers.

Initialize returns `{data:{order,payment}}`; payment includes reference, status, access_code and authorization_url. An unresolved attempt may have a reference but no access_code. Do not interpret this as a failed charge or create a fresh token automatically. Keep the reference and offer status checking.

Statuses: pending_payment, payment_failed, expired, payment_review, paid, cancelled, refunded. payment_review means manual investigation is required; it is not a promise that payment was received. Never automatically ask the buyer to pay again for review orders. Cancelled/refunded orders never expose valid ticket credentials.

## Buyer APIs

- GET /api/buyer/orders requires the Sanctum session. Query page >=1 and per_page 1..50 (default20). Standard Laravel paginator response: data contains order objects, plus current_page,last_page,total,per_page,etc. Only orders with this user's user_id appear; matching email alone never grants account access.
- GET /api/buyer/tickets/{ticketPublicId} requires owning session, matching X-Checkout-Token or a valid X-Order-Recovery-Token. Returns `{data:{public_id,token,admit_count,status,order_public_id,event_title,ticket_name}}`. Unpaid/cancelled/refunded orders cannot expose credentials. Another account receives403.
- POST /api/checkout/recovery/request `{order_public_id,email}` always returns202 with the same generic message for validly shaped input. A matching stored purchase email queues a recovery message. Five requests per ten minutes per IP; one delivery per order per ten-minute bucket.
- Recovery emails link to FRONTEND_URL/orders/recover#token=<64-character token>. The fragment avoids sending the credential in HTTP paths, access logs and referrers. Clear it immediately in the browser.
- POST /api/checkout/recovery/exchange `{token}` exchanges the single-use, 24-hour email token for `{data:{order_public_id,access_token,expires_at}}`. Ten requests per ten minutes. Reuse/expiry returns403. Store the returned capability only in sessionStorage for that order and attach X-Order-Recovery-Token on authorized reads/verification. Access lasts30 minutes and cannot authorize another order. No account ownership is transferred.
- Session mutations use the existing Sanctum CSRF-cookie flow. Invalid fields422; unauthenticated history401; unauthorized403; missing404.

## Durable processing and operations

Use QUEUE_CONNECTION=database (or a durable production queue), a supervised `php artisan queue:work --timeout=60 --tries=3`, and `php artisan schedule:run` every minute. Run migrations before enabling workers. Configure a real mail transport and FRONTEND_URL/config app.frontend_url correctly. Do not use sync queues in production: the test suite deliberately uses sync with provider and mail fakes.

`commerce:dispatch` queues at most200 due payment checks and200 email deliveries per run and marks pending orders with elapsed reservations expired. Unique jobs reduce duplicate dispatch; atomic database leases protect processing. Queue/provider failures leave durable due work for later scheduling. Provider requests never hold inventory locks. Signed webhooks persist reconciliation work before acknowledgement and dispatch provider verification. Unknown signed charge references are retained for investigation.

Unresolved created/initialized attempts poll every5 minutes; verified failed attempts every6 hours to detect later success. After14 days of provider-resolved but nonterminal responses, reconciliation records unresolved_provider_status for manual review. Repeated provider errors remain due with attempts/last_error visible. Success/review attempts stop polling; signed notifications can trigger a further check. No refund or settlement confirmation is invented.

Payment_resolutions records verification mismatches, unfulfillable charges, terminal-order charges, additional successful payments, duplicate/invalid transaction identities and unknown signed charges. Evidence contains provider transaction identity/amount/currency/status, without card details or credentials. Operators must review unresolved rows, compare provider records and perform any actual refund through an approved operational process. This phase adds no automatic refund or settlement workflow.

Successful issuance atomically creates one purchase delivery outbox row. Scheduler jobs email a secure recovery link to the stored purchase address. Credentials are accessed through the protected order page. Email failures are retried; sent_at is recorded only after transport success. Delivery is at least once: a crash after transport acceptance but before sent_at may cause duplicate email, not duplicate tickets. Encrypted recovery material is cleared after successful delivery. Monitor failed_jobs, overdue reconciliation/delivery rows and unresolved payment_resolutions.

## Frontend integration

Preserve the current checkout and QR presentation. Use server statuses; retain order/reference when initialization is unresolved; show precise cancelled/refunded/review states. Replace the unavailable history placeholder with paginated /buyer/orders and links to existing /payment-success/{public_id}. Add /orders/recover for request/exchange, session-scoped recovery headers and protected order display. Do not put QR credentials in URLs, localStorage or analytics.

## Verification and completion report

- `php scripts/test-isolated-database.php`:93 passed,751 assertions on isolated MariaDB10.4.32. Six separate-process tests cover two editors, editor/checkout locking in both directions, last-unit purchases, duplicate finalization, and failed retry competing with a new purchase. The generated database was removed afterward.
- `php scripts/test-phase03-http.php`:passed real HTTP calls from the actual Vue services to an isolated Laravel server. Verified quote, idempotent initialization, successful/repeated verification, historical table admission, authenticated history, queued email capture, guest recovery exchange, single-use links and unauthorized denial. Paystack responses are mocked; mail is captured. Temporary database/server/files were removed. This HTTP fixture uses SQLite; locking correctness comes from the separate MariaDB run.
- Scoped ESLint passed for all Phase3 Vue/service/router/test files. `npm run build` passed. `node scripts/check-phase03.mjs` passed recovery scope/expiry, headers, encoded references, CSRF mutation and history pagination checks. Phase1B19 checks and Phase2 frontend18 checks also passed.
- Pint `--test`:19 PHP files passed. `git diff --check` passed. `php artisan schedule:list` confirms commerce:dispatch every minute.
- The new forward migration was applied locally; counts across events,ticket_types,orders,order_items,payments,tickets,order_allocations,purchases,users were unchanged. No real charges or outbound email were created.

Backend files changed: CheckoutService, PaymentFinalizer, OrderRecoveryService, PaystackWebhookController, BuyerOrderController, ReconcilePayment, DeliverOrder, Money, routes/api.php, routes/console.php, the commerce migration, commerce/event lifecycle and concurrency tests, worker fixture, MoneyTest and isolated HTTP harness scripts. Existing inventory aggregation, commerce models, order snapshots and Paystack split architecture remain in use.

Vue files changed: src/services/checkoutService.js, src/utils/checkoutStorage.js, src/pages/Checkout.vue, src/pages/PaymentSuccess.vue, src/pages/TransactionHistory.vue, src/pages/OrderRecovery.vue, src/router/index.js, scripts/check-phase03.mjs and scripts/check-phase03-e2e.mjs. Existing unrelated Phase2 work was preserved. The integration described above is implemented in C:/Users/HP/kaka, with the approved checkout/QR/table presentation retained.

Ready for interactive test-mode end-to-end validation. The provider popup and actual test-mode provider delivery still require an interactive pass with the configured account. Production readiness depends on the operational requirements below; this work does not start another phase.

## Remaining deployment dependencies

Supervised queue workers, minute scheduler, mail transport, correct frontend URL, operational payment-resolution monitoring and actual provider test-mode end-to-end testing remain deployment requirements. This implementation never creates live charges, sends real test mail, fabricates refunds or reports settlement as confirmed.

Google authentication was restored to its pre-Phase3 configuration at the user's request. No Google authentication changes are included in this phase.
