# CampusPool — Sprint 1: start here

**Baseline reviewed:** workspace `main` commit `d8ec8a4`, 23 October 2026.  
**Purpose:** get every contributor and the teacher to the *same* executable, explainable demonstration. This is a **synthetic campus car/bike pooling prototype**, not a production transport or payment application.

## The one-sentence product

For a fixed date, a demonstration driver publishes a campus-bound ride, a demonstration rider searches compatible offers and requests a seat, the driver accepts the request, and the rider can cancel the booking; the backend records the resulting state in MySQL.

## Which repository is which?

| Location | Purpose |
|---|---|
| [CampusPool/carpooling](https://github.com/CampusPool/carpooling) | Official issues, academic backlog and [Sprint Plan PR #45](https://github.com/CampusPool/carpooling/pull/45); its current `main` is **not** the application |
| **This repository — `CampusPool/carpooling-workspace`** | Runnable application source, package, test automation and teammate PRs |
| `prototype/` | The runnable package: modified upstream Carpoolear backend + CampusPool extension + integration patches + isolated reproduction scripts |
| `teammates/` | Individual task instructions; **not** separate app copies |
| `docs/ACADEMIC_TRACEABILITY.md` | Provisional requirements/evidence mapping; not a replacement for the team's submitted SRS/SAD/Test Plan |

Check actual GitHub repository **visibility/access** before sharing: older instructions call this repository private, but visibility is not guaranteed by that text.

## Run the exact demonstration (no paid maps key)

Prerequisites: Docker Desktop / Docker Compose, Git, Python 3, and available localhost port **18180**. Clone into a Docker-shared folder under your home directory (e.g. `~/Documents`); avoid `/tmp` on macOS, where bind mounts may appear empty.

```sh
git clone https://github.com/CampusPool/carpooling-workspace.git
cd carpooling-workspace/prototype
sh reproduction/start-local.sh
# Open http://127.0.0.1:18180/mobility/?demo=campus-route
python3 reproduction/smoke-booking.py
# When finished:
sh reproduction/stop-local.sh
```

The first run installs Composer dependencies in isolated containers and initializes a local MySQL volume; internet access and sufficient disk space are needed. The startup script creates **synthetic-only** configuration and does not require billing or a Maps API key. Do not run it against the main MobilityPlatform database. Do not run `migrate:fresh` on a real or shared database.

For a complete package/test verification, install Node.js too and run `sh reproduction/verify-package.sh` from `prototype/`; it uses a separate disposable stack. [Detailed setup and limitations](../prototype/reproduction/PORTABLE_DEMO.md).

### What to demonstrate to the teacher

Use two independent browser profiles or a normal + incognito window, since driver and rider sessions must not overwrite each other.

1. Open the demo as **Driver** and review the saved Yeshwanthpur-to-campus profile.
2. Select a future date and publish a car/bike offer, including the pickup arrangement.
3. Open a separate **Rider** session with compatible date/direction and search.
4. Review the ride summary, request a seat and switch to the driver to accept.
5. Show the resulting booking, cancel it and show the updated state/seat availability.
6. Explain that the database is **MySQL**, the route service is **synthetic straight-line geometry**, and demo credits are **not real money**.

**Current UI limitation:** without a Google Maps browser key, the map panel can show “Map access is unavailable.” This does not mean the booking API failed, but the teacher-facing UI needs a helpful text-only route fallback. Do **not** call the map live GPS navigation or road-accurate routing.

## Code map: what actually participates in the Sprint 1 flow?

| Layer | Entry files / directories | Responsibility | Origin |
|---|---|---|---|
| Browser frontend | `prototype/campuspool-extension/web/commute.html`, `commute.css`, `commute.mjs`; supporting `commute-map.mjs`, `place-search.mjs` | Driver/rider entry screens, forms, ride cards, request/accept/cancel UI | Imported project-specific extension; individual authorship not established by file import |
| HTTP routes | `prototype/campuspool-extension/routes/commute.php` | `/mobility/` web page and `/api/mobility/commute/...` endpoints | CampusPool extension |
| Backend API | `prototype/campuspool-extension/Mobility/Commute/CommuteController.php` | Input validation, API actions | CampusPool extension |
| Core business rules | `.../Mobility/Commute/CommuteService.php` and `CommuteMatchingService.php` | Profiles, offers, matching, booking requests, accept/reject/cancel, capacity/quote boundaries | CampusPool extension |
| Routing/pickup | `prototype/campuspool-extension/Mobility/Routing/`, `Mobility/Pickup/`; `prototype/reproduction/demo-router.py` | Route/pickup abstractions; deterministic **fake** geometry for portable demo | Extension + package-only demo utility |
| Persistent relational data | `prototype/database/migrations/` and `prototype/campuspool-extension/migrations/` | Existing Carpoolear trip/booking/user tables + new `mobility_commute_*` and related tables | Inherited schema + extension migrations |
| App glue | `prototype/integration-patches/` | Connects extension to upstream Laravel/Carpoolear app | Selected integration materials |
| Verification | `prototype/tests/extension/MobilityCommuteProductTest.php`, `prototype/tests/frontend/`, `prototype/reproduction/smoke-booking.py`, `.github/workflows/application.yml` | Focused backend/frontend tests and fresh-start publish→search→request→accept→cancel smoke | Imported/adapted tests and packaging/CI |

**Example relational evidence:** original `trips` and `trip_passengers` records are used by the booking flow; added tables include `mobility_commute_profiles`, `mobility_commute_demo_trips`, `mobility_commute_matches`, `mobility_commute_booking_terms`, and `mobility_commute_receipts`. The names and actual constraints must be explained from the migrations; do not invent an ERD.

## Source reuse, attribution and scope

- This is **not 1,585 files of original student-written application code**. At this baseline Git tree there were 1,585 tracked workspace files, including 1,572 package-inventoried files.
- `prototype/SOURCE_MAP.tsv` attributes **1,229** package files to unchanged upstream Carpoolear, **4** to modified upstream, **235** to imported CampusPool extension code, **56** to imported extension tests, **16** to integration materials, **3** to adapted tests and **29** to package-authored support.
- The upstream backend is [STS-Rosario/Carpoolear](https://github.com/STS-Rosario/carpoolear_backend), pinned to commit `6ba3d8913a8f77e664e848a526f196690a03c1e8` and licensed **GPL-3.0**. See `prototype/PROVENANCE.md`, `LICENSES.md`, and the per-file `SOURCE_MAP.tsv`.
- “Imported extension” is a **source classification, not proof that any specific student wrote it**. Each teammate must show their **actual reviewed additions** in their own PR. No rewriting commit history or making unsupported authorship claims.
- License permission does **not** by itself confirm faculty permission to submit an adapted open-source application for academic assessment. Get faculty approval and retain applicable notices.

## What works / what is not yet demonstrated?

**Demonstrated by automation:** synthetic demo sessions, profile setup, ride publication/search, seat request, driver acceptance and rider cancellation. The fresh-clone HTTP smoke passed on 23 Oct; [GitHub Actions on `main` also passed](https://github.com/CampusPool/carpooling-workspace/actions/runs/39857893823).

**Not claimable as completed Sprint 1 delivery:** real student login/ID verification; road-accurate routing and traffic; actual payment integration; reliable production dispatch or recurrence; fully reviewed teammate implementation PRs; the one-minute submitted video; final faculty-approved SRS/SAD/Test Plan reconciliation. The course-specific ERD/advanced SQL, MongoDB and Next.js work are planned next-phase tasks.

## Contribution and acceptance rule

Each member: (1) clones and starts this exact workspace, (2) points to their issue and owned code, (3) implements a **genuine** small improvement or regression test, (4) runs relevant tests, (5) opens a PR **in this repository** linking the [official issues](https://github.com/CampusPool/carpooling/issues), (6) gets a teammate review. Do **not** submit a PR that only re-adds or renames unchanged inherited code.

Current Sprint 1 focus: Arjun — booking/CI/docs; Zayaan — offer UI and graceful map failure; Nikhil — search/matching edge cases; Nithesh — cancellation and MySQL integrity. Equal-looking issue counts are not a substitute for balanced, testable contributions. Any issue reassignment is a proposal until reflected on the official board.

**Before the Sprint 1 submission:** capture a one-minute honest flow, correct outdated 18080/18180 and merged-branch references in official PR #45, and align the narrative with your submitted assessment documents.
