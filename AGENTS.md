# RUSH HOUR — PERMANENT ENGINEERING AND DEVELOPMENT INSTRUCTIONS

You are working on Rush Hour, an event management and ticketing SaaS platform intended to progressively scale to millions of users.

The existing infrastructure consists of:

- Frontend: Vue.js, Pinia, Vue Router, Axios and Vite.
- Backend: Laravel 12, Eloquent, Sanctum, Socialite and Paystack.
- Frontend repository: `C:/Users/HP/kaka`.
- Backend repository: `C:/Users/HP/kakabackend`.
- Frontend development port: 5174.
- Backend development port: 8000.

## 1. Engineering philosophy

Apply the following engineering principles throughout development:

- Robert C. Martin's Clean Code principles.
- Google Engineering Practices and Site Reliability Engineering.
- Pavel Durov's emphasis on speed, simplicity, automation, device compatibility and product craftsmanship.
- John Carmack's emphasis on computational efficiency, code inspection and problem-solving.
- Martin Kleppmann's principles of data consistency, concurrency and reliable data-intensive systems.
- Official Laravel, Vue.js, PHP, Vite and database engineering conventions.

Apply these principles pragmatically. Do not introduce unnecessary abstractions, design patterns or infrastructure simply to appear sophisticated.

## 2. Existing codebase

This is an existing application, not a new project.

Before implementing a change:

- Inspect the relevant current source files, their dependencies and their actual execution paths.
- Use the supplied file references as starting points.
- Do not perform repeated repository-wide discovery when the relevant files and relationships are already known.
- Perform targeted additional inspection only when necessary.
- Verify assumptions against the actual implementation.
- Reuse working services, models, components and utilities.
- Do not overwrite unrelated work or revert existing uncommitted changes.

Do not rebuild functioning infrastructure without a demonstrated technical reason.

## 3. Frontend preservation

The existing visual design is approved.

Do not redesign, restyle, restructure or replace existing visual interfaces unnecessarily.

Preserve typography, spacing, colours, layouts, responsive behaviour, components, navigation and animations.

Only modify presentation when strictly necessary to make a requested feature functional, accessible or secure.

## 4. Frontend-backend consistency

Laravel must remain the authoritative source of persistent business data.

Vue should manage presentation, temporary user interactions and cached server responses.

Do not maintain competing business databases in localStorage, sessionStorage or component memory.

Use consistent API contracts, identifiers, field names, status values, date formats, monetary representations and error responses.

The frontend must not invent undocumented endpoints or assume unsupported backend functionality.

Preserve existing API compatibility unless a coordinated change is necessary.

## 5. Clean code

Use meaningful names, cohesive functions, clear responsibilities and predictable dependencies.

Avoid:

- Duplicate business rules.
- Unnecessary dependencies.
- Over-engineered service abstractions.
- Dead code.
- Unbounded queries and loops.
- Unnecessary API requests.
- Excessive component rendering.
- Unnecessary watchers.
- Hardcoded environment-specific URLs.
- Silent mock-data fallbacks.
- Fake successful operations.
- Temporary patches that conceal architectural problems.

Prefer simple, understandable implementations that can be independently tested.

## 6. Data structures and algorithms

Consider time complexity, space complexity, database query cost, network payload size, memory consumption and execution frequency.

Use appropriate data structures, indexed lookups, pagination, eager loading, bounded processing, efficient aggregation and caching where justified.

Do not load entire database collections into application memory or the browser when filtering, searching or pagination can happen at the database level.

Measure performance before introducing major optimisations.

## 7. Security

Security is a release requirement.

Enforce authentication, resource ownership, scoped permissions, input validation and business rules on the backend.

Protect personal information, financial information, payment credentials, QR tokens and administrative functionality.

Never trust frontend-supplied prices, seller identities, permissions, payment success or ticket admission decisions.

Do not expose secrets, raw production exception messages, private event information or internal credentials.

Use secure session handling, CSRF protection, rate limiting and appropriate upload validation.

Do not weaken security checks to make tests pass.

## 8. Commerce and concurrency

Protect historical purchases and financial records.

Use database transactions, appropriate locking, unique constraints and idempotency where required.

Consider simultaneous purchases, duplicate requests, expired reservations, failed payments, delayed webhooks and simultaneous ticket scans.

Never change the historical price or admission entitlement of an existing purchase merely because an organiser edits a ticket product.

## 9. Database changes

Preserve existing records.

Use forward-compatible migrations for necessary schema changes.

Do not execute destructive migrations, reset databases, erase user records or run `migrate:fresh` against an existing development or production database.

Use isolated test databases.

## 10. Verification

For every implementation:

- Test successful operations.
- Test invalid input.
- Test unauthorised access.
- Test relevant concurrency and retry behaviour.
- Test failure recovery.
- Check existing functionality for regressions.
- Run relevant formatting, static analysis, build and test commands.

Do not claim tests passed unless they were actually executed.

## 11. Scope control

Implement only the current assigned phase.

Do not begin future phases automatically.

Do not make unrelated changes, install unnecessary packages, rewrite working components or generate redundant documentation.

Security issues discovered outside the current phase should be documented and prioritised. Address immediately exploitable issues when safely within scope; otherwise explicitly report release blockers.

## 12. Completion report

At the end of each phase, provide:

1. Problems identified and verified.
2. Changes implemented.
3. Files modified.
4. Database migrations added, if any.
5. API contracts added or changed.
6. Test commands and actual results.
7. Remaining limitations or unresolved issues.
8. Exact integration instructions for the other repository.
9. Whether the phase is ready for end-to-end testing.

The objective is not merely functional-looking software. The objective is correct, secure, efficient, maintainable and production-quality software.

Follow these instructions throughout all subsequent Rush Hour development phases.