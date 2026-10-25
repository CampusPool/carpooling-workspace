# CampusPool — historical development workspace

This repository holds the **earlier development copy** of the CampusPool carpooling prototype, plus initial setup and team task notes. It is retained for source history and reference.

## Where should the team work now?

**Use [CampusPool/carpooling](https://github.com/CampusPool/carpooling) — the official SE + DBMS repository — for all new code, tests and PRs.**

| Purpose | Repository |
| --- | --- |
| Current runnable prototype and automated tests | [Official `carpooling/prototype/`](https://github.com/CampusPool/carpooling/tree/main/prototype) |
| Official code submissions and teammate PRs | [`CampusPool/carpooling`](https://github.com/CampusPool/carpooling/pulls) |
| Official tasks, project plan and issues | [`CampusPool/carpooling` issues](https://github.com/CampusPool/carpooling/issues) |
| Older source snapshots, distribution history and task sheets | **This workspace** |

**Do not clone this older workspace as the starting point for new Sprint work.** Its source is not automatically synchronized with official `main`; later matching changes and shared CI repairs exist in the official repository.

## Starting from the current official code

```sh
git clone https://github.com/CampusPool/carpooling.git
cd carpooling
git switch main
git pull --ff-only origin main
git switch -c s1/your-assigned-branch
cd prototype
sh reproduction/start-local.sh
python3 reproduction/smoke-booking.py
sh reproduction/stop-local.sh
```

The synthetic browser demo is at `http://127.0.0.1:18180/mobility/?demo=campus-route`. It does not demonstrate real payments or live routing.

Make and test a genuine change on your own branch, update the package source inventory/manifest if files changed, and open a **PR against official `CampusPool/carpooling:main`** with an issue link and actual test results. Do not push directly to `main`.

## Historical resources retained here

- [Teammate assignment sheets](teammates/README.md)
- [Original prototype package](prototype/)
- [Early project documentation](docs/)
- [Original contribution guidance](CONTRIBUTING.md)

The old source and all prior versions of this README remain available through Git history. The primary project documentation and source attribution are in the **official repository**.
