# Phase 7 release verification report

24 November 2026. **Release decision: blocked; not production-ready.** This report distinguishes executed automated checks from browser, provider and operational work that remains unverified. No real charges, refunds, bank onboarding or messages to real recipients were made. Authentication code was deliberately preserved at the user's request. Existing data was not reset.

## Scope and changes

Audited local Laravel `C:/Users/HP/kakabackend` (starting HEAD `b595fd4`) and Vue `C:/Users/HP/kaka` (starting HEAD `762091d`). Another session actively changed Vue dependencies, pagination, lint configuration and route splitting during verification. Those changes were preserved; their presence means a frozen final artifact still needs end-to-end sign-off. No frontend files were edited by this backend session.

Implemented here:

- Owner editor summaries now return saved `lifecycle_state` and nullable ISO `booking_cutoff_at`, alongside the existing version/form. This resolves the missing backend read contract identified in the Phase 6 frontend handoff. Existing Vue lifecycle reload already consumes `lifecycle_state`; initial cutoff population still requires frontend wiring.
- Removed unused public `/api/ping` and `/api/cors-test` diagnostics. `/up` remains the health endpoint. No active Vue callers or tests referenced the removed diagnostics.
- Added `tests/Feature/ReleaseReadinessTest.php`: owner-only lifecycle read and bounded discovery measurement/assertions using an isolated relational database.
- Added [the authoritative current API contract](api-spec.md), including an actual 80-route inventory. It supersedes the prospective Vue API spec's incorrect bearer-token guidance. Earlier phase handoffs remain the detailed payload references.
- No migration, financial calculation change, authentication change, visual redesign or architectural rewrite.

Files modified: `app/Http/Controllers/Api/CreateEventController.php`, `routes/api.php`; added the readiness test and these two documentation files.

## Actual verification

| Check | Executed result |
|---|---|
| `php scripts/test-isolated-database.php tests/Feature tests/Unit` | **144 passed, 11 failed; 1176 assertions; 372.20s.** Includes all 13 separate-process MariaDB concurrency cases. This full run began before the new readiness test was added. |
| Readiness test alone | **2 passed, 96 assertions; 22.18s.** |
| Final focused security/restored-auth/readiness run | **11 passed, 328 assertions; 16.93s.** Command: `php scripts/test-isolated-database.php tests/Feature/ApiSecurityTest.php tests/Feature/AuthenticationRollbackTest.php tests/Feature/ReleaseReadinessTest.php --stop-on-failure`. |
| Pint and PHP syntax | Three changed PHP files passed Pint; controller and new test passed `php -l`; diff whitespace check passed. No PHPStan/Larastan configuration exists; Pint is not semantic static analysis. |
| Vue production build | Passed initially (622 modules, Vite6.4.1,21.86s), and after concurrent frontend improvements (630 modules,Vite6.4.6,12.05s). |
| Vue lint | Initial repository lint failed345 errors/3 warnings, largely generated `.vite` files plus source/config errors. A later run improved to2 errors (`events.js` unused catch variable, `authDestination.js` control-character regex); the final rerun confirmed the same2 errors. No automatic lint fix was run by this session. |
| `node scripts/check-phase06-ui.mjs` | Passed mounted Vue financial flows: currency partitions, unknown fees, whole-line IDs/amounts, stable uncertain-refund UUID, retry, lifecycle conflict, bank pending/account isolation/private storage. |
| `node scripts/check-phase05-analytics.mjs` | Passed canonical cache invalidation and server aggregate guest counts. |
| `node scripts/check-phase04-ui.mjs` | Passed paid/free table seat forms, permissions, pagination, uncertain scanner retry, camera cleanup and account isolation. |
| `node scripts/check-event-profile.mjs` | **8 passed**, including canonical product IDs, visibility/stock, timezone/media safety, closure and cart preservation. |
| `php scripts/check-phase06-http.php` in Vue repo | Passed actual Vue services over isolated Laravel HTTP: registration, zero-charge finance, persisted purchase IDs, idempotent queued refund, credential suspension, owner isolation, lifecycle conflict/cancellation and rejected checkout. SQLite/provider fakes; not production-lock evidence. Temporary harness files removed. |
| Relational engine | MariaDB10.4.32, newly generated disposable databases, removed by the test helper. No existing development database reset. |
| Browser inventory | Failed: **browser access host unavailable**. No interactive browser/camera/device or real Google consent flow was completed. |
| Dependency audit | Composer audit failed: **62 advisories across16 locked packages**, including Laravel, Guzzle, CommonMark, Symfony and development packages. Initial npm audit18 affected packages (12high/5moderate/1low); after concurrent frontend updates latest npm audit reported **0**. Advisory applicability and backend remediation remain required. |

