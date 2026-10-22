# Carpoolear integration

This provisional integration reuses the pinned Carpoolear authentication, accounts, vehicles, trip offers, search, passenger bookings, notifications and MySQL authority. Original extension code lives under `backend/extensions/carpoolear`; no Hopr proprietary source or assets are included.

From the project root:

```sh
sh scripts/prepare-carpoolear.sh
colima start --profile mobility
sh research/experiments/carpoolear/run-smoke.sh lifecycle
```

Preparation clones the official source only if missing, verifies its revision, creates a separate evaluation worktree and applies the booking/trust patch followed by the lifecycle integration patch. Existing worktrees are preserved; conflicts stop preparation. Check any additional local changes before interpreting a result. The runtime helper uses loopback HTTP inside an internal container network and removes its synthetic database afterward. No host ports are exposed.

`lifecycle-integration.patch` registers the provider, installs legacy HTTP write guards, excludes non-planned trips from available-offer search and revokes inactive public live-share tokens. The HTTP guard serializes numeric-trip mutations with lifecycle transitions; feedback and stopping a share remain allowed after completion. Share start/location writes are allowed only before a terminal state. A terminal transition deactivates active shares and clears their coordinates in the same transaction.

Inactive public tokens now return404 rather than the upstream stopped-trip profile. The patch updates the corresponding upstream tests to express that deliberate privacy change; other trip/live-share tests remain intact. Internal services, background jobs and future write paths still need an adoption audit; the guard currently covers HTTP entry points.

This is a software increment, not final foundation adoption. Recurring occurrences, per-segment capacity, on-demand fulfillment, payment collection/refunds/payouts, financial reconciliation, operating support and release readiness remain required. The user accepts an open-source release while development stays private. Upstream GPL-3.0 and dependency obligations must be assessed before adoption/distribution; the upstream source license is not replaced by this integration.
