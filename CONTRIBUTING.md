# Contributing to CampusPool

**Where to work:** [CampusPool/carpooling-workspace](https://github.com/CampusPool/carpooling-workspace) contains the runnable baseline and is the destination for teammate feature PRs. [CampusPool/carpooling](https://github.com/CampusPool/carpooling) contains the official issues, sprint board and final assessed source. Read [the main README](README.md) and your [role-specific task sheet](teammates/README.md) before changing code.

## Workflow

1. Clone the **whole** workspace, run `cd prototype && sh reproduction/start-local.sh`, verify the `18180` demo with `python3 reproduction/smoke-booking.py`, and stop it with `sh reproduction/stop-local.sh`.
2. Find your assigned issue in [the official repository](https://github.com/CampusPool/carpooling/issues) and read its acceptance criteria. Branch from the latest **workspace `main`**, using your assigned `s1/...` name in [the sprint plan](https://github.com/CampusPool/carpooling/blob/main/docs/SPRINT_PLAN.md). Do not revive the already-merged `import/sprint1-attributed-sanitized` branch.
3. Make an identifiable **original code or test change** within your assigned module. Coordinate shared routes, `CommuteService.php`, migrations and dependency locks before editing them.
4. Run the relevant tests. For full package verification, use `cd prototype && sh reproduction/verify-package.sh` **in a separate clean clone**; it uses disposable Docker dependencies, creates an isolated database and removes generated files on completion. Do not run destructive database commands on the interactive demo or original developer pilot.
5. Stage only files you actually edited (`git add -p` for tracked files, explicit `git add path/to/new-file` for new files). Review `git diff --cached --stat`, commit a precise message and push your personal branch.
6. Open a concise PR **into `CampusPool/carpooling-workspace:main`**, using full URLs to the official issues. Describe: **summary, linked issue, actual test commands and results, and known limitations**. Include a sanitized screenshot if relevant.
7. Request review from another member; don't merge red CI. The accepted change is synchronized to the official repo separately once its attributed baseline is integrated. Do not claim third-party code, historical extension work or other teammates' changes as your own.

## Responsibilities and reviews

| Contributor | GitHub | Issue numbers | Branch |
|---|---|---|---|
| Arjun | `arjunsrikanthh` / `noqtar` | #23, #39, #44 | `s1/arjun-booking-release` |
| Zayaan | `zayaan-06` | #12, #21 | `s1/zayaan-web-commute` |
| Nikhil | `NikhilJoshi441` | #22, #43 | `s1/nikhil-matching` |
| Nithesh | `NitheshMogaveer777` | #24, #37 | `s1/nithesh-integrity` |

These are planned assignments, **not completed PRs**. Reviewers should verify scope, affected tests, attribution and that the submitted code can be explained by its author.

## Safe repository practices

- Never commit API keys, OTPs, personal identity details, customer records, browser captures, database dumps, local `.env`, `vendor/`, `node_modules/`, or unreviewed production credentials.
- Retain upstream Carpoolear GPLv3 attribution and license files, and use `prototype/PROVENANCE.md` and `prototype/SOURCE_MAP.tsv` to separate reused foundations from student changes.
- Do not claim synthetic routing, fares or demo credits are live Google routing, payments or production identity verification.
- Coordinate intentional changes to inventoried package files with their integrity manifest; don't disable the manifest check or paper over test failures.
- The official SRS, SAD, Test Plan and DBMS requirements remain the assessment baseline. Record deviations instead of quietly changing scope.
- Repository visibility is determined by GitHub settings; the word "private" in a README or repository description does not make it private.

See [the root README](README.md#7-troubleshooting-and-safe-cleanup) for troubleshooting and [the official Sprint 1 plan](https://github.com/CampusPool/carpooling/blob/main/docs/SPRINT_PLAN.md) for acceptance tests and deadlines.
