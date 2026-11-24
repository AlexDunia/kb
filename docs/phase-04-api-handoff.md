# Phase 4 - registration, attendees and online admission

## Scope and existing infrastructure

This phase preserves existing paid orders, item price/name/admission snapshots, Paystack attempts and issued QR credentials. Login, Google authentication, authentication configuration and existing authentication changes are untouched. Event staff authorization uses the existing authenticated user; public organiser/crew/contact data grants no access.

Recurrence remains schedule metadata without occurrence identity/capacity. New recurring paid checkout and free registration are rejected. Pending recurring charges go to payment review. Existing recurring credentials are preserved but cannot be scanned until occurrence entitlements are explicitly resolved. No implicit single-occurrence or series entitlement is invented.

## Migration and data model

`2026_11_24_000003_add_registration_and_admission.php` adds attendees, event_entrances, event_staff, event_staff_invitations, admission_requests and admission_movements. Adds order_items.registration_schema, tickets.unit_index, ticket_types.is_registration and events.allow_reentry. Existing tickets are backfilled into unnamed entitlement seats in chunks without changing tokens, admission counts, historical prices or buyer identities. Deploy the migration with checkout/door writes paused during the historical backfill; preserve backups and never reset an existing DB.

An order buyer is distinct from attendees. Each order-item unit has people_per_unit entitlement seats. One table unit still issues one QR, linked to several attendee seats. The item's immutable registration_schema snapshots question IDs, labels, required flags and types at purchase. Answers belong to seats. Schema changes affect new orders only. Existing unnamed historical seats remain unnamed until an authorized organizer updates them; buyers are never silently assigned as attendees.

Configured enabled/required attendee questions are string fields from the existing creation wizard; email gets email validation. Required fields are validated for every admission seat. Unknown answers, duplicate slots, foreign ticket IDs and out-of-range unit/seat indices are rejected. A checkout registers at most500 seats. Events with an empty historical form may create unnamed seats rather than inventing identities.

Free registration uses existing Order/OrderItem/Ticket records with status registered, zero money and no Payment, fake paid_at or Paystack transaction. A hidden registration ticket product provides the existing FK/inventory relationship. Event and product locking serialize free capacity; editing cannot reduce capacity below registrations or switch mode over commitments. registered and paid are fulfilled states for protected ticket access. Existing paid checkout status stays paid.

## Public booking APIs

GET /api/events/{event}/registration returns `{data:{event_id,fields,capacity,available,booking_supported}}` for active events. Availability is advisory; registration rechecks under locks. Public event payloads also add registration_fields and booking_supported.

POST /api/events/{event}/registrations accepts checkout_token UUID, quantity1..20, customer{first_name,last_name,email,phone?}, attendees[{unit_index,seat_index:0,answers:{questionId:value}}]. One individual QR is issued per unit. Returns201 `{data:order}` using the existing protected-order representation. Repeated identical tokens return the same registration; changed token payloads422. Remember X-Checkout-Token before navigating to /payment-success/{public_id}. The existing history, recovery and queued delivery flows include registrations.

Paid POST /api/checkout/initialize adds optional attendees[{ticket_type_id,unit_index,seat_index,answers}]. Indices are zero-based, unit_index < ordered quantity, seat_index < people_per_unit. Required configured answers make the corresponding attendees mandatory. Quotes add registration_schemas keyed by event ID; order items retain registration_schema and authorized issued ticket payloads include linked attendees. Reusing the checkout token with different attendee information is rejected; use the saved attempt while a payment is unresolved.

## Event operations API

All /api/event-operations/{event} routes require the existing Sanctum authentication. Existing auth flows are unchanged. Owner has all event operations. Explicit active door membership grants minimal admission access; manager membership additionally grants attendee/purchase management. Optional entrance_id scopes a staff member's admission rights to that entrance. Owner alone creates entrances, changes re-entry policy, invites/revokes staff or lists invitations. Names and emails on public profiles are never checked for permissions.

