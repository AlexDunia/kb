# Phase 2 backend API handoff

This phase implements the Laravel section only. Sanctum cookie authentication, CSRF requirements, ownership policies and retired legacy mutations remain as documented in `phase-01-api-handoff.md`. No Vue files or visual design were changed.

## Deployment and migration

Apply `database/migrations/2026_11_24_000001_strengthen_event_editor_integrity.php` before serving the updated editor code. It adds `events.lock_version` (existing records start at 1), `ticket_types.retired_at`, widens `events.main_image` to 2000 characters, adds discovery indexes, and backfills a new indexed `event_addresses.city_search` value from the existing city. It does not delete application records or rewrite existing event timestamps, financial snapshots or ticket entitlements.

Deployment command: `php artisan migrate --path=database/migrations/2026_11_24_000001_strengthen_event_editor_integrity.php`. Earlier migrations, including the existing public-profile table, must already be applied. Use the normal configuration/route cache deployment process afterward. Runtime database and application timezones must remain UTC; set the actual event IANA timezone in `timeZone`.

## Editor endpoints and concurrency

The endpoints remain:

- `POST /api/create-event` ? create an incomplete draft, HTTP 201.
- `GET /api/create-event/{id}` ? owner-only editor data.
- `PATCH /api/create-event/{id}` ? owner-only partial changes.
- `POST /api/create-event/{id}/publish` ? owner-only publication of the saved draft.

All responses above include `data: {id, status, title, version, form}`; mutation responses also retain `message`. `form` is the complete persisted editor representation, including server-assigned ticket IDs, local/UTC times, `city`, `publicProfile` and `version`. Refresh the client state from this response after every successful save rather than keeping temporary ticket IDs.

**Every PATCH and publish request must send the last received integer `version`.** Missing revisions return HTTP 428 with `message: "An event version is required. Reload the editor first."`; stale revisions return HTTP 409 with `message: "This event changed. Reload it before saving again."`. Neither writes data. Every successful event mutation increments the revision, including publication and profile changes. A second publish with the current revision returns 422; a stale publish returns 409.

```json
{"version":4,"title":"Updated event name"}
```

Publish sends only the saved draft's revision, for example `{"version":5}`. A client must save unsaved form changes before publication. Preserve unsaved user input on 409, reload the event, and ask the user to reconcile changes; do not automatically replay a stale full form.

