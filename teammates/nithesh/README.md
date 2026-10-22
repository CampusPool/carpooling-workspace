# NitheshMogaveer777 — Sprint 1 task packet

**Focus:** Cancellation, SQL integrity, isolated tests and application CI

**Official issues:** [#24](https://github.com/CampusPool/carpooling/issues/24), [#37](https://github.com/CampusPool/carpooling/issues/37), [#39](https://github.com/CampusPool/carpooling/issues/39), [#43](https://github.com/CampusPool/carpooling/issues/43)

**Branch:** `s1/nithesh-integrity`

## Before you begin

Do not duplicate the project or copy the imported baseline into a personal subfolder. From the workspace repo root:

```sh
git fetch origin
git switch import/sprint1-attributed-sanitized  # until private PR #1 is reviewed and merged
git switch -c s1/nithesh-integrity
```

After PR #1 is merged, start new work from updated `main` instead. If this feature branch was made earlier, rebase it carefully onto approved main.

## Existing code to inspect

The paths below exist in the shared root; directory ownership is advisory, not a reason to copy code.
- `prototype/campuspool-extension/migrations/`
- `prototype/campuspool-extension/tests/MobilityLifecycleTest.php`
- `prototype/campuspool-extension/tests/MobilityFinancialInvariantsTest.php`
- `prototype/tests/extension/MobilityCommuteProductTest.php`
- `prototype/reproduction/`

## Actual contribution required

1. Verify repeat cancellation, durable cancelled history, restored capacity and exactly-one refund.
2. Prove clean migration ordering in a disposable test schema; never target the persistent pilot.
3. Introduce real application CI only when the baseline is merged; do not call documentation CI an application pass.
4. Leave ordered waitlist/rematching explicitly unfinished for Sprint 2.

## Verification

The full verifier creates and removes **its own** temporary Docker Compose database/volume and must never be pointed at the original running MobilityPlatform database.

```sh
cd prototype && sh reproduction/verify-package.sh
```

## PR handoff

- Submit a **real change**, not a commit containing only unchanged copied code.
- Suggested PR title: `test: verify cancellation history and database integrity`.
- Link the full official issue URLs and distinguish satisfied criteria from remaining ones.
- Supply tests run, test output and reproducible evidence.
- Never mark the entire issue Done if other acceptance criteria remain unfinished.
- Coordinate edits to shared controllers/routes/migrations with the responsible owner.
- Request an independent teammate review before merge.
