# CampusPool — team development workspace\n\n> **CURRENT TEAM WORKFLOW — 25 OCTOBER 2026:** The canonical, CI-repaired source is [CampusPool/carpooling](https://github.com/CampusPool/carpooling), following baseline PR #46 and repair PR #52. **Clone that official repository, branch from its main, and submit individual PRs there.** This workspace is an earlier reference copy and may be behind official main. [Updated individual task sheets](teammates/README.md). The older workspace-clone/PR instructions further down this README are historical and superseded.\n

**Campus carpooling / bikepooling prototype for the SE + DBMS coursework at PES University.**

This is the **shared development and code-review repository**. It already contains a runnable, attributed Laravel/MySQL prototype under `prototype/`. Teammates **clone the entire repository** and contribute focused code and tests; the `teammates/` folders contain *instructions*, not independent apps.

> **Official submission, issues and project board:** [CampusPool/carpooling](https://github.com/CampusPool/carpooling) · [Sprint board](https://github.com/orgs/CampusPool/projects/2) · [Official sprint plan](https://github.com/CampusPool/carpooling/blob/main/docs/SPRINT_PLAN.md).
>
> **Shared coding workspace (this repo):** [CampusPool/carpooling-workspace](https://github.com/CampusPool/carpooling-workspace). Open teammate feature PRs **here**, targeting `main`; reviewed contributions will be carried into the official repository. The baseline import is currently [official draft PR #46](https://github.com/CampusPool/carpooling/pull/46); **do not claim that draft as already merged or as four separate student contributions**.

## 1. What are we building?

The Sprint 1 deliverable is a **working campus commuter ride-sharing prototype**, with a driver and a rider using a browser interface. It demonstrates:

- Driver creates a car/bike ride offer for a date/direction; rider searches for a compatible offer.
- Rider requests a seat; the driver accepts; the booking changes state.
- Booking cancellation is recorded and capacity can be restored.
- A map/route and pickup details are shown where supported, with synthetic demo users and test fixtures.
- Laravel routes/services, MySQL migrations, a browser frontend and automated tests are included.

**Not implemented or not proven production-ready:** real PES email/student-ID verification and OTP, real road routing/live traffic/GPS, payment collection or settlement, a real wallet, production deployment/security, and a working waitlist. Displayed fares/credits and default routes are **synthetic**. The browser demo is **not** evidence that Google Maps routing or live traffic works in a fresh clone.

The local `http://127.0.0.1:18080/mobility/?demo=campus-route` sometimes shown in screenshots is the **original developer pilot**, with its own configuration and database. **The portable prototype in this repository runs on port 18180** and has a separate synthetic database. The interface shares code, but those two running instances do not automatically share rides, map keys or state.

**Scope:** Sprint 1, 19–23 October 2026 — core ride flow, focused tests and a one-minute demo video. Sprint 2, 26–30 October 2026 — remaining course requirements, stronger authentication, DBMS evidence, documentation reconciliation and the two-minute video. Use the official issues for acceptance criteria; do not treat unimplemented stretch features as completed.

## 2. Start here — fresh clone and run

**Prerequisites:** Git, Docker with the **Docker Compose** plugin, Python 3, Node.js, a working Docker engine, enough disk/memory for PHP/Composer and MySQL containers, and a free local port **18180**. macOS/Linux with Docker is the established workflow. On Windows, use a suitable Unix-compatible shell (for example WSL2) and Docker Desktop; Windows-specific reproduction has **not** been independently verified. Docker will download images and Composer dependencies on the first run.

```sh
git clone https://github.com/CampusPool/carpooling-workspace.git
cd carpooling-workspace
git switch main
git pull --ff-only origin main

# Check the prerequisites before launching
docker info
docker compose version
python3 --version
node --version

cd prototype
sh reproduction/start-local.sh
```

**Open:** [http://127.0.0.1:18180/mobility/?demo=campus-route](http://127.0.0.1:18180/mobility/?demo=campus-route)

Choose the **driver** and **rider** demo entries. Use two different browser profiles/private sessions when testing both identities so they do not overwrite one another's session. In a fresh profile, complete the demo onboarding and choose sample Bengaluru coordinates. Select a **future** departure date and compatible direction, publish as driver, search and request as rider, accept as driver, and observe the rider booking.

From the `prototype/` directory, check the real API journey:

```sh
python3 reproduction/smoke-booking.py
# Success marker: PORTABLE_INTERACTIVE_BOOKING_PASS
sh reproduction/stop-local.sh
cd ..
```

The smoke script **writes synthetic bookings** to the disposable demo database. Run it only against your own local demo; don't use a production URL. `stop-local.sh` stops containers but **preserves the local configuration and database volume** so an existing demo can be resumed. Don't delete volumes or reset a teammate's installation.

See [portable demo instructions](prototype/reproduction/PORTABLE_DEMO.md) for a detailed driver/rider walkthrough. The original `18080` pilot is **not required** for teammates.

## 3. How to verify code and tests

**Frontend tests** — from `prototype/`:

```sh
node --test tests/frontend/test-commute-ui.mjs tests/frontend/test-commute-map.mjs tests/frontend/test-place-search.mjs campuspool-extension/web/driver-onboarding-ui.test.mjs campuspool-extension/web/tracking-state.test.mjs
```

**Full portable package check** — run **from a fresh/isolated clone**, not at the same time as an active local demo in the same working tree:

```sh
cd prototype
sh reproduction/verify-package.sh
```

This verifies the file manifest, runs Node tests, installs Composer dependencies in Docker, migrates an **isolated MySQL** instance, checks Laravel HTTP/health and runs focused backend tests. It uses a uniquely named disposable Docker project and **removes generated runtime files and its test database volume afterward**. The package verifier must never be pointed at the original developer pilot or any real database. Do not assume a green focused suite means every third-party/backend test or production integration works.

A CI workflow at [`.github/workflows/application.yml`](.github/workflows/application.yml) runs package verification and a fresh-start synthetic booking smoke test. A pull request is **not ready to merge if these checks are red**. The official PR #46 had a GitHub Actions MySQL-startup race; the fix makes readiness require an actual SQL query over TCP instead of `mysqladmin ping`. Check the **current PR run**, not an older successful run, before reporting its status.

If only your module is affected, run its focused test(s) as well, then include the **actual** command, pass/fail result and limitations in your PR.

## 4. Sprint 1 ownership — what EACH contributor must do

Assignments below correspond to the **official issues**, and the story points describe **planned workload**, not completed work or authored line count.

| Member / GitHub | Official issues | Points | Expected independent contribution | Branch / instructions |
|---|---|---:|---|---|
| **Arjun** `@arjunsrikanthh` (also `@noqtar`) | [#23](https://github.com/CampusPool/carpooling/issues/23) booking · [#39](https://github.com/CampusPool/carpooling/issues/39) CI · [#44](https://github.com/CampusPool/carpooling/issues/44) demo/docs | **11** | Authorization/capacity regression tests, booking correctness, CI/release evidence and one-minute video. Coordinate shared booking service edits. | `s1/arjun-booking-release` · [read task sheet](teammates/arjun/README.md) |
| **Zayaan** `@zayaan-06` | [#12](https://github.com/CampusPool/carpooling/issues/12) driver details · [#21](https://github.com/CampusPool/carpooling/issues/21) offer publishing | **10** | Driver/vehicle and car/bike offer UI validations, readable map-error/fallback states, responsive screenshot and frontend tests. | `s1/zayaan-web-commute` · [read task sheet](teammates/zayaan/README.md) |
| **Nikhil** `@NikhilJoshi441` | [#22](https://github.com/CampusPool/carpooling/issues/22) ride search · [#43](https://github.com/CampusPool/carpooling/issues/43) test plan | **10** | Date/direction/capacity/vehicle matching boundaries, stale quote/provider failure tests, results mapped to the approved Test Plan. | `s1/nikhil-matching` · [read task sheet](teammates/nikhil/README.md) |
| **Nithesh** `@NitheshMogaveer777` | [#24](https://github.com/CampusPool/carpooling/issues/24) cancellation (waitlist remains unproven) · [#37](https://github.com/CampusPool/carpooling/issues/37) DB integrity | **10** | Idempotent cancellation, capacity restoration, migration/SQL constraint evidence, test cases for duplicate side effects. | `s1/nithesh-integrity` · [read task sheet](teammates/nithesh/README.md) |

For acceptance cases, exact files and member-specific test commands, read your linked **individual task sheet** and the [official Sprint 1 plan](https://github.com/CampusPool/carpooling/blob/main/docs/SPRINT_PLAN.md#contributor-acceptance-and-pr-scope). All nine Sprint 1 issues are tracked in the official repo; don't close them merely because a baseline implementation was imported. A teammate must personally implement, test and explain the contribution attributed to them.

**Edit ownership to avoid Git conflicts:**

- Arjun: `prototype/campuspool-extension/Mobility/Commute/CommuteService.php`, controller and booking routes; CI/docs/release.
- Zayaan: `prototype/campuspool-extension/web/` browser UI and relevant frontend test files.
- Nikhil: `prototype/campuspool-extension/Mobility/Commute/CommuteMatchingService.php` and related matching/pickup/routing tests.
- Nithesh: `prototype/campuspool-extension/migrations/` plus cancellation/DB integrity tests. Coordinate shared `CommuteService.php` edits with Arjun rather than editing simultaneously.

## 5. How to make your OWN pull request

The `main` branch already includes the imported baseline. **Do not create separate apps, copy 1,500 files into a personal folder or re-import the third-party backend.**

From the repository root, **after updating `main` and without losing local work**:

```sh
git switch main
git pull --ff-only origin main
git switch -c s1/your-assigned-branch

# Edit only your owned code and test files, then run relevant checks.
git status --short
git diff --check
git add -p                   # selectively stage tracked modifications
git add path/to/new-test     # stage new files you actually created
git diff --cached --stat
git commit -m "test: cover a specific ride-booking boundary"
git push -u origin s1/your-assigned-branch
```

Replace the branch and commit example with your **assigned branch and actual change**. If you already have a branch, continue it rather than running `git switch -c` again.

Open a PR **from your branch into `CampusPool/carpooling-workspace:main`**. Use a short professional PR description:

```text
Summary: What behavior changed, and why?
Issue: https://github.com/CampusPool/carpooling/issues/NUMBER
Tests: Exact commands and outcomes; any known limitations.
```

Request **one other teammate's review**. Fix review comments and CI before merge. Merged workspace changes are then integrated into [the official repository](https://github.com/CampusPool/carpooling); coordinate that sync with Arjun so PR #46's imported baseline is not duplicated. **One meaningful code/test PR per member is more valuable than equal-looking commit counts.** Do not rewrite author history, invent contributions or claim passing tests not run.

Do not force-push `main`, commit environment secrets, API keys, student records, database dumps, generated `vendor/`, `node_modules/` or screenshots containing personal data. Coordinate migrations and shared files. See [CONTRIBUTING.md](CONTRIBUTING.md) for the review rules.

## 6. Source layout and reuse disclosure

```text
carpooling-workspace/
├── README.md                    this onboarding guide
├── CONTRIBUTING.md              contribution and review policy
├── teammates/                   role-specific instruction READMEs (not standalone apps)
├── .github/workflows/            application and documentation checks
└── prototype/
    ├── app/, routes/, database/  pinned upstream Carpoolear Laravel backend
    ├── campuspool-extension/
    │   ├── Mobility/            custom commute, booking, matching and other services
    │   ├── web/                 browser UI, map and frontend tests
    │   ├── migrations/          campus data schema changes
    │   └── tests/               extension test material
    ├── tests/                    backend/frontend tests
    ├── integration-patches/      explicit legacy-to-extension integration changes
    ├── reproduction/            Docker, startup, verifier and demo scripts
    ├── scripts/                 source-integrity helpers
    ├── PROVENANCE.md             origin and authorship disclosures
    ├── LICENSES.md              licensing and distribution notes
    ├── SOURCE_MAP.tsv            file-by-file source classification
    └── MANIFEST.sha256           source integrity hashes
```

The pinned backend is from [`STS-Rosario/carpoolear_backend`](https://github.com/STS-Rosario/carpoolear_backend) and retains upstream GPLv3 notices. The CampusPool extension was imported from earlier MobilityPlatform development; its historical **individual** authorship is not yet verified. The ~1,500-file baseline is **not** four new student PRs and must not be presented as wholly original coursework.

Review [`prototype/PROVENANCE.md`](prototype/PROVENANCE.md), [`prototype/LICENSES.md`](prototype/LICENSES.md) and the [official distribution notes](https://github.com/CampusPool/carpooling/blob/main/docs/PROTOTYPE_DISTRIBUTION.md) before assessed submission or outside distribution. Confirm faculty reuse/originality acceptance and any applicable licensing requirements; GitHub repository visibility does not determine licensing.

## 7. Troubleshooting and safe cleanup

| Problem | First check |
|---|---|
| Docker daemon or Compose unavailable | Start Docker Desktop/Colima, then `docker info` and `docker compose version`. |
| Port 18180 occupied | Stop only the local service using that port, or coordinate a reviewed port override; don't kill unrelated processes. |
| First startup slow | Composer and Docker images may need downloading. Follow the actual console output rather than assuming success. |
| MySQL migration fails | Inspect the **migrate and mysql container logs** and confirm the health check requires a successful TCP SQL query; don't clear volumes blindly. |
| Map missing or wrong | Fresh clones use synthetic routing; the Google Maps browser key is **not bundled**. Use available text/manual-coordinate fallback. |
| No matching rides | Check future date, outbound/return direction, vehicle type, published status and compatible pickup choices. |
| Rider and driver appear the same | Use separate browser profiles/sessions, not two tabs sharing one login state. |
| Source manifest mismatch | Do not blindly rewrite hashes. Review changes against `SOURCE_MAP.tsv`; intentional changes require an updated manifest before package verification. |
| Local services left running | From `prototype/` run `sh reproduction/stop-local.sh`. It preserves the database volume; do not use `docker compose down -v` on somebody else's work. |

If something still fails, capture the **exact command, relevant error lines and branch/commit hash** in your PR or issue. Do not paste credentials or real personal information.

## 8. Sprint 1 completion checklist

- [ ] Each of the **four members** clones and starts the independent `18180` prototype successfully.
- [ ] Each member makes and tests a genuine change, submits a scoped PR, and receives teammate review.
- [ ] Accepted member PRs are integrated into the official submission repo without re-crediting upstream files.
- [ ] CI and the synthetic driver → rider → accept → cancel smoke test pass on the final integrated commit.
- [ ] The approved Test Plan, SRS/SAD deviations and DBMS schema evidence are accurately explained, without calling missing functionality complete.
- [ ] The **one-minute Sprint 1 video** is recorded and submitted with the actual prototype and known limitations.

The checklist records what must be verified, **not a claim that everything is complete**. For Sprint 2 sequencing, [read the official plan](https://github.com/CampusPool/carpooling/blob/main/docs/SPRINT_PLAN.md#sprint-2-delivery-order).