Writes lock the event row, then all its ticket rows in ascending ID order, inside a transaction. Checkout and payment finalization lock those same ticket rows before inventory decisions. Consistent inventory reads happen after these locks; this also avoids stale repeatable-read snapshots. Failed-payment retries recheck inventory before restoring a reservation. Database transaction retries retain the same expected event revision. See [Laravel transaction semantics](https://laravel.com/framework/docs/12.x/database#database-transactions).

## Ticket collection semantics

`PATCH` treats `tickets` as a **merge by persisted ID by default**. Omitted products and omitted fields on supplied existing products are preserved. Omit `tickets` entirely for event-details/profile/media-only changes. A new product omits `id` (or uses null); a persisted product must keep its server ID. Unknown, foreign, retired and duplicate IDs produce HTTP 422 field errors. Never send the wizard's temporary UI counter as a database ID.

For the full four-step wizard's intended replacement/removal operation, explicitly send `ticketCollectionMode: "replace"`, the current event revision and the complete intended collection:

```json
{
  "version":6,
  "ticketCollectionMode":"replace",
  "tickets":[
    {"id":15,"name":"General","unitType":"individual","price":"5000.00","units":100,"peoplePerUnit":1,"visible":true},
    {"name":"Gold table","unitType":"table","price":"50000.00","units":10,"peoplePerUnit":4,"visible":true}
  ]
}
```

Omitted products are retired (`retired_at` set, visibility false), never deleted. Their order items, payments, issued tickets and legacy purchases remain intact. Retired products are excluded from editable `form.tickets`, public tickets and new checkout, but remain in owner dashboard analytics and database history. There is no restore/delete product endpoint in this phase. To hide a product while keeping it editable, set `visible: false` instead. An empty merge array changes nothing; an empty replacement array retires every product on a draft. Published paid events must retain at least one configured product, which may be hidden or have zero stock.

Owner `form.tickets[*].inventory` is read-only: `configured_capacity_units`, `sold_units`, `reserved_units`, `available_units`, `admission_capacity`. `units` remains configured product capacity, not remaining stock. One table is one product unit; its admission capacity is units multiplied by `peoplePerUnit`. Hidden products have no saleable available units. The dashboard retains its existing configured/effective capacity distinction; hidden/retired effective capacity includes sold and reserved commitments, with zero new available units.

Capacity cannot be reduced below paid units plus valid reservations. Current unexpired pending orders reserve capacity; expired, cancelled, failed, review and refunded orders do not. Completed legacy `purchases` also consume units; legacy pending records have no expiry and are conservatively reserved until explicitly resolved. Historical purchases without a ticket product ID cannot be assigned to a product automatically. Modern revenue/timeline analytics continue to use order snapshots, while inventory includes attributable legacy commitments.

Any commercial order-item or legacy-purchase history freezes `unitType` and `peoplePerUnit`, even if an order later expires or fails. Product name/current price changes affect future purchases only. Historical line prices, names and admission counts are never rewritten. Switching paid/free mode is rejected while sold/reserved commitments exist. A delayed successful payment whose reservation has expired is rechecked against current stock; an unfulfillable success goes to payment review and issues no tickets.

## Validation and dates

Drafts may omit publication fields, but supplied values must be valid. Publication and active editing use the same validator. Field errors are now consistently arrays under `errors` with HTTP 422, including publication failures.

| Field | Implemented contract |
| --- | --- |
| `title`, `organiser`, ticket `name` | Maximum 100, 100, 50 characters respectively, matching the existing columns. |
| `description`, `venue`, `city` | Maximum 20000, 255, 255 characters. TEXT-backed fields also enforce the database's 65535-byte UTF-8 limit. `city` is explicit; the backend does not guess a city from a venue name. |
| `category` | Existing category name, case-insensitive; no implicit category creation. Required to publish. |
| `timeZone` | Valid IANA timezone, maximum 60 characters; default `Africa/Lagos`. |
| `meetingLink`, `organiserWebsite` | HTTP(S) URLs, maximum 1000/500 characters. |
| `format` | `in-person`, `online`, `hybrid`. Publication requires a venue for in-person/hybrid, a meeting URL for online/hybrid. |
| `ticketMode` | `free` or `paid`. Paid publication requires named products with positive prices. Zero-price paid drafts may be saved, but cannot be published. Free events do not gain a new registration/checkout capability. |
| ticket `price` | Nonnegative decimal, at most 2 decimal places, maximum 999999.99. Prefer decimal strings. |
| `units`, `freeCapacity`, `peoplePerUnit` | Integers: 0?1000000 units/free capacity, 1?10000 people per unit. Individual products always admit one person. Zero capacity is valid and means no stock, not unlimited stock. Aggregate event admission capacity cannot exceed 2147483647. |
| `maxPerPerson`, ticket `color` | Nullable positive integer up to 1000000; color is a six-digit hex value such as `#ec4899`. |
| Collections | Maximum 100 ticket products, 20 secondary images, 30 attendee fields and 8 known public extra-detail types. |

Event start/end and ticket sales start/end support local values named `startsAtLocal`, `endsAtLocal`, `salesStartLocal`, `salesEndLocal`, formatted exactly `YYYY-MM-DDTHH:mm:ss`. ISO alternatives are `startsAt`, `endsAt`, `salesStart`, `salesEnd` and must carry `Z` or an explicit offset. Instants are stored in UTC; `event_timezone` retains the event-local timezone. Reopening returns both local values and UTC ISO values. Publication requires start and end, and end must follow start. Sales end must follow sales start where both exist and cannot exceed the event end. Past instants are not globally forbidden: existing past events remain editable.

When supplying local and ISO representations together, they must describe the same instant in the event timezone. Send only the representation the user changed, or recompute both correctly. Nonexistent daylight-saving times are rejected. An explicit matching ISO offset disambiguates repeated fall-back local times. A timezone-only edit preserves existing instants and changes their local representation, including ticket sale windows.

Recurring rules are validated (frequency, selected weekday/monthly rule and series end). They remain metadata. There is **no per-occurrence ticket inventory or occurrence-specific availability endpoint**. Do not fabricate future recurring occurrence stock; the existing event-wide stock and event time window are the implemented commerce boundary.

Existing stored timestamps are preserved: older records may have been written with inconsistent timezone semantics. There is insufficient provenance to blanket-shift those historical instants safely; audit/correct affected records explicitly rather than running an automatic timezone conversion.

## Media, profile content and public responses

`coverImage` and `secondaryImages` accept HTTP(S) URLs or base64 JPEG, PNG, GIF and WebP data URLs. Uploaded bytes must actually match the declared image type, be at most 5 MiB, and at most 40 million pixels. SVG, executable text disguised as an image, invalid base64 and arbitrary relative paths are rejected. External URLs are stored without server-side fetching. A maximum of 20 secondary images is accepted. Stored event image URLs support 2000 characters.

Uploads use content hashes inside an event-owned directory, so duplicate cover/gallery bytes are processed/stored once. Persisted upload URLs retain `source_type: upload`, even when absolute HTTP URLs. Media row IDs are preserved when URLs are unchanged; duplicate metadata and cover/gallery overlap are normalized. Superseded managed assets are considered for cleanup only after a successful database commit, under the event lock, and retained while referenced by event fields, media or profiles. New files from a rolled-back operation are removed. Cleanup failures are reported server-side without pretending a committed event save failed. Unowned legacy assets are retained rather than deleted speculatively.

Use the same canonical PATCH endpoint with optional `publicProfile` to author the existing profile, under the same ownership/version protection. Top-level profile fields merge; supplied profile arrays replace that specific array. Omitted fields remain. Set individual text fields to null or an array to `[]` to clear them; `publicProfile: null` does not delete the profile. Read back `data.form.publicProfile` after saving.

Supported fields are `summary`, `caption_title`, `caption_description`, `organiser_bio`, `organiser_image`, `guests`, `schedule`, `moments`, `faqs`, `crew`, `related_event_ids`. Nested shapes:

- guests: `{id,name,role,bio,image}`; crew: `{name,role,image}`.
- schedule: `{time,title,description,label}`; moments: `{url,caption}`; FAQs: `{question,answer}`.
- related IDs: distinct existing event IDs, maximum 20; public retrieval still excludes non-active targets.

Profile image fields accept HTTP(S) URLs or existing public storage image URLs, not new data URLs. Managed uploads must belong to this event. Profile arrays are bounded to 100 entries. Unknown nested attributes are rejected. Existing FAQ relations are preserved; the existing public frontend adapter can prefer `public_profile.faqs` and fall back to legacy FAQs/extra details. No new content store was introduced.

Phase 1 public privacy protections remain. Detail ticket responses additionally expose `configured_capacity_units`, `sold_units`, `reserved_units`, `available_quantity`, `admission_capacity`; `quantity` is still configured capacity. Only visible, non-retired tickets are returned. Use checkout quote/initialization for authoritative current sale eligibility and price. Checkout also rejects ended events and retired products. `ticket_mode` distinguishes free events from paid events with no visible stock; a fallback event `price` of zero is not a free-admission promise.

## Discovery and frontend changes required

`GET /api/events` now implements server-side `q`, `city`, `category_id`, `category`, `subcategory_id`, date filters, featured, validated sort and pagination. `q` accepts at most 200 characters/20 words, matches all words across title/description/tags/organizer/category/venue/city, and treats `%`/`_` as literal characters. `city` is a case-insensitive exact city match. Prefer `category_id`; `category` is an exact category name. An unknown numeric category ID returns 422. Defaults remain 10 per page, maximum 100, with `{data,meta,links}` and deterministic tie ordering.

Discovery indexes cover active/category/date/ID, active/date/ID, active/created_at/ID, and normalized address city. EXPLAIN regression checks verify category/date and city index use on SQLite and MariaDB. Contains-text search still requires database scanning within the filtered set; no unmeasured full-text/search infrastructure was added.

Required Vue integration, in order:

1. In `createEventService.js`, preserve response `data.version` and `data.form`; include version in PATCH/publish. Replace temporary ticket IDs with returned persisted IDs after the initial save; omit IDs for genuinely new rows.
2. Preserve the four-step wizard and styling. Send `ticketCollectionMode: 'replace'` only for its deliberate complete ticket collection saves. Workspace ticket changes can send one persisted product with default merge semantics. Details-only PATCHes omit ticket/media arrays.
3. Display 422 field errors as arrays. For 409 keep unsaved inputs and reload/reconcile; for 428 reload the editor. Never blindly retry a stale full collection. Refresh editor/event/ticket/dashboard caches only after successful writes, using the returned state.
4. Respect the title/organizer/ticket-name limits, positive paid-publication prices and zero-capacity meaning. Show configured/sold/reserved/available quantities separately. Freeze admission semantics when commercial history exists; the backend remains authoritative.
5. Align `eventDateTime.js` and form payloads with event-local times and explicit-offset instants. Do not derive occurrence stock from `recurrenceSchedule.js`. Use saved IDs and current backend availability in EventDetails/EventTickets/EventTicketSelection.
6. In `publicCatalog.js`, send `q`, `city`, `category_id`, `page`, `per_page` to the backend and consume its `meta` totals. Remove the loop fetching the entire catalog for browser filtering. `FindEvents.vue` should paginate the returned matching results.
7. Author public profile fields through versioned canonical PATCHes where the existing interface needs them. Never invent a separate profile endpoint or persist authoritative content in localStorage.

No frontend checks or browser create/edit/publish verification are claimed in this backend-only phase. The unchanged Vue client requires the revision/ID integration above before end-to-end editing can pass.

## Verification and file manifest

The named Phase 2 migration was applied to the local development database (batch 12); record counts stayed unchanged across nine event/commerce tables. Local-database public listing and detail reads both returned HTTP 200 after migration. Actual MariaDB EXPLAIN selected `events_discovery_category_date` and `event_addresses_city_search` for their respective queries. Provider calls remain mocked; concurrency verification uses independent PHP processes and real database row locks, not SQLite lock emulation. The existing safe isolated-database runner creates/removes only a randomly named test database. Concurrency tests additionally check that exact test-name prefix before refreshing their fixture schema.

Executed verification:

| Command / check | Actual result |
| --- | --- |
| `php vendor/pestphp/pest/bin/pest` | SQLite full run: 72 passed, 3 intentionally skipped row-lock tests. |
| `php vendor/pestphp/pest/bin/pest --filter=EventLifecycleIntegrityTest` | Final SQLite lifecycle checks: 18 passed, 163 assertions. |
| `php scripts/test-isolated-database.php` | Final MariaDB 10.4.32 run: 75 passed, 658 assertions, including all three independent-process concurrency tests. Disposable database removed. |
| `php vendor/bin/pint --test` with the Phase 2 PHP manifest | 21 files passed. |
| `php -l` with the Phase 2 PHP manifest | 21 files passed. |
| `git diff --check` | Passed. |
| Named migration `--pretend`, then forward application locally | Passed; recorded in batch 12; nine event/commerce table record counts unchanged. |
| Local database public listing and detail kernel requests | Both HTTP 200; protected configuration omitted. |
| MariaDB `EXPLAIN` review | Chosen indexes: `events_discovery_category_date` and `event_addresses_city_search`. |

Backend implementation and verification are complete. It is ready for Vue integration and subsequent end-to-end testing after the required revision/ID/filter changes above. No Phase 3 work was started.

Known historical tooling limitation: an older commerce migration's `down()` attempts to drop a MariaDB index needed by a foreign key. This was discovered in isolated test teardown; the old migration was not rewritten. Forward application of the new migration is tested. Concurrency tests reset only the confirmed disposable database and do not invoke the defective legacy rollback path.

Phase 2 modified files: `app/Http/Controllers/Api/CreateEventController.php`, `EventController.php`; `app/Http/Resources/PublicEventResource.php`; `app/Models/Event.php`, `TicketType.php`, `EventMedia.php`; `app/Services/CreateEventService.php`; `app/Services/Commerce/InventoryService.php`, `CheckoutService.php`, `PaymentFinalizer.php`; `app/Services/Dashboard/DashboardAnalyticsService.php`; existing feature tests `CreateEventTest.php`, `EventPublicProfileTest.php`, `ApiSecurityTest.php`.

Phase 2 added files: `app/Services/EventEditorValidation.php`, `EventMediaService.php`; `database/migrations/2026_11_24_000001_strengthen_event_editor_integrity.php`; `tests/Feature/EventLifecycleIntegrityTest.php`, `EventConcurrencyTest.php`; `tests/Support/EventLifecycleFixtures.php`, `event-editor-worker.php`; this handoff. Earlier uncommitted Phase 1/profile/commerce work was preserved.