The 11 failures all belong to `AuthenticationIntegrationTest`: password/current-user explicit role contract, registration role contract, Google identity/history/email-verification/state/error handling and login-specific throttling. Several Google tests mock a stateful provider without the restored controller's `stateless()` expectation, so their mock failures are **not proof that a real Google login currently fails**. The restored-auth suite's3 tests pass, including mocked stateless Google. The full suite remains red; no test was weakened or silently excluded.

Final financial/analytics mounted reruns passed. Both emitted `WebSocket server error: Port 24678 is already in use` from the concurrent Vite test environment; these were not clean browser network runs. Resolve the harness HMR port contention for reproducible isolated CI.

## Feature evidence and gaps

| Product area | Verified evidence and remaining limits |
|---|---|
| Password/Google authentication | Session login/register/logout/recovery and CSRF tests pass in restored suite. Full auth security suite red; real Google provider/browser unverified. Existing auth preserved. |
| Draft/edit/publish/public profile/discovery | Event integrity tests pass for incomplete drafts, shared publish rules, IDs/version conflicts, category validation, media rollback, UTC/DST, stock/history and paginated filtering. Browser wizard/public rendering unverified this phase. |
| Ticket configuration and paid commerce | Commerce tests pass authoritative prices, snapshots, retry races, expiry, late/duplicate charges, provider mismatches, reconciliation, buyer ownership and delivery retries. No real provider-test checkout/browser popup was executed. |
| Free registration/attendees | Registration/admission tests and actual HTTP Vue service flow pass required questions, table seats, buyer separation, capacity and credential retrieval. |
| Staff/entrances/door history | Scoped membership, invitations, permissions, partial admission, repeat-request IDs, movement retention and simultaneous scans tested. Camera hardware/browser interaction unverified. Online validation only. |
| Promotions | Backend discount eligibility, amount snapshots, dates, pause/version rules and concurrent quota tests pass. **Live Vue discount authoring is still unavailable** (`WorkspaceViews.persistCodes` refuses non-preview writes); checkout service omits optional discount in quote/initialize. End-to-end discount redemption is incomplete. |
| Analytics/attribution | Backend tests pass historical snapshot metrics, currencies, SQL aggregates, bounded charts, attribution and recommendation signals. Vue aggregate/cache checks pass. Large production-scale reports not measured. |
| Messaging | Backend durable audience/consent/unsubscribe/delivery/retry tests pass. **Live Vue message composition/send is not integrated**; workspace draft submission refuses non-preview. SMTP delivery/bounce handling/provider operations unverified. |
| Earnings/payouts | Backend fakes verify masked onboarding, reconciliation evidence and platform-receipt vs seller-settlement distinctions. Vue UI/service checks pass. Real bank verification and settlement statement match unverified. No withdrawal capability. |
| Refund/cancel/complete/archive | Backend tests pass complete-line partial-order refunds, idempotency, pending/review states, cancellation work, credential revocation/physical exit and retained history. Frontend HTTP/UI cancellation/refund tests pass. Provider confirmation and operational obligations remain staging sign-off. |

Additional unsupported/deferred capabilities: recurring occurrence booking (explicitly blocked), unrestricted offline scanning, arbitrary amount/partial-unit/partial-seat refunds, owner settlement statement UI/API, automatic platform-held disbursement, automatic settled-seller recovery, SMS, blog endpoints. The exported auth-store `updateUser` method targets absent `PUT /api/user`; no active caller found, so it is a dormant unsupported contract, not a verified working profile feature.

