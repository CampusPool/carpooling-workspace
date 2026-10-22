# arjunsrikanthh — Sprint 1 task packet

**Focus:** Booking authorization, release coordination and last-seat contention

**Official issues:** [#23](https://github.com/CampusPool/carpooling/issues/23), [#44](https://github.com/CampusPool/carpooling/issues/44), [#37](https://github.com/CampusPool/carpooling/issues/37)

**Branch:** `s1/arjun-booking-release`

## Before you begin

Do not duplicate the project or copy the imported baseline into a personal subfolder. From the workspace repo root:

```sh
git fetch origin
git switch import/sprint1-attributed-sanitized  # until private PR #1 is reviewed and merged
git switch -c s1/arjun-booking-release
```

After PR #1 is merged, start new work from updated `main` instead. If this feature branch was made earlier, rebase it carefully onto approved main.

## Existing code to inspect

The paths below exist in the shared root; directory ownership is advisory, not a reason to copy code.
- `prototype/campuspool-extension/Mobility/Commute/CommuteService.php`
- `prototype/campuspool-extension/Mobility/Commute/CommuteController.php`
- `prototype/campuspool-extension/routes/commute.php`
- `prototype/tests/extension/MobilityCommuteProductTest.php`

## Actual contribution required

1. Add dedicated runtime regressions for self-booking, foreign-driver actions, and competing requests for the last seat.
2. Verify failures do not change capacity or create duplicate bookings.
3. Coordinate any shared route/service edits and capture integration evidence.

## Verification

The full verifier creates and removes **its own** temporary Docker Compose database/volume and must never be pointed at the original running MobilityPlatform database.

```sh
cd prototype && sh reproduction/verify-package.sh
```

## PR handoff

- Submit a **real change**, not a commit containing only unchanged copied code.
- Suggested PR title: `test: cover booking authorization and capacity contention`.
- Link the full official issue URLs and distinguish satisfied criteria from remaining ones.
- Supply tests run, test output and reproducible evidence.
- Never mark the entire issue Done if other acceptance criteria remain unfinished.
- Coordinate edits to shared controllers/routes/migrations with the responsible owner.
- Request an independent teammate review before merge.
