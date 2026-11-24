# Rush Hour current API contract

Verified 24 November 2026 against Laravel route registration. This is the current route inventory and shared contract; the phase handoffs linked below define detailed payloads. It supersedes the prospective Vue `docs/api-spec.md`, which incorrectly prescribes bearer authentication. No new authentication behavior is introduced here.

## Shared rules

- Base path `/api`; browser requests use the existing same-origin proxy. Production must proxy `/api` and `/sanctum` to Laravel. Do not embed a localhost API origin in built assets.
- Browser authentication is the existing Sanctum session/cookie flow. Obtain `/sanctum/csrf-cookie` before mutations, send credentials and the XSRF header. Google uses the existing redirect/callback routes. Authentication security findings are in the release report.
- JSON errors contain `message`, and validation errors may contain `errors` keyed by field. Handle 401, 403, 404, 409 (reload stale version without discarding edits), 419, 422, 428 (missing editor version), 429 and uncertain network outcomes distinctly. Do not display arbitrary provider exceptions.
- Preserve numeric database event/product IDs and ULID public order/ticket/refund IDs. Never substitute labels, array indexes or preview IDs. Use `encodeURIComponent` for path parameters.
- Commerce `*_minor` fields are integer minor currency units, grouped by currency. Editor product `price` is a decimal major-unit input. Fixed discount values are minor units; percentage values are integer percentages. Never trust client totals or merge currencies. Unknown historical provider fees remain null.
- Store instants in UTC, exchange ISO timestamps with explicit offsets, and preserve event IANA `time_zone`. Local editor inputs must round-trip with the event zone. Nullable cutoff means no additional cutoff, not unlimited booking after event end.
- Most mutations return `{data: record}`; paginators return `data` plus their endpoint-specific pagination fields. Public event resources use `data`, `links`, `meta`; operations/financial paginator responses use Laravel paginator fields. Do not flatten every response using one guessed envelope.
- Owner editor summaries now include `id,status,title,version,lifecycle_state,booking_cutoff_at,form`. Editor PATCH/publish and lifecycle operations require current `version`; merge ticket changes by default. Explicit replacement retires omitted products rather than deleting commercial history.
- Public catalog: `GET /events` validates `q,city,category/category_id,subcategory_id,date_from,date_to,sort,featured,page,per_page` (max100); Vue discovery uses12. Draft/private fields and ticket credentials are excluded.
- Public checkout quotes are advisory. Initialization uses `checkout_token` UUID, customer, real item IDs/quantities, attendee answers, optional visitor and one `{event_id,code}` discount. Server prices/snapshots/stock/discount eligibility win. A successful provider popup does not establish payment success.
- Protected order/ticket access requires matching authenticated buyer, `X-Checkout-Token`, or exchanged `X-Order-Recovery-Token`. Email/public ID alone is insufficient. Do not log or persist ticket credentials in reports or analytics.
- Free registration uses event registration endpoints and issues entitlements without a fake paid transaction. Buyer and named attendees are distinct. Table seats require their own configured answers.
- Admission is online, event/staff/entrance scoped. Lookup does not admit. Movement UUIDs make retries idempotent; preserve the same UUID after an uncertain response. Partial multi-person admission is supported. Network loss must not display a confirmed admission. Exit can record physical departure after revocation without restoring entry.
- Discounts, messages and finance require owner authorization. Door membership grants no financial or full attendee access. Marketing consent and signed unsubscribe are distinct from essential ticket/refund notices.
- Refunds select complete persisted order-item lines, use a UUID request key and return202 queued, never presumed completed. Provider verification confirms money movement. Allocated funds and platform receipts are not seller settlement.

## Detailed payloads and invariants

- [Event editor, media and discovery](phase-02-api-handoff.md)
- [Commerce, recovery and payment reconciliation](phase-03-api-handoff.md)
- [Registration, staff and admission](phase-04-api-handoff.md)
- [Discounts, analytics and messaging](phase-05-api-handoff.md)
- [Finance, refunds and lifecycle](phase-06-api-handoff.md)
- [Release evidence and unresolved contracts](phase-07-release-report.md)

## Registered routes

Generated from `php artisan route:list --json --path=api`. `auth:sanctum` below means authenticated; each controller additionally enforces ownership/staff/order-capability rules. Rate limits remain endpoint-specific.

