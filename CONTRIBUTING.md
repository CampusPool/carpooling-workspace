# Contributing

1. Select the assigned issue in [CampusPool/carpooling](https://github.com/CampusPool/carpooling/issues).
2. Create a feature branch from `main`, using the branch listed in the sprint plan.
3. Keep changes within the assigned module. Coordinate edits to shared services, routes, migrations and dependency locks with the maintainer.
4. Run the relevant tests with synthetic fixtures and an isolated database. Do not run database resets against the demo installation.
5. Open a PR in this repository. Link the official issue, describe the change and include test results and UI screenshots where applicable.
6. Obtain review from another team member before merging. Integrate approved work into the official repository through a separate PR.

Use full issue URLs for cross-repository references. Keep credentials, personal data, database dumps and reference captures out of commits. Retain third-party license notices and attribution. Plain SQL migrations and assessed queries belong in source control; database backups do not.

Module ownership and integration order are maintained in the [sprint plan](https://github.com/CampusPool/carpooling/blob/docs/se-dbms-backlog-2026-10-12/docs/SPRINT_PLAN.md).