## Performance evidence

Latest repeatable discovery fixture:121 catalog rows,30 sequential Laravel test-kernel requests,12 matching events/page, concurrency1. **p50 38.54ms / p95 47.80ms / p99 61.13ms**,26.05 requests/s within the kernel loop,0 errors,maximum6 SQL queries,10,633-byte response,54MiB PHP process peak (includes test infrastructure). Earlier run during concurrent test activity:56.90/155.75/163.84ms,15.81requests/s. Neither is HTTP server throughput, an SLO, or evidence of launch capacity. CPU utilization was not sampled.

Existing501-order dashboard measurement rerun in full suite:179.96ms,38 queries. EXPLAIN uses order PK lookups; the single-event501-item fixture scans matching item rows with temporary/filesort. All rows match, so this alone does not justify another index or precomputed infrastructure. Discovery's page relation reads are bounded; recommendation result groups are capped10 with30-second per-user cache. Substring `LOWER(...) LIKE '%word%'` search is bounded to20 words but remains catalog-scan work; measure larger representative catalogs before choosing full-text indexing. No new cache/queue infrastructure introduced.

Concurrent frontend route splitting reduced entry JS from403.34KB raw/133.35KB gzip to281.32KB/98.40KB; create-event is now a separate95.25KB/27.95KB chunk. Dashboard195.22KB/58.44KB. Main CSS149.19KB/27.80KB plus brand449.91KB/68.95KB; load waterfall/CSS coverage remains to be measured. These are build sizes, not load timings. Existing mounted checks cover selected timer/listener/camera cleanup, stale responses and duplicate cache request behavior; they do not prove every reactive path or asset is optimized.

Realistic HTTP load remains **unexecuted** for discovery, hot launches, provider verification, dashboards, multi-device scans and large attendee lists. The13 process-contention tests establish scenario correctness, not latency percentiles or throughput. Required staging run: fixed hardware/dataset, warm/cold cache, mixed user sessions, ramp to expected peak, collect request count/error rate/p50/p95/p99, CPU/RSS, DB lock waits/slow queries and queue age. Separate provider fakes from provider test-account limits. Stop on oversell, entitlement violation or financial mismatch; never load-test real charges. No million-user claim is supported.

## Security findings and release blockers

1. **Authentication requires separate authorized remediation.** Existing stateless Google flow has no OAuth state verification; source also overwrites password/name through `updateOrCreate`, does not enforce verified Google email, returns exception messages in redirects and returns raw User fields (only password/remember token hidden). Provider fields are absent from User fillable. Existing dedicated login limiter expectation fails (generic limiter is not equivalent). Local Google HTTP client disables TLS verification. Do not deploy local mode. These are source/security findings; do not assume mock failures reproduce real-provider behavior. User explicitly prohibited auth changes, so no changes were made to controller, model, auth store, session, CORS or bootstrap.
2. **Backend dependency advisories require triage and compatible upgrades**, especially runtime packages; update in an isolated branch with commerce/auth regression verification. No blind major upgrade or authentication dependency rewrite was performed.
3. **Phase5 frontend integration is incomplete** for discount creation/redemption and queued messaging. Do not advertise these as live working features.
4. **No browser/device/provider or operational sign-off.** Browser host failure prevents Chrome/Safari/Firefox/mobile checks, keyboard/accessibility audit, camera/manual scanner comparison, constrained CPU/memory/network testing and lab Web Vitals. Reduced-motion styles exist but have not been interactively certified. No field LCP/INP/CLS data exists. Targets remain p75 LCP<=2.5s,INP<=200ms,CLS<=0.1; no compliance claim.
5. **Production deployment not verified.** Local defaults (`APP_DEBUG` true, non-secure cookies, development origins) are not acceptable deployment evidence. Existing `/kaka/` build base requires matching hosting/rewrite. Vue's relative API path requires a real production proxy; static GitHub Pages alone cannot execute Laravel.

