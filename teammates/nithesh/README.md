# Nithesh — Sprint 1

**GitHub:** @NitheshMogaveer777 · **Issues:** [#24](https://github.com/CampusPool/carpooling/issues/24), [#37](https://github.com/CampusPool/carpooling/issues/37) · **Focus:** Cancellation and MySQL integrity

## Start and submit
Clone the **whole repository**, not a teammate subfolder. Each subfolder is an instruction sheet, not a standalone app. **Use the official repository** and verify its latest CI.

```sh
git clone https://github.com/CampusPool/carpooling.git
cd carpooling
git switch main
git pull --ff-only origin main
git switch -c s1/nithesh-integrity
cd prototype
sh reproduction/start-local.sh
# http://127.0.0.1:18180/mobility/?demo=campus-route
python3 reproduction/smoke-booking.py
sh reproduction/stop-local.sh
cd ..  # back to repository root
```

If you have already cloned, update `main` and make your feature branch there; do not reclone or reset uncommitted work.

**Your feature branch and PR must contain work you actually implement or test. Do not submit someone else’s prepared changes as your own.**\n\n## Assigned work

1. Test cancellation history, repeated cancellation and restoration of available capacity.
2. Verify database migrations and constraints in a new isolated test database; avoid editing unrelated services.
3. Check duplicate financial effects using synthetic fixtures only. Waitlist is not a demonstrated feature.

**Relevant code:** `prototype/campuspool-extension/migrations/`, `prototype/campuspool-extension/Mobility/Commute/CommuteService.php` (coordinate shared edits with Arjun), `prototype/campuspool-extension/tests/`, `prototype/tests/extension/`

**Verification:** Run the relevant isolated PHP tests and `cd prototype && sh reproduction/verify-package.sh`; never reset the running pilot.

## Pull request
Commit only files you **actually change** and any associated tests; don't re-add the imported Carpoolear backend or generated `vendor/` files.

```sh
git status --short
git add -p  # review and stage only your changed tracked lines
# Also use git add on any new test files you created, specifying their exact paths.
git diff --cached --stat
git commit -m "test: verify cancellation and SQL integrity"
git push -u origin s1/nithesh-integrity
```

Open a PR **into `CampusPool/carpooling:main`**. Link the full official issue URLs, state what changed and tests actually run, and request teammate review. Review and merge approved contributions in the official [SE + DBMS repository](https://github.com/CampusPool/carpooling). The workspace is now a reference copy, not the PR destination. No teammate should claim copied baseline code as their new contribution.
