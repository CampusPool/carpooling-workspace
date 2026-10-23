# CampusPool development workspace

This repository contains the runnable, GPLv3 Carpoolear-derived CampusPool prototype, CampusPool-specific extension and team-review workflow. **Do not claim the entire upstream backend as original student code.**

**[Start here: Sprint 1 prototype, code ownership and demo](docs/SPRINT1_START_HERE.md)**

- [Official repository and issues](https://github.com/CampusPool/carpooling)
- [Project board](https://github.com/orgs/CampusPool/projects/2)
- [Sprint plan and module assignments](https://github.com/CampusPool/carpooling/blob/docs/se-dbms-backlog-2026-10-12/docs/SPRINT_PLAN.md)
- [Contribution guide](CONTRIBUTING.md)
- [Exact source provenance and licenses](prototype/PROVENANCE.md)

Sprint 1: 19–23 October 2026. Sprint 2: 26–30 October 2026.

## Run the local synthetic demo

From the clone root:

```sh
cd prototype
sh reproduction/start-local.sh
# Open http://127.0.0.1:18180/mobility/?demo=campus-route
python3 reproduction/smoke-booking.py
sh reproduction/stop-local.sh
```

Requires Docker Compose and Python 3; full verification also requires Node.js. Demo routes are **synthetic straight lines**, not live road routing. No real payments or student identity checks are performed. See [portable demo instructions](prototype/reproduction/PORTABLE_DEMO.md) and [teacher-facing limitations](docs/SPRINT1_START_HERE.md).