- GET /admission returns `{data:{event:{id,title,date,ends_at,event_timezone,allow_reentry},role,entrances}}`, restricted to assigned entrances for scoped staff.
- POST /admission/lookup `{token,entrance_id}` hashes the raw token and scopes it to the event. Returns only ticket_public_id,ticket_name,admit_count,inside_count,remaining_first_entries. No token echo, buyer details or attendee answers.
- POST /admission/movements `{token,entrance_id,direction:in|out,count:1..500,request_key:UUID}` returns `{data:{outcome:recorded|denied,reason,count,direction,request_key,ticket_public_id,ticket_name,admit_count,inside_count,remaining_first_entries}}`. A denied operation has count0. The same key/payload returns the original result without another movement; changing the operation behind a key409.
- GET /admission/history?page=&per_page= (max100,default30), optional ticket_public_id. Standard Laravel paginator, event/entrance-scoped, with entrance ID/name, ticket public ID, staff user ID, direction,count,occurred_at. No full attendee data.
- GET/POST /entrances and PUT /entrances/{id}: owner only. Full `{name,active,opens_at?,closes_at?}`; timestamps with offsets are converted to UTC and returned in ISO8601. Admission history timestamps use the same UTC ISO format. At most50 entrances. Disable rather than delete historical entrances.
- PUT /admission/settings `{allow_reentry:boolean}`: owner only. Defaults false.
- GET /staff: owner only; data contains memberships plus staff name/email (owner-only), meta contains current_page,last_page,total; page/per_page are validated (default30,max100). Includes latest100 invitations, with credential fields excluded.
- POST /staff/invitations `{email,role:door|manager,entrance_id?}` returns201 queued invitation ID, not its secret. Email carries a single-use seven-day token in FRONTEND_URL/event-invitations/accept#token=....
- DELETE /staff/{member} revokes membership and pending invitations for that member's email. DELETE /staff/invitations/{id} revokes an unaccepted invitation.
- POST /api/event-staff/invitations/accept `{token}` requires authenticated account matching the invited email and possession of the emailed token. Single use; invalid/expired/revoked/wrong account403. Returns event_id and role; never changes authentication/account credentials.
- GET /attendees?page=&per_page=&q= and /purchases?page=&per_page=&q=: owner/manager only; default30,max100. Attendee search prefixes name/email using event-prefixed indexes; purchase search prefixes buyer email/order public ID. All records are event-scoped, including multi-event orders. Each purchase page contains event-scoped order-item lines; combine lines only within that page and do not claim a partial page is an entire order.
- PATCH /attendees/{id} `{version,answers}` merges permitted answers against the historical schema; stale version409. Admission counts, ticket credentials, buyer/order prices and admission state cannot be edited here.
- POST /tickets/{ticketPublicId}/resend: owner/manager, only fulfilled valid credentials. Queues existing secure buyer delivery, deduplicated per ticket per ten-minute interval. Sends to the original buyer, not an arbitrary new recipient.
- GET /attendees/export: owner/manager, same q filter; streamed CSV in batches of200, spreadsheet-formula-safe, no QR credentials. Do not cache exports.

## Admission rules and locking

Entry requires a valid credential on a paid/registered order, active one-time event and active authorized entrance. Default entry window is event start minus2 hours through event end; owners can supply explicit entrance windows. Exit requires seats currently inside and remains available after closing. Counts must fit eligible seats; all-or-nothing per request. Partial table entry/exit is supported. Each seat can enter once unless event.allow_reentry is explicitly enabled. Exits never grant a new initial entitlement.

Staff membership and entrance reads are shared-locked while processing; revocation/deactivation waits for already-running operations. A unique request row and the ticket row serialize movements; attendee seats are changed in the same transaction. Movement history records UTC time, entrance, staff actor, affected seat IDs and direction/count. Rejected operations retain their idempotent denial result. Lookup is advisory; movement revalidates authorization and entitlement online.

Network loss: no offline admission is recorded or promised. Keep the same request UUID and payload when a response is uncertain, retry online and display the saved result. Do not create a new request key until the prior result is known. Do not queue independent offline device scans, invent arrival counts or use browser data as admission authority.

## Queues and deployment

Existing commerce:dispatch handles purchase/registration/resend delivery. New admission:dispatch-invitations runs every minute, dispatching bounded durable invitation jobs. Use a durable production queue, supervised workers, minute scheduler, real mail transport and correct FRONTEND_URL. Invitation transport failures retain due DB records for retry. No live provider charges or real test emails are needed. Email transport remains at least once; duplicate delivery never grants duplicate seats.

## Verification and frontend integration

Backend lifecycle tests cover partial table admission, idempotency conflicts, explicit re-entry, staff scope/privacy/revocation, closed/refunded/recurring rejection, free capacity/idempotency, configured required answers, schema history, update conflicts, invite ownership, export protection and event ownership. Executed checks and their results are recorded below.

Vue integration must preserve existing design: collect one attendee form per admission seat in paid checkout; offer free registration without Paystack; show registered orders as fulfilled; use the operations APIs for paginated attendee/purchase listings, edits, resend/export, entrance/staff administration and online door operations. Staff links must use a separate /door/{eventId} page with minimal event data, not owner-only dashboard analytics. Invitation tokens belong in fragments/temporary session state, never URLs sent to the server or analytics. No login/authentication changes are authorized.

## Frontend ownership and next steps

The user confirmed that another session owns the Vue integration. No files in C:/Users/HP/kaka were edited for this backend implementation. That session should use this handoff and complete its own build, lint and end-to-end UI verification. In particular: use GET /events/{event}/registration for live remaining free places, implement an authenticated staff door page without fetching owner-only analytics, provide invitation acceptance, and expose revocation/re-entry controls only to owners. Saving a public contact is not a permission grant; invitations require explicit acceptance by the matching account. Keep an uncertain movement request's UUID/payload unchanged across network recovery.

## Verified limitations

