# Arjun — Sprint 1

**GitHub:** @arjunsrikanthh · **Issues:** [#23](https://github.com/CampusPool/carpooling/issues/23), [#39](https://github.com/CampusPool/carpooling/issues/39), [#44](https://github.com/CampusPool/carpooling/issues/44) · **Focus:** Booking correctness, CI and demo evidence

## Start and submit
Clone the **whole repository**, not a teammate subfolder. Each subfolder is an instruction sheet, not a standalone app.

```sh
git clone https://github.com/CampusPool/carpooling-workspace.git
cd carpooling-workspace
git switch main
git pull --ff-only origin main
git switch -c s1/arjun-booking-release
cd prototype
sh reproduction/start-local.sh
# http://127.0.0.1:18180/mobility/?demo=campus-route
python3 reproduction/smoke-booking.py
sh reproduction/stop-local.sh
cd ..  # back to repository root
```

If you have already cloned, update `main` and make your feature branch there; do not reclone or reset uncommitted work.

## Assigned work

1. Add focused regression tests for self-booking, unauthorized acceptance and last-seat contention.
2. Check current workspace CI after the MySQL TCP readiness fix; investigate any **new** failure using migration and database logs, and record the actual result.
3. Finalize reproducible booking demonstration evidence and the one-minute Sprint 1 video.

**Relevant code:** `prototype/campuspool-extension/Mobility/Commute/CommuteService.php`, `prototype/campuspool-extension/Mobility/Commute/CommuteController.php`, `prototype/tests/extension/`, `.github/workflows/application.yml`

**Verification:** Run `cd prototype && sh reproduction/verify-package.sh` using its isolated database; capture the real outcome.

## Pull request
Commit only files you **actually change** and any associated tests; don't re-add the imported Carpoolear backend or generated `vendor/` files.

```sh
git status --short
git add -p  # review and stage only your changed tracked lines
# Also use git add on any new test files you created, specifying their exact paths.
git diff --cached --stat
git commit -m "test: verify booking authorization and capacity"
git push -u origin s1/arjun-booking-release
```

Open a PR **into `CampusPool/carpooling-workspace:main`**. Link the full official issue URLs, state what changed and tests actually run, and request teammate review. PRs from the shared workspace will be integrated into the official [SE + DBMS repository](https://github.com/CampusPool/carpooling) only after review. No teammate should claim copied baseline code as their new contribution.
