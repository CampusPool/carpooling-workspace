# NikhilJoshi441 — Sprint 1 task packet

**Focus:** Ride search, matching, route and pickup boundaries

**Official issues:** [#22](https://github.com/CampusPool/carpooling/issues/22)

**Branch:** `s1/nikhil-matching`

## Before you begin

Do not duplicate the project or copy the imported baseline into a personal subfolder. From the workspace repo root:

```sh
git fetch origin
git switch import/sprint1-attributed-sanitized  # until private PR #1 is reviewed and merged
git switch -c s1/nikhil-matching
```

After PR #1 is merged, start new work from updated `main` instead. If this feature branch was made earlier, rebase it carefully onto approved main.

## Existing code to inspect

The paths below exist in the shared root; directory ownership is advisory, not a reason to copy code.
- `prototype/campuspool-extension/Mobility/Commute/CommuteMatchingService.php`
- `prototype/campuspool-extension/Mobility/Routing/`
- `prototype/campuspool-extension/Mobility/Pickup/`
- `prototype/campuspool-extension/tests/MobilityRoutingQuoteTest.php`

## Actual contribution required

1. Add isolated tests for out-of-window, incompatible, full and unpublished offers.
2. Verify walking limits, route provider failure and stale quote/profile behavior.
3. Keep matching changes separate from the booking authority in CommuteService.php.

## Verification

The full verifier creates and removes **its own** temporary Docker Compose database/volume and must never be pointed at the original running MobilityPlatform database.

```sh
cd prototype && sh reproduction/verify-package.sh
```

## PR handoff

- Submit a **real change**, not a commit containing only unchanged copied code.
- Suggested PR title: `test: cover route matching boundaries and failures`.
- Link the full official issue URLs and distinguish satisfied criteria from remaining ones.
- Supply tests run, test output and reproducible evidence.
- Never mark the entire issue Done if other acceptance criteria remain unfinished.
- Coordinate edits to shared controllers/routes/migrations with the responsible owner.
- Request an independent teammate review before merge.
