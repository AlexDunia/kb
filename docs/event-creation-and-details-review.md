# Event creation, editing and public details

Reviewed: 24 November 2026. Scope: the current frontend and backend source, followed by a proposed product flow. This document does not implement changes. “Supported” below describes inspected code, not a completed browser test.

## 1. The central decision

Create should collect enough information to describe the event accurately and let somebody register or buy the correct admission. Edit should let the organiser complete and maintain the richer public page without repeating creation.

Do not make cast biographies, crew photographs, a full programme, gallery captions or FAQs mandatory just to create an event. Do not postpone information that changes what somebody is buying: date, attendance format, location/access arrangements, price, capacity and admission entitlement must be correct before booking opens.

There are three different milestones:

1. **Save draft:** preserve incomplete work privately.
2. **Publish:** make a sufficiently complete event visible.
3. **Open bookings:** allow registration or payment only when inventory and fulfilment are ready.

The current API separates saving a draft from publishing. Publishing an ordinary open event can make booking available immediately; there is no separate publish-with-bookings-off switch in the inspected editor payload. Separating the third milestone in the interface would require coordinated work, not just new button text.

## 2. Event types are independent choices

| Dimension | Current values | Meaning |
|---|---|---|
| Admission/pricing | `free`, `paid` | Whether admission requires payment |
| Attendance format | `in-person`, `online`, `hybrid` | Where people participate |
| Schedule | `one_time`, `recurring` | One occurrence or a repeating pattern |
| Category | Existing database category name | What the event is about |
| Ticket unit | `individual`, `table` | What one purchased unit admits |
| Publication | `draft`, `active` in the editor | Whether it is published |

A free event can be online, hybrid or in person. A paid event can also use any attendance format. These should not become separate creation forms with duplicated rules.

“Virtual” corresponds to `online`. A press event is a subject/category or potentially an accreditation workflow; `press` is not a special admission or attendance mode in the inspected validation. Press-only approval, invitation-only admission and accreditation should not be presented as existing features. The phrase “paid, press, virtual, free” does not establish an implementation contract for press events.

The schema accepts recurring together with pricing and attendance choices, but recurring booking is explicitly unsupported today. A valid combination of form values does not prove that its complete purchase flow exists.

## 3. What Create collects today

The current frontend has four steps: **Basics → Details → Tickets → Attendees**. Edit reuses the same `CreateEvent.vue` component.

| Step/group | Fields collected | Current requirements/behaviour |
|---|---|---|
| Basics | Event name, category | Required by frontend progression and backend publishing |
| Timing | Start, end, timezone | End must follow start; local wall times are converted to UTC instants |
| Schedule | One-time or recurring | Recurring reveals additional schedule controls |
| Recurrence | Daily/weekly/monthly; weekdays; monthly date or ordinal weekday; end date or occurrence count | Conditional on recurring; frontend previews upcoming occurrences |
| Attendance | In person, online, hybrid | Controls venue and meeting-link requirements |
| Physical location | Venue/address text, city | Venue required for in-person/hybrid publishing; city accepted but not required by the inspected publisher |
| Online access | Meeting link | Required for online/hybrid publishing; HTTP(S) URL |
| Details | Description, organiser name, organiser website, tags | Optional in backend publishing; missing organiser becomes “Unknown Organiser” |
| Images | Cover, additional images | Cover required for publishing; additional images optional |
| Practical details | Parking, dress code, what to bring, agenda, speakers, food/drinks, transport, FAQ text | Optional text blocks, up to one block per supported type |
| Admission | Free or paid | Free is the default |
| Free admission | Capacity | Optional in the editor; missing capacity becomes zero, not unlimited |
| Paid products | Name, individual/table, colour, price, units, people per table, purchase limit, visibility, perks, sales start/end | At least one named positive-price product required for paid publishing |
| Attendees | Which attendee questions are enabled and required | Defines questions answered later during registration/checkout, not an attendee list entered by the organiser |

