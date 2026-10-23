# Nikhil — Sprint 1

**GitHub:** @NikhilJoshi441 · **Issues:** [#22](https://github.com/CampusPool/carpooling/issues/22), [#43](https://github.com/CampusPool/carpooling/issues/43) · **Focus:** Ride search and Test Plan execution

## Start and submit
Clone the **whole repository**, not a teammate subfolder. Each subfolder is an instruction sheet, not a standalone app.

```sh
git clone https://github.com/CampusPool/carpooling-workspace.git
cd carpooling-workspace
git switch main
git pull --ff-only origin main
git switch -c s1/nikhil-matching
cd prototype
sh reproduction/start-local.sh
# http://127.0.0.1:18180/mobility/?demo=campus-route
python3 reproduction/smoke-booking.py
sh reproduction/stop-local.sh
```

If you have already cloned, update `main` and make your feature branch there; do not reclone or reset uncommitted work.

## Assigned work

1. Test date/direction/vehicle mismatches, full or unpublished offers, stale quotes and route-provider failures.
2. Record results against the approved Test Plan; coordinate booking and cancellation tests with the respective owners.
3. Fix a confirmed matching defect only within the matching module; document unsupported cases.

**Relevant code:** `prototype/campuspool-extension/Mobility/Commute/CommuteMatchingService.php`, `prototype/campuspool-extension/Mobility/Routing/`, `prototype/tests/extension/`, `prototype/campuspool-extension/tests/`

**Verification:** Run the isolated relevant backend tests, then `cd prototype && sh reproduction/verify-package.sh` where available.

## Pull request
Commit only files you **actually change** and any associated tests; don't re-add the imported Carpoolear backend or generated `vendor/` files.

```sh
git status --short
git add <your-changed-files>
git commit -m "test: cover ride matching boundaries"
git push -u origin s1/nikhil-matching
```

Open a PR **into `CampusPool/carpooling-workspace:main`**. Link the full official issue URLs, state what changed and tests actually run, and request teammate review. PRs from the shared workspace will be integrated into the official [SE + DBMS repository](https://github.com/CampusPool/carpooling) only after review. No teammate should claim copied baseline code as their new contribution.
