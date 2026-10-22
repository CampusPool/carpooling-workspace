# Carpoolear Mobility extension

This mountable Laravel extension adds an authenticated trip lifecycle to the existing `STS` backend. Mount the whole extension read-only at `/opt/mobility-extension`, mount its `Mobility/` directory at `app/Mobility`, set `MOBILITY_EXTENSION_ROOT=/opt/mobility-extension`, and register `STS\Mobility\MobilityServiceProvider`.

Routes are `/api/mobility/trips/{trip}/lifecycle`, `/api/mobility/trips/{trip}/transitions`, and the administrator audit route `/api/mobility/operator/trips/{trip}`. Transition requests use JSON `action` (`start`, `board`, `alight`, `complete`, or `cancel`), `passenger_id` for board/alight, and a printable ASCII `Idempotency-Key` up to 100 characters.

Trip lifecycle mutations are owner-controlled. A separate rider-authenticated `POST /api/mobility/trips/{trip}/dropoff-confirmation` requires `Idempotency-Key`, an accepted/alighted booking and the matching alight event. It records an acknowledgement, not GPS proof. The host/operator cannot impersonate the rider.

The extension stores an independent state machine and immutable event response records while reading accepted bookings from the legacy tables. A local financial core adds fare snapshots, trusted capture ingestion, balanced postings and guarded payout eligibility/outbox. See [financial scope and limits](Mobility/Payments/README.md). Provider money movement, dispatch, notifications, recurrence and native clients are not implemented.

Payment history now has rider/driver views and an explicit administrator financial audit route. Authenticated cash acknowledgements record a separate two-party receipt and terminal dispute history, without creating provider funds. See [API contract](../../../docs/PAYMENT_API.md) for role scope, pagination, timing and retry rules.

## Original candidate-matching domain (offline proof)

Original domain components Mobility/Matching/RouteSegmentMatcher.php and Mobility/Matching/TripMatchReference.php implement a deterministic stop/time/segment-seat candidate selector and validated match IDs. Test with tests/RouteSegmentMatcherStandalone.php. This is our own algorithm, not HOPR recovered source or score. No HTTP route, authoritative seat inventory or transactional booking path is wired yet. Production work requires persistence, ownership, booking-time segment capacity locks and recurrence. See analysis/HOPR_ANDROID_WORKFLOW_CONTRACTS.md at the project root.

## Read-only weekly schedule preview

Authenticated trip owners can call GET /api/mobility/trips/{trip}/schedule-preview?from=YYYY-MM-DD&days=14 for an existing weekly-mask trip. It uses Asia/Kolkata, 1–31 day bounded results and explicitly returns is_preview_only=true and occurrences_materialized=false. It creates no booking, cash/provider record, or dated trip instance. Standalone test: tests/WeeklySchedulePreviewStandalone.php. Laravel HTTP tests: tests/WeeklySchedulePreviewHttpTest.php. Dated recurring booking and capacity remain unimplemented.

## Disabled unsafe legacy payment promotion

The linked integration middleware LegacyLifecycleGuard fails closed for a WAITING_PAYMENT booking posted to legacy /api/trips/{trip}/requests/{user}/pay, returning 409 LEGACY_PAYMENT_PROMOTION_DISABLED before the unsafe promotion. Invalid non-waiting attempts retain upstream 422 validation. MobilityLegacyPaymentGuardTest verifies no state change. This is a temporary HTTP boundary only, until trusted payment proof, actor authorization, atomic seat rechecks and provider callback reconciliation exist. Do not use the old /pay route for live money.

## Local browser pilot (18 October)

The new development-only browser interface is served on the **same origin** as the authenticated API by `mobility-pilot/{asset?}` only when `MOBILITY_PILOT_UI_ENABLED=true`. Run the separate loopback-only launcher `sh scripts/mobility-pilot-up.sh`, then open `http://127.0.0.1:18080/mobility-pilot`. See [pilot client runbook](../../../docs/PILOT_WEB_CLIENT.md). It uses an in-memory JWT and real request/accept/lifecycle writes against the existing MySQL authority. It is not a complete mobile app, trusted routing system or payment integration.


### Testing-only demo publication

`Mobility/Pilot/PilotOfferController.php` exposes `GET /api/mobility/pilot/routes` and `POST /api/mobility/pilot/offers`, requiring existing logged middleware **and** both testing environment plus enabled UI flag. `PilotDemoRoutes` publishes two synthetic directions into local RouteCache only from the CLI seed. The controller validates a server-owned route, Asia/Kolkata departure, seats and notes and calls existing TripsManager. Arbitrary client-supplied coordinates, distance and pricing are never authoritative. Local HTML selects one of the synthetic routes, so a complete host with a seeded vehicle can publish, a rider can discover/request, and host can accept. `MobilityPilotOfferHttpTest` covers safe rejection and successful write/booking. This fixture path is not a real mapping provider, on-demand dispatch or publicly deployable routing service.