Important distinction: the backend can save incomplete drafts, but the frontend validates steps before progressing. “Save draft” and “continue” should not accidentally impose identical completeness rules.

Capacity and ticket visibility also need plain explanations. A paid event can satisfy publishing validation with zero ticket units, and a free event can have zero capacity. Neither should be described as ready to accept bookings merely because it published successfully.

## 4. What can exist on the full event page

The public page supports more than the current creation wizard exposes.

| Public information | Current source | Collected by current wizard? | Recommended place |
|---|---|---|---|
| Title, category, cover, description | Event fields | Yes | Create; editable later |
| Date, time, timezone | Event fields | Yes | Create; controlled edits later |
| Venue and city | Address relation | Yes | Create for physical attendance |
| Full street/state/postcode/country | Address model/public resource | No complete structured address editor; save service currently fills several values with blanks and country with Nigeria | Required location work before relying on detailed addresses; optional address refinements in Edit |
| Organiser display name and website | Organiser relation/event website | Yes | Create, preferably prefilled from a verified source when one exists |
| Organiser bio and photo | `publicProfile.organiser_bio`, `organiser_image` | No dedicated controls found | Edit → Organiser |
| Short summary | `publicProfile.summary` | No | Edit → Overview |
| Feature heading and supporting copy | `caption_title`, `caption_description` | No | Edit → Overview; optional |
| Guests, cast, performers, speakers | `publicProfile.guests`: id, name, role, bio, image | Only a simple speakers text block | Edit → People; use roles rather than separate databases for each event category |
| Programme | `publicProfile.schedule`: time, title, description, label | Only an agenda text block | Edit → Schedule |
| Gallery / moments | Additional images or `publicProfile.moments`: URL, caption | Images yes; structured moments/captions no | Edit → Gallery |
| FAQs | `publicProfile.faqs`: question, answer; legacy/text fallbacks also exist | Simple FAQ text block | Edit → FAQs |
| Crew / production credits | `publicProfile.crew`: name, role, image | No | Edit → People/credits |
| Related events | `related_event_ids`, resolved public related-event data | No selector found | Edit → Related events; optional |
| Parking, dress, bring, food, transport | `extraDetails` | Yes | Optional practical details, usually Edit |
| Price, ticket options, availability | Ticket products and server inventory | Yes, configuration only | Create essentials; Edit advanced controls |
| Registration questions | `attendeeFields` → public `registration_fields` | Yes | Defaults in Create; optional Edit configuration |
| Event highlights/options and subcategories | Public resource relations | No corresponding fields in the inspected create payload | Existing read capability; do not assume a working write flow |

The public adapter uses structured profile data with fallbacks to existing text fields. For example, speakers text can become simple guest entries, but that does not provide full bios and photos. Schedule and FAQ text also have fallback parsing. The current frontend creation payload does not submit `publicProfile`, although the backend accepts it and returns it to an authorised editor.

**Conclusion:** rich public-profile storage and rendering exist, but a complete organiser-facing editor for those fields was not found in the inspected frontend. Existing Edit mode alone does not fill this gap.

The online meeting link is deliberately absent from `PublicEventResource`. Keep private joining credentials out of public event JSON. The inspected sources do not establish a complete authorised attendee join-link delivery flow; validate that separately before selling online access.

## 5. Proposed minimum Create flow

This is a recommendation, not the current enforced contract. Preserve the approved visual design; shorten interaction through conditional fields and optional sections rather than a redesign.

### A. Basics

Collect title, category, one-time/recurring, start, end, timezone and attendance format. Default timezone sensibly but make it visible and editable. Show physical-location fields only for physical attendance; show online access fields only for online participation.

### B. Public essentials

Collect cover, a concise event description and organiser display name. Description and organiser are recommendations for publication quality: the current backend does not require them. Do not silently substitute a fabricated organiser identity. Keep website, tags, extra images and practical notes optional.

### C. Admission and review