| Method | Path | Middleware |
|---|---|---|
| GET|HEAD | `/api/auth/google` | api, web |
| GET|HEAD | `/api/auth/google/callback` | api, web |
| GET|HEAD | `/api/buyer/orders` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/buyer/tickets/{publicId}` | api |
| GET|HEAD | `/api/categories` | api |
| POST | `/api/checkout/initialize` | api, Illuminate\Routing\Middleware\ThrottleRequests:checkout-initialize |
| GET|HEAD | `/api/checkout/orders/{publicId}` | api |
| POST | `/api/checkout/orders/{publicId}/cancel` | api, Illuminate\Routing\Middleware\ThrottleRequests:10,1 |
| PUT | `/api/checkout/orders/{publicId}/message-preferences` | api, Illuminate\Routing\Middleware\ThrottleRequests:10,1 |
| POST | `/api/checkout/payments/{reference}/verify` | api, Illuminate\Routing\Middleware\ThrottleRequests:checkout-verify |
| POST | `/api/checkout/quote` | api, Illuminate\Routing\Middleware\ThrottleRequests:checkout-quote |
| POST | `/api/checkout/recovery/exchange` | api, Illuminate\Routing\Middleware\ThrottleRequests:10,10 |
| POST | `/api/checkout/recovery/request` | api, Illuminate\Routing\Middleware\ThrottleRequests:5,10 |
| POST | `/api/create-event` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/create-event/{id}` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| PATCH | `/api/create-event/{id}` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| POST | `/api/create-event/{id}/publish` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/dashboard/events` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-read |
| GET|HEAD | `/api/dashboard/events/{event}/overview` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-read |
| GET|HEAD | `/api/dashboard/events/{event}/share-links` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-read |
| POST | `/api/dashboard/events/{event}/share-links` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| GET|POST|HEAD | `/api/event-message-preferences/{delivery}/unsubscribe` | api, Illuminate\Routing\Middleware\ValidateSignature, Illuminate\Routing\Middleware\ThrottleRequests:10,1 |
| GET|HEAD | `/api/event-operations/{event}/admission` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/event-operations/{event}/admission/history` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| POST | `/api/event-operations/{event}/admission/lookup` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:60,1 |
| POST | `/api/event-operations/{event}/admission/movements` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:120,1 |
| PUT | `/api/event-operations/{event}/admission/settings` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/event-operations/{event}/attendees` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/event-operations/{event}/attendees/export` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| PATCH | `/api/event-operations/{event}/attendees/{attendee}` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/event-operations/{event}/discounts` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| POST | `/api/event-operations/{event}/discounts` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| PUT | `/api/event-operations/{event}/discounts/{discount}` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| GET|HEAD | `/api/event-operations/{event}/earnings` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| GET|HEAD | `/api/event-operations/{event}/entrances` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| POST | `/api/event-operations/{event}/entrances` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| PUT | `/api/event-operations/{event}/entrances/{entrance}` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/event-operations/{event}/financial-audit` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| POST | `/api/event-operations/{event}/lifecycle` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| GET|HEAD | `/api/event-operations/{event}/messages` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| POST | `/api/event-operations/{event}/messages` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| POST | `/api/event-operations/{event}/messages/audience` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-read |
| PUT | `/api/event-operations/{event}/messages/{message}` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| POST | `/api/event-operations/{event}/messages/{message}/retry` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| POST | `/api/event-operations/{event}/messages/{message}/send` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| POST | `/api/event-operations/{event}/orders/{publicId}/refunds` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| GET|HEAD | `/api/event-operations/{event}/purchases` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/event-operations/{event}/refunds` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| POST | `/api/event-operations/{event}/refunds/{refund}/retry` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:dashboard-write |
| GET|HEAD | `/api/event-operations/{event}/staff` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| POST | `/api/event-operations/{event}/staff/invitations` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:10,1 |
| DELETE | `/api/event-operations/{event}/staff/invitations/{invitation}` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| DELETE | `/api/event-operations/{event}/staff/{member}` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| POST | `/api/event-operations/{event}/tickets/{ticket}/resend` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:10,1 |
| POST | `/api/event-staff/invitations/accept` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:10,1 |
| GET|HEAD | `/api/events` | api |
| POST | `/api/events` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/events/{event}` | api |
| PUT | `/api/events/{event}` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| DELETE | `/api/events/{event}` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/events/{event}/check-liked` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/events/{event}/registration` | api |
| POST | `/api/events/{event}/registrations` | api, Illuminate\Routing\Middleware\ThrottleRequests:10,1 |
| POST | `/api/events/{event}/toggle-like` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| POST | `/api/events/{event}/track-view` | api, Illuminate\Routing\Middleware\ThrottleRequests:event-view |
| POST | `/api/forgot-password` | api, Illuminate\Routing\Middleware\ThrottleRequests:5,1 |
| POST | `/api/login` | api |
| POST | `/api/logout` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| POST | `/api/payments/paystack/webhook` | api, Illuminate\Routing\Middleware\ThrottleRequests:paystack-webhook |
| GET|HEAD | `/api/recommendations` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| POST | `/api/recommendations/clear-cache` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| POST | `/api/register` | api |
| POST | `/api/reset-password` | api, Illuminate\Routing\Middleware\ThrottleRequests:10,1 |
| GET|HEAD | `/api/sanctum/csrf-cookie` | api |
| GET|HEAD | `/api/seller/banks` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:5,1 |
| GET|HEAD | `/api/seller/payout-accounts` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:5,1 |
| POST | `/api/seller/payout-accounts` | api, Illuminate\Auth\Middleware\Authenticate:sanctum, Illuminate\Routing\Middleware\ThrottleRequests:5,1 |
| GET|HEAD | `/api/subcategories` | api |
| GET|HEAD | `/api/user` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
| GET|HEAD | `/api/user/liked-events` | api, Illuminate\Auth\Middleware\Authenticate:sanctum |