Public backend directory contains only index.php, favicon and robots at audit time. Frontend built output contained no PHP files. Root frontend `test.php`/`mime-test.php`, scripts, environment files, source and backend test diagnostics must never be deployed as a document root. This was a bounded source/build inspection, not a comprehensive secret scan or external penetration test. No secrets or ticket credentials are included here. Owner/resource-isolation, foreign product IDs, payment authenticity, ticket credentials and upload safety have automated coverage; this is not a guarantee of all permissions or upload edge cases.

## Required deployment and operations

Use PHP8.2+ with production web workers/OPcache, a supported relational database with transaction/row-lock behavior verified for that exact version, HTTPS, durable queue storage, shared sessions/cache/atomic locks and public asset storage. Local MariaDB10.4.32 is a verification environment, not an endorsement of its production lifecycle/support status. Database cache/session stores already support shared instances if all app instances use the same database; file-local sessions/cache cannot supply cross-instance locks. Keep one stable APP_KEY across instances and backup encryption keys securely. Do not rotate away access to encrypted ticket/payout data without a migration plan.

Before deployment: configure production APP_ENV/APP_DEBUG=false, canonical APP_URL/FRONTEND_URL, exact trusted stateful/CORS origins, secure HttpOnly session cookies with appropriate SameSite/domain, trusted TLS reverse proxy, provider callback/webhook URLs and test-vs-live separation, SMTP, storage URL/symlink and read/write permissions. Never expose Paystack secret keys as VITE values. Verify callback reachability/signatures using provider test facilities. No credentials are recorded in this report.

Run forward migrations with `php artisan migrate --force` after tested backups; never reset existing data. Cache configuration/routes only after correct environment configuration. Build Vue with matching base and SPA rewrite; proxy API/Sanctum paths, preserving cookies/headers and rate-limit identity. Use a supervised queue worker and minute scheduler. `schedule:list` confirms `commerce:dispatch`, `admission:dispatch-invitations`, `messages:dispatch`, `finance:dispatch`; verify workers actually drain queues, retries recover after restart and failures are monitored. Worker timeout must exceed the longest job timeout while staying below queue retry_after; inspect all jobs before final settings. Shared scheduler locks/one scheduler instance prevent duplicate dispatch bursts.

Monitor /up plus a separate DB/cache/queue synthetic check, HTTP5xx/p95, queue oldest age/failed jobs, unresolved payments, provider-review/refund-attention records, payout review, settlement mismatches and cancellation backlog. Redact provider payloads, recovery links, payment/access tokens, raw bank details and attendee PII from logs. Maintain an operator procedure for ambiguous subaccounts, duplicate/unfulfillable charges, platform-held seller disbursement, settled-seller recovery and legacy records. Allocations are never marked settled manually without reconciled evidence.

Backup/restore procedure to establish in staging: scheduled encrypted consistent DB backup with binlog/PITR retention, encrypted asset backup and separately protected APP_KEY/config recovery. Define owner-approved RPO/RTO and retention; restore into an isolated environment, validate row counts/financial snapshots/decryption/assets, run smoke tests and reconcile provider state before reopening commerce. No backup restoration or failover drill was executed here.

Safe rollout: frozen tested commits/artifact hashes, migration preflight/backups, pause checkout if incompatible changes require it, deploy backend-compatible additions before frontend, restart workers (`queue:restart`), run smoke checks, monitor queues/payments. Roll back application artifacts only while schema-compatible; Phase6 migration is intentionally forward-only. Do not run destructive migration rollback against commercial history. If financial writes occurred, use an audited forward fix and provider reconciliation.

## Next release gate

Resolve authorized auth security findings and red tests; remediate Composer advisories; finish Phase5 frontend integration; wire saved cutoff read; freeze and re-run both repos; execute real browser/device/accessibility and provider-test end-to-end flows; run representative staging load, queue restart/recovery and backup restoration drills. Preserve approved design and historical commerce records. The implemented lifecycle read is ready for frontend integration; the complete application is not yet cleared for production.