For free events, ask for a real registration capacity. State explicitly that zero means no places. For paid events, start with one ticket: name, price and number of units. Selecting “table” additionally requires people admitted per table. Offer “add another ticket” only when needed.

Keep purchase limits, sales windows, perks, visibility and colours in optional advanced controls. Do not hide a setting if it changes the intended entitlement or sales timing.

Use sensible existing attendee-question defaults and offer “customise registration questions” as optional. Additional questions should not force every organiser through an extra mandatory step. Essential accessibility or safety information may belong before publication even if other enrichment is deferred.

Finish with a review of exactly what the attendee will see and buy. Distinguish “Save draft” from “Publish”. Where booking cannot work, explain the reason instead of displaying a successful booking-ready state.

### Where to stop asking questions

If omitting a field leaves an attendee unsure what the event is, when/where it happens, what admission costs or what it admits, collect it before bookings open. Otherwise make it optional and available in Edit. Optional information can still be filled during initial creation through an expandable section; Edit should not be the only opportunity to add it.

## 6. How the interactions differ

| Combination | Organiser supplies | Attendee interaction | Current caveat |
|---|---|---|---|
| Free, in person | Venue, time, capacity | Register without payment, then use issued admission | Zero capacity is not unlimited |
| Paid, in person | Venue plus priced products and inventory | Select units → attendee answers → checkout/payment → tickets | Server owns price, stock and payment outcome |
| Free, online | Time, capacity, private joining link | Register → obtain authorised access | Join-link delivery must be verified |
| Paid, online | Priced products plus joining arrangements | Pay → receive authorised access | A payment receipt alone does not establish the online fulfilment flow |
| Free or paid, hybrid | Physical venue and online link | Should choose a participation entitlement if capacity/access differs | No explicit online-versus-physical entitlement is present in inspected ticket fields |
| Recurring, any format | First occurrence, repeat rule and series end | Intended future flow: choose occurrence, then register/buy | Both free registration and paid checkout currently reject recurring booking |
| Press or invitation-only | Depends on whether public registration or accreditation is intended | Approval/accreditation only if explicitly implemented | No dedicated workflow established here |

For hybrid events, naming products “Online” and “In person” is only display text. It does not enforce separate access rights. Until that distinction exists, do not promise channel-specific admission or expose private online access to the wrong ticket holders.

The current editor has one event-wide `free`/`paid` mode. Paid publishing requires every supplied ticket price to be above zero, so a mixed free-and-paid ticket catalogue is not supported by this contract. Tables are purchase units, not a seat-map or assigned-seating system.

## 7. Recurring events: current state and intended flow

### What exists now

- First start/end time and timezone.
- Daily, selected-weekday weekly, or monthly recurrence.
- Monthly date or ordinal weekday rule.
- End on a date or after a number of occurrences.
- Frontend preview and an overlap check.
- Stored recurrence JSON and backend recurrence validation.
- Explicit rejection of recurring free registration and paid checkout; the public API reports booking unsupported.

The inspected create service stores a series rule; it does not create independently bookable occurrence inventory. The frontend preview is not a booking engine. The public resource also does not expose the stored recurrence rule, so a complete public occurrence selector cannot be assumed.

### Proposed flow when recurring booking becomes a separate assigned phase

1. Organiser enters the first occurrence and repeat rule.
2. Show a bounded preview, timezone and clear series end.
3. Decide whether tickets cover one occurrence or the entire series. Recommended initial scope: one selected occurrence per booking.
4. Provide a stable occurrence identity and inventory for that occurrence on the server.
5. Attendee selects a date first, then the applicable admission product.
6. Store the purchased occurrence and entitlement in the historical order/ticket snapshot.
7. Editing asks whether a change affects one occurrence or future occurrences; sold past entitlements remain intact.
8. Define exceptions, cancellations and attendee notifications before enabling sales.

Unresolved decisions include short months, a nonexistent fifth weekday, daylight-saving transitions, skipped dates, rescheduling and per-occurrence capacity. Existing preview rules need comparison with server generation before either is called authoritative. Do not build these features as part of simplifying Create.

