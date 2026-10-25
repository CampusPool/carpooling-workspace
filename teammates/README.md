# Sprint 1 teammate handoff

**Official SE + DBMS repository:** [CampusPool/carpooling](https://github.com/CampusPool/carpooling) (issues, sprint plan, final source and submission).  
**Reference workspace:** [CampusPool/carpooling-workspace](https://github.com/CampusPool/carpooling-workspace) (historical prototype and task sheets; do not open new feature PRs here).

| Contributor | Sprint 1 issues | Story points | Instructions |
|---|---|---:|---|
| Arjun | [#23](https://github.com/CampusPool/carpooling/issues/23), [#39](https://github.com/CampusPool/carpooling/issues/39), [#44](https://github.com/CampusPool/carpooling/issues/44) | 11 | [Arjun](arjun/README.md) — booking, CI and evidence |
| Zayaan | [#12](https://github.com/CampusPool/carpooling/issues/12), [#21](https://github.com/CampusPool/carpooling/issues/21) | 10 | [Zayaan](zayaan/README.md) — offer UI and map fallback |
| Nikhil | [#22](https://github.com/CampusPool/carpooling/issues/22), [#43](https://github.com/CampusPool/carpooling/issues/43) | 10 | [Nikhil](nikhil/README.md) — matching and testing |
| Nithesh | [#24](https://github.com/CampusPool/carpooling/issues/24), [#37](https://github.com/CampusPool/carpooling/issues/37) | 10 | [Nithesh](nithesh/README.md) — cancellation and DB integrity |

## Workflow — official source of truth (25 October 2026)

1. Clone **the official repository** (not this workspace or an instruction subfolder):

   ```sh
   git clone https://github.com/CampusPool/carpooling.git
   cd carpooling
   git switch main
   git pull --ff-only origin main
   cd prototype
   sh reproduction/start-local.sh
   python3 reproduction/smoke-booking.py
   sh reproduction/stop-local.sh
   cd ..
   ```
2. Create your assigned `s1/...` branch from the updated **official `main`**; never commit directly to `main`.
3. Write and test **your actual** assigned code and/or regression tests. Imported Carpoolear files, previous extension code and central CI repairs are baseline, not individual contributions.
4. Update package `SOURCE_MAP.tsv` entries for intentionally changed files, regenerate `MANIFEST.sha256` with `cd prototype && python3 scripts/check-package.py --write`, then verify using `python3 scripts/check-package.py` and relevant tests. Do not disable integrity checks.
5. Push your branch to **CampusPool/carpooling** and open a concise PR targeting **`CampusPool/carpooling:main`**, linked to your official issues. Include exact test results and request a teammate review.
6. The shared workspace remains a reference to early prototype development; it is **not** the feature PR destination after official baseline PR #46 and central CI repair PR #52.

**Demo:** `cd prototype && sh reproduction/start-local.sh`, then `http://127.0.0.1:18180/mobility/?demo=campus-route`. It uses synthetic routing and demo credits, not live traffic or real money.

Upstream Carpoolear GPLv3 sources must remain attributed. Never claim inherited files as original student work, fabricate contribution history, or commit secrets, generated dependencies or database dumps. GitHub repository visibility is controlled by Settings, regardless of any “private” text in a description.