- Recurring booking and admission are blocked pending occurrence identity, capacity and entitlement design. Existing series credentials are preserved, not reinterpreted.
- Online admission only. No offline admission success or multi-device offline guarantee exists.
- Door admission allocates eligible entitlement seats in seat-index order for group-count operations; it does not verify a person's physical identity.
- Existing unnamed historical registrations have an empty question snapshot. Authorized edits can add name/email without imposing a later event form.
- Scheduler, supervised durable queues, mail transport and correct FRONTEND_URL are required for invitation and purchase delivery. Production deployment should pause writes while the historical backfill runs.
- A legacy AuthenticationIntegrationTest expects user.role='user' in the current login response. The existing restored auth response omits that field. The full-suite stop-on-first-failure run reports that mismatch; it was not changed. AuthenticationRollbackTest and existing session/password recovery tests pass. No auth implementation or configuration files were changed.

## Backend file inventory

Added: app/Models/Attendee.php; app/Services/Commerce/RegistrationService.php; app/Services/Commerce/FreeRegistrationService.php; app/Services/AdmissionService.php; app/Services/EventAccessService.php; app/Support/UtcDates.php; app/Http/Controllers/Api/AdmissionController.php; app/Http/Controllers/Api/EventOperationsController.php; app/Http/Controllers/Api/EventStaffController.php; app/Jobs/DeliverStaffInvitation.php; database/migrations/2026_11_24_000003_add_registration_and_admission.php; tests/Feature/RegistrationAdmissionTest.php; this handoff.

Extended: app/Models/Event.php, Order.php, OrderItem.php, Ticket.php; app/Services/Commerce/CheckoutService.php, InventoryService.php, PaymentFinalizer.php; app/Services/CreateEventService.php, EventEditorValidation.php; app/Services/Dashboard/DashboardAnalyticsService.php; app/Http/Controllers/Api/BuyerOrderController.php; app/Http/Requests/InitializeCheckoutRequest.php; app/Http/Resources/PublicEventResource.php; routes/api.php; routes/console.php; tests/Feature/EventConcurrencyTest.php; tests/Support/event-editor-worker.php; scripts/test-isolated-database.php (supports explicit test paths/flags).

## Local migration verification

The forward migration is applied to the local development database. Record counts across events,ticket_types,orders,order_items,payments,tickets,order_allocations,purchases,users were unchanged. Hash comparisons verified that existing ticket credential/entitlement fields and order-item historical snapshots were unchanged. This local DB had no existing issued-ticket seats to backfill; the dedicated isolated migration test additionally seeds two historical table QRs and verifies eight unnamed seats, stable credentials, stable prices and distinct unit indices. No real charges or outbound test emails were created.

Legacy purchases without issued Ticket records remain inventory commitments and are not automatically converted into invented attendees or admission credentials.

Use data.role from GET /admission (owner,manager,door) for event-operation presentation, not a global user.role field. Backend authorization is enforced independently on every operation.

## Executed verification

- Broad isolated MariaDB10.4.32 regression run:97 passed,790 assertions. Included API security, session/password recovery, AuthenticationRollbackTest, paid commerce, event creation/editing/public profiles, dashboard analytics, event concurrency, registration/admission and Money tests. AuthenticationIntegrationTest was excluded from that passing run after the separate full-suite attempt exposed the preserved auth response mismatch described above.
- Core regression command: `php scripts/test-isolated-database.php tests/Feature/RegistrationAdmissionTest.php tests/Feature/CommercePaymentLifecycleTest.php tests/Feature/EventConcurrencyTest.php` passed49 tests,313 assertions before the later timestamp/backfill assertions were added. Includes all eight separate-process concurrency scenarios, including competing partial scans and competing last-place free registrations.
- Final lifecycle command: `php scripts/test-isolated-database.php tests/Feature/RegistrationAdmissionTest.php` passed14 tests,116 assertions, including UTC round trips, query plans and isolated historical migration verification.
- Final owner-only staff identity/privacy check: `php scripts/test-isolated-database.php tests/Feature/RegistrationAdmissionTest.php --filter=invitation_requires_secret` passed1 test,13 assertions after enriching the owner staff roster with names/emails. Door staff cannot access that roster.
- SQLite lifecycle run passed12 tests,93 assertions with the MariaDB query-plan test skipped at that point. Production locking claims come from MariaDB, not SQLite.
- Pint --test passed all30 changed PHP files. All30 passed PHP syntax checks; git diff --check passed. Route listing confirms17 event-operations routes, plus public registration form/create and authenticated invitation acceptance. Schedule listing confirms both commerce:dispatch and admission:dispatch-invitations every minute.
- Separate whole-suite stop-on-first-failure attempt:17 passed,1 failed,87 pending,220 assertions. The failure is the existing user.role expectation in AuthenticationIntegrationTest; the auth implementation was left untouched. This is not a claim that the entire legacy suite is green.
- SHA256 comparisons verified that AuthController.php, AppServiceProvider.php, config/cors.php, config/session.php and bootstrap/app.php are unchanged from the start of this phase. Existing auth routes were not modified.

Backend is ready for the frontend session's integration and online test-mode end-to-end checks. Frontend build/lint/browser checks belong to that session and were not claimed here. No future phase was started.