**Immediate product position:** recurring may be saved as a draft or informational listing, but must be clearly marked unavailable for platform booking until occurrence support is implemented.

## 8. Edit already exists; extend its scope deliberately

Frontend routes already include `/create-event` and `/events/:id/edit`, sharing `CreateEvent.vue`. Backend contracts are:

| Operation | Endpoint |
|---|---|
| Create draft | `POST /api/create-event` |
| Load owned event for editing | `GET /api/create-event/{id}` |
| Save changes | `PATCH /api/create-event/{id}` |
| Publish draft | `POST /api/create-event/{id}/publish` |

Updates and publishing require the current `version`. A stale edit returns a conflict; missing update version is rejected. The backend locks records and checks ownership. Drafts and active events are editable only in allowed lifecycle states. Updating an active event enforces publication validation; the organiser does not republish it as a new event.

Recommended Edit sections:

1. Basics and location/access.
2. Admission and inventory.
3. Overview and media.
4. Organiser, guests/cast/speakers and crew.
5. Programme and FAQs.
6. Registration questions and practical notes.

Use the same event ID and existing save service. Optional section edits should send targeted patches. The full wizard currently explicitly replaces its ticket collection; partial profile edits should not accidentally use that replacement behaviour or erase unrelated fields.

Commercial protections already include capacity not falling below commitments, retirement of removed products, restrictions on changing table admission semantics after history exists, restrictions on switching free/paid with commitments, and restrictions on changing recurrence semantics after order history. Preserve them. Existing order price/entitlement snapshots must remain historical facts even when products are edited.

Date/location changes after sales deserve an explicit review and notification policy; the inspected create/edit flow does not establish automatic notification for every such edit. The current copy saying everything can be updated after publishing is broader than the actual restrictions and should be corrected in a future implementation.

## 9. Decisions and gaps before implementation

| Item | Decision/recommendation |
|---|---|
| Reduce initial effort | Keep only public/booking essentials mandatory; advanced sections optional |
| Rich detail editing | Add controls using the existing `publicProfile` contract; do not create another competing event store |
| Duplicated content | Choose structured schedule/guests/FAQs as the preferred editor source; preserve legacy text until intentionally migrated |
| Missing location detail | Coordinate structured address input and persistence if full addresses are required; current save contract is venue/city only |
| Free capacity | Explain zero accurately; require positive capacity when claiming registration is open |
| Online fulfilment | Verify or implement authorised access delivery before online sales launch |
| Hybrid entitlements | Decide whether one admission covers both channels; otherwise implement explicit channel permissions |
| Recurring sales | Keep disabled until occurrence identity, inventory and entitlement rules exist |
| Public empty sections | Prefer omitting empty optional sections; never fabricate guests or programme data |
| Unimplemented public actions | Guest following is explicitly unavailable in the inspected page; do not count it as an organiser data field |

## 10. Inspection sources and verification limits

Backend sources: `app/Services/EventEditorValidation.php`, `CreateEventService.php`, `app/Http/Controllers/Api/CreateEventController.php`, `app/Http/Resources/PublicEventResource.php`, `app/Models/EventPublicProfile.php`, `app/Services/Commerce/RegistrationService.php`, `FreeRegistrationService.php`, `CheckoutService.php`, `PaymentFinalizer.php`, and `routes/api.php`.

Frontend sources: `C:/Users/HP/kaka/src/pages/CreateEvent.vue`, `EventDetails.vue`, `src/services/createEventService.js`, `eventService.js`, `src/utils/eventEditor.js`, `eventProfile.js`, and the previously inspected router.

This review inspected source paths and contracts. It did not submit event forms, inspect actual event records, run purchases, or test online access or recurring execution in a browser. No application code, schema, API contract or frontend design was changed. No tests were needed for this documentation-only deliverable. The recommendations require separately scoped implementation and end-to-end verification before release.
