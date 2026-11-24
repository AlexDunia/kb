# Phase 1A API handoff

## Authentication and browser configuration

Authentication remains Sanctum **session cookies**, using Laravel's `web` guard. There are no issued bearer tokens or token-management endpoints. Dashboard tests now use `actingAs($user)` on the session guard rather than Sanctum's token-oriented test helper. This follows [Laravel's SPA authentication contract](https://laravel.com/framework/docs/12.x/sanctum#spa-authentication).

1. Call `GET /sanctum/csrf-cookie` before login, registration, or authenticated mutations. The existing `/api/sanctum/csrf-cookie` alias remains supported for stateful frontend requests.
2. Send credentials on requests and send the decoded `XSRF-TOKEN` cookie as `X-XSRF-TOKEN`. The existing `src/api/axios.js` has `withCredentials: true` and `withXSRFToken: true`.
3. Send `Accept: application/json` and a first-party `Origin` or `Referer`. Use the same hostname consistently; do not mix `localhost` and `127.0.0.1` during one session.
4. Session expiry/CSRF failure is HTTP 419. Refresh CSRF protection and request login when authentication has expired. Do not automatically replay payment or other mutations without their existing retry safeguards.

`POST /api/login` returns `{ "user": USER, "message": "Login successful" }`; registration returns the same envelope with HTTP 201 and its registration message. `GET /api/user` returns USER directly:

```json
{"id":1,"name":"Ada","email":"ada@example.com","avatar":null,"email_verified_at":null,"role":"user"}
```

Only those six user fields are serialized; password, remember token and Google subject are private. `role` is consistently `user`: there is no active global administrator/agent role system. Spatie's installed dependency, configuration and historical permission tables remain intact but are not activated. No frontend-supplied role grants access. Event edit, publish and dashboard access use the same ownership policy based on `events.created_by`; there is no team access or administrator bypass.

Google still uses `GET /api/auth/google`, the existing callback, and `/auth/google/success?user=...` on the frontend. Socialite now validates OAuth session state; callbacks started before deployment must restart login. TLS verification stays enabled, including locally: configure PHP/Guzzle's trusted CA bundle if needed. Only provider-verified email identities may create/link accounts. An established Google subject takes precedence over email matching; a different already-linked subject is rejected. Existing local IDs, names, email addresses and passwords are preserved. Google profile fields are assigned server-side with `forceFill`, rather than exposing identity/verification fields to general mass assignment. The callback JSON uses USER above; treat `/api/user` as the authenticated source of truth, not query-string data. Failures redirect with a generic message and log details server-side.

## Canonical event endpoints and legacy migration

| Method and path | Contract |
| --- | --- |
| `POST /api/create-event` | Authenticated draft creation; existing camelCase editor payload; HTTP 201, `data: {id,status,title}`, `message`. |
| `GET /api/create-event/{id}` | Owner only; `data: {id,status,form}`. Includes private editing configuration. |
| `PATCH /api/create-event/{id}` | Owner only; existing partial editor updates and summary response. |
| `POST /api/create-event/{id}/publish` | Owner only; existing publication validation and summary response. |
| `POST /api/events` | Retired: authenticated HTTP 410 with `message` and `editor_endpoint: "/api/create-event"`. |
| `PUT /api/events/{event}` | Retired: authenticated HTTP 410. Migrate to the canonical PATCH and its editor payload. |
| `DELETE /api/events/{event}` | Retired: authenticated HTTP 410. No replacement deletion endpoint is introduced. |

The active `src/services/createEventService.js` and workspace updates already use the canonical editor. The legacy `eventService.js` writers target `/events.php`, not these Laravel routes; store wrappers and `purchaseService.js` still reference those legacy methods. No consumer of the retired Laravel writers was found in the supplied frontend/backend repositories. Do not retarget the old payloads blindly: use the existing editor payload builder. External consumers outside these repositories cannot be confirmed and must follow the 410 migration notice. Public listing/detail routes are retained.

Routing is now registered once through `bootstrap/app.php`. The old custom route provider is unregistered and retains only its legacy `HOME` constant. Laravel 12's configured middleware is authoritative; the old `app/Http/Kernel.php` is not bound to the running HTTP kernel and is not changed. Purchase models, purchase records, permission migrations and commerce inventory/history safeguards remain in place.

## Public events

Only `status: "active"` is public. `draft`, `cancelled` and `completed` are absent from listing, favourites and related results; their public detail/like endpoints return 404, including for owners. Owners access draft configuration through the editor instead. Tracking retains its existing harmless no-op for non-active events. Recommendation queries already restrict results to active events.

`GET /api/events` retains `{data: [...], meta: {...}, links: {...}}`. Existing category/subcategory/date/featured filters and sorting remain. Pagination defaults to 10; `per_page` must be an integer from 1 to 100 and `page` must be positive. Invalid pagination now returns 422.

`GET /api/events/{event}` retains `{data: EVENT}`. Public EVENT fields are explicitly selected:

- Scalars: `id`, `title`, `description`, `category_id`, `date`, `ends_at`, `event_timezone`, `event_type`, `event_format`, `price`, `total_tickets`, `duration`, `featured`, `main_image`, `banner_image`, `banner_url`, `status`, `ticket_mode`, `tags`, `organiser_website`, `created_by`. The numeric owner ID is retained for the current frontend's owner-edit button; no owner profile is exposed.
- Listing relationships: `category: {id,name}`, `organizer: {id,name}`, public venue `address`, and `sub_categories: [{id,name,category_id}]`.
- Detail relationships: visible `ticket_types`, `media: [{id,url,is_cover,sort_order}]`, `faqs: [{id,question,answer}]`, `event_options: [{id,name}]`, `public_profile` (or null), and active `related_events`. Related cards use the same safe serializer with organizer/address loaded. Ticket fields are `id,name,unit_type,color,price,quantity,people_per_unit,max_per_person,description,sales_start_date,sales_end_date,is_featured,visible,available_quantity`. Detail availability uses existing server-side reserved inventory; `quantity` remains total capacity.
- `public_profile` preserves `summary`, `caption_title`, `caption_description`, `organiser_bio`, `organiser_image`, `guests`, `schedule`, `moments`, `faqs`, `crew`. Its raw `related_event_ids` and persistence metadata are private.
- `extra_details` is now an array of `{type,label,value}` for the existing public editor types `parking,dress,bring,agenda,speakers,food,transport,faq`. Arbitrary types/keys are omitted. The current event profile adapter accepts arrays.

`meeting_link`, attendee-field configuration, raw recurrence configuration, organizer email, relation pivot data and unselected persistence/internal attributes are omitted. Public responses cannot deliver online joining credentials; the current frontend already displays “Joining details are shared by the organiser.” No new joining-details capability is added.

`GET /api/user/liked-events` now requires authentication and returns `{data: [...], meta: {current_page,last_page,per_page,total}}`, with a maximum of 100 items per page. Request subsequent `?page=N` values. Ticket relationships exclude hidden tickets; use the detail/quote endpoints for authoritative availability. Like toggling and checks retain `{liked,likes_count,...}` and only accept active events.

Dates and decimal price representations retain the existing model/API formats; use `event_timezone` when interpreting event times. Checkout continues to use existing server-authoritative amounts and monetary minor-unit contracts.

## Errors and rate limits

API exceptions return JSON even without an Accept header. Clients should branch on HTTP status, read `message`, and read optional `errors`. Existing business-state failures remain HTTP 422; they are not silently converted to 409.

| Status | Example response |
| --- | --- |
| 401 | `{"message":"Unauthenticated."}` |
| 403 | `{"message":"You do not have permission to perform this action."}` |
| 404 | `{"message":"Resource not found."}` |
| 405 | `{"message":"Method not allowed."}` |
| 410 | `{"message":"This legacy event mutation endpoint has been retired. Use the event editor endpoints.","editor_endpoint":"/api/create-event"}` |
| 419 | `{"message":"Session expired. Please refresh CSRF protection and try again."}` |
| 422 validation | `{"message":"The title field must be a string.","errors":{"title":["The title field must be a string."]}}` |
| 422 publication state | `{"message":"Event is already published"}` |
| 429 middleware | `{"message":"Too many requests. Please try again later."}` plus `Retry-After` |
| 500 exception | `{"message":"Server error. Please try again later."}` |

Existing editor publication validation can contain string-valued `errors`, while Laravel field validation contains arrays; normalize both when displaying errors. Existing controller-handled business/failure messages remain supported. Unexpected exception details are never returned through API responses, even when local debug is enabled.

The shared generic limit remains 60/minute per authenticated user or guest IP. Dashboard reads now use their intended 120/minute limiter without the conflicting generic 60 cap. Dashboard writes retain 20/minute plus the generic protection. Tracking/webhooks retain their existing independent named limits. Login additionally limits 10/minute per email/IP pair; registration uses 10/minute per IP. Checkout limits and payment retries are unchanged.

## Environment and exact frontend integration

1. Keep Vue's editor/dashboard consumers on the existing canonical routes above. Handle 410 explicitly in legacy consumers; do not offer deletion as successful.
2. Keep `src/api/axios.js` cookie/XSRF options. Replace the hardcoded origin in `src/stores/events.js` when integrating across environments, using the same configured API client for favourites. The standalone legacy Axios calls do not enable cross-origin `withXSRFToken`; use the shared client to preserve CSRF handling. Frontend files were inspected, not modified in this backend phase.
3. Continue consuming the existing public profile fields; stop depending on private meeting/configuration fields. Preserve the current `created_by` owner-button check, but let the backend policy decide access. Follow the favourites pagination metadata. Do not interpret `user.role` as event ownership or promise global admin capabilities.
4. Locally use frontend port 5174 and backend port 8000. Configure the Vite proxy for `/api` and `/sanctum` to the backend, or use credentialed same-site API requests. Public catalog's existing `VITE_API_URL` must include `/api`; no backend URLs were hardcoded by this phase.
5. Set backend `APP_URL`, `FRONTEND_URL`, and `SANCTUM_STATEFUL_DOMAINS` (frontend host and port, no scheme). Set `CORS_ALLOWED_ORIGINS` to comma-separated full frontend origins. Defaults allow the configured `FRONTEND_URL` plus `http://127.0.0.1:5174`. CORS credentials remain enabled; no wildcard origins are required. Use same-site domains in production.
6. Keep the existing session store operational and configure `SESSION_DOMAIN` as appropriate. Production defaults secure cookies on; use HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, and a correct `SESSION_SECURE_COOKIE` value. Set `SESSION_SAME_SITE` for the actual deployment; the default remains `lax`.
7. Preserve existing Google credentials and callback URL. OAuth state requires the redirect/callback to share their session cookie and canonical backend host. Configure a trusted CA bundle locally rather than disabling TLS. Clear/rebuild local/deployment configuration and route caches through the normal deployment process after updating these files.

## Verification and limits

No Phase 1A database migration was added and no existing application records were deleted. The already-uncommitted public-profile model/migration/tests and commerce test edits were preserved; they are not newly claimed Phase 1A work. Existing event creation, checkout, verification, duplicate webhooks, reservations, historical snapshots and attribution regression tests remain part of the suite.

- Baseline: 30 passed, 6 failed (five token-test setup failures plus a sample Pest binding failure).
- SQLite: `php vendor/pestphp/pest/bin/pest` — 54 passed, 464 assertions.
- Configured MySQL driver / MariaDB 10.4.32: `php scripts/test-isolated-database.php` — 54 passed, 464 assertions; the newly created isolated database was removed. The helper creates a random isolated database, runs the suite, then removes only that database. It refuses production/nonlocal environments and cached configuration. Requires create/drop privileges for that isolated database. It never resets the configured development database.
- Formatting: `php vendor/bin/pint --test` against the 21 Phase 1A PHP files — passed. `php -l` — all 21 files passed. `git diff --check` — passed. `php artisan route:list --path=api -vv` — 37 API routes with the intended middleware. The `php artisan test` wrapper failed on this Windows environment's subprocess working-directory check; the direct Pest commands above executed successfully.
- Backend changes are ready for frontend end-to-end testing after applying the integration/configuration requirements. Real browser Google/Paystack callbacks were not exercised; provider calls are mocked in automated tests. Simultaneous multi-process load testing was not performed; retry, reservation and duplicate-payment regressions were executed against both databases. PostgreSQL is not claimed as verified.

Out-of-scope frontend follow-up: `src/services/purchaseService.js` still contains a localStorage purchase implementation and references `/events.php` writers. Its active checkout use was not established; do not use it for financial truth. The tested backend commerce path remains authoritative. Frontend search also still fetches multiple catalog pages for local filtering; moving that search server-side belongs to a separately assigned phase.

## Phase 1A file manifest

Modified: `app/Http/Controllers/Api/AuthController.php`, `CreateEventController.php`, `DashboardController.php`, `EventController.php`; `app/Models/Event.php`; `app/Providers/AppServiceProvider.php`, `AuthServiceProvider.php`, `RouteServiceProvider.php`; `bootstrap/app.php`; `config/app.php`, `cors.php`, `session.php`; `routes/api.php`; `tests/Feature/DashboardAnalyticsTest.php`, `ExampleTest.php`.

Added: `app/Http/Resources/CurrentUserResource.php`, `PublicEventResource.php`; `app/Policies/EventPolicy.php`; `tests/Feature/ApiSecurityTest.php`, `AuthenticationIntegrationTest.php`; `scripts/test-isolated-database.php`; this handoff.

The pre-existing edits to `tests/Feature/CommercePaymentLifecycleTest.php` and pre-existing public-profile artifacts remain outside this Phase 1A manifest.
