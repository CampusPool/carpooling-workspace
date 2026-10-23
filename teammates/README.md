# Sprint 1 teammate handoff

**Official SE + DBMS repository:** [CampusPool/carpooling](https://github.com/CampusPool/carpooling) (issues, sprint plan, final source and submission).  
**Working repository:** [CampusPool/carpooling-workspace](https://github.com/CampusPool/carpooling-workspace) (prototype and teammate PRs).

| Contributor | Sprint 1 issues | Story points | Instructions |
|---|---|---:|---|
| Arjun | [#23](https://github.com/CampusPool/carpooling/issues/23), [#39](https://github.com/CampusPool/carpooling/issues/39), [#44](https://github.com/CampusPool/carpooling/issues/44) | 11 | [Arjun](arjun/README.md) — booking, CI and evidence |
| Zayaan | [#12](https://github.com/CampusPool/carpooling/issues/12), [#21](https://github.com/CampusPool/carpooling/issues/21) | 10 | [Zayaan](zayaan/README.md) — offer UI and map fallback |
| Nikhil | [#22](https://github.com/CampusPool/carpooling/issues/22), [#43](https://github.com/CampusPool/carpooling/issues/43) | 10 | [Nikhil](nikhil/README.md) — matching and testing |
| Nithesh | [#24](https://github.com/CampusPool/carpooling/issues/24), [#37](https://github.com/CampusPool/carpooling/issues/37) | 10 | [Nithesh](nithesh/README.md) — cancellation and DB integrity |

## Workflow

1. Clone **the whole workspace repository**, not a `teammates/` subfolder. These folders contain task instructions, not separate applications.
2. Run the [portable demo](../prototype/reproduction/PORTABLE_DEMO.md) from `prototype/` and read your own task packet.
3. Create a personal `s1/...` branch from current workspace `main`. The old `import/sprint1-attributed-sanitized` branch was already merged.
4. Make and test a **real** code/test change in your assigned area. Commit only your actual files; do not recreate the imported baseline.
5. Push to `CampusPool/carpooling-workspace` and open a concise PR to its `main`, linking full issue URLs in the official repository.
6. Get a teammate review. Accepted code is later synchronized to `CampusPool/carpooling` for the official submission.

**Demo:** `cd prototype && sh reproduction/start-local.sh`, then `http://127.0.0.1:18180/mobility/?demo=campus-route`. It uses synthetic routing and demo credits, not live traffic or real money.

Upstream Carpoolear GPLv3 sources must remain attributed. Never claim inherited files as original student work, fabricate contribution history, or commit secrets, generated dependencies or database dumps. GitHub repository visibility is controlled by Settings, regardless of any “private” text in a description.
