# Zayaan — Sprint 1

**GitHub:** @zayaan-06 · **Issues:** [#12](https://github.com/CampusPool/carpooling/issues/12), [#21](https://github.com/CampusPool/carpooling/issues/21) · **Focus:** Driver details, ride offers and browser UX

## Start and submit
Clone the **whole repository**, not a teammate subfolder. Each subfolder is an instruction sheet, not a standalone app.

```sh
git clone https://github.com/CampusPool/carpooling-workspace.git
cd carpooling-workspace
git switch main
git pull --ff-only origin main
git switch -c s1/zayaan-web-commute
cd prototype
sh reproduction/start-local.sh
# http://127.0.0.1:18180/mobility/?demo=campus-route
python3 reproduction/smoke-booking.py
sh reproduction/stop-local.sh
```

If you have already cloned, update `main` and make your feature branch there; do not reclone or reset uncommitted work.

## Assigned work

1. When the map provider is unavailable, show a useful text route/pickup summary, not just a failure message.
2. Test car/bike offer form validation and driver/rider journey status on small/desktop screens.
3. Add focused frontend tests and a sanitized screenshot of the changed behavior.

**Relevant code:** `prototype/campuspool-extension/web/commute.mjs`, `prototype/campuspool-extension/web/commute-map.mjs`, `prototype/campuspool-extension/web/commute.html`, `prototype/campuspool-extension/web/commute.css`, `prototype/tests/frontend/`

**Verification:** From `prototype/`, run `node --test tests/frontend/test-commute-ui.mjs tests/frontend/test-commute-map.mjs tests/frontend/test-place-search.mjs`.

## Pull request
Commit only files you **actually change** and any associated tests; don't re-add the imported Carpoolear backend or generated `vendor/` files.

```sh
git status --short
git add <your-changed-files>
git commit -m "fix: handle commute map failures clearly"
git push -u origin s1/zayaan-web-commute
```

Open a PR **into `CampusPool/carpooling-workspace:main`**. Link the full official issue URLs, state what changed and tests actually run, and request teammate review. PRs from the shared workspace will be integrated into the official [SE + DBMS repository](https://github.com/CampusPool/carpooling) only after review. No teammate should claim copied baseline code as their new contribution.
