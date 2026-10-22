# CampusPool — Academic specification reconciliation

**Source-of-truth limitation:** The team’s *submitted* SRS, SAD and Test Plan
were not present in the inspected Mac project, official repository or project
Library search. Available materials include their university templates, the
SE/DBMS planning backlog and the current course deliverables. This is a
**provisional traceability matrix**; it does not replace submitted documents
or imply faculty approval.

| Requirement / evidence area | Current repository evidence | State / remaining work |
|---|---|---|
| Campus ride offers and search | `prototype/campuspool-extension/Mobility/Commute/CommuteMatchingService.php`, `web/commute.mjs` | Synthetic demo working under isolated fixtures; verify real routing separately |
| Booking lifecycle | `CommuteService.php`, `tests/extension/MobilityCommuteProductTest.php` | Focused tests cover request/accept/cancel; concurrency and production identity not completed |
| Pickup walking and route choices | `Mobility/Journeys/`, `Mobility/Pickup/` | Synthetic geometry in teammate demo is **not a real road route** |
| Secure student access and campus identity | `routes/college-auth.php` and backlog #8/#9/#10/#38 | Demo actors, not production student verification |
| MySQL relational authority | `prototype/database/migrations/` and `campuspool-extension/migrations/` | Present; final course ERD/data dictionary and advanced SQL demos remain |
| MongoDB / NoSQL | Official issue #40 | **Missing**, mandatory DBMS deliverable for Sprint 2 |
| Next.js interface | Official issue #42 | **Missing**, mandatory DBMS deliverable for Sprint 2 |
| GitHub collaboration | `teammates/`, private PR #1, `.github/workflows/` | Baseline import done; actual individual feature PRs/reviews pending |
| Test Plan mapping | `prototype/tests/extension/`, `prototype/tests/frontend/` | Isolated suites; original submitted Test Plan not yet mapped |
| Sprint 1 video | Official issue #44 | **Pending recording and upload** |
| Originality and third-party attribution | `prototype/PROVENANCE.md`, `prototype/LICENSES.md` | Carpoolear-derived GPLv3 backend disclosed; faculty originality acceptance unconfirmed |

## Required reconciliation when team supplies the submissions

1. Obtain the precise SRS, SAD and Test Plan *as submitted* (not blank templates).
2. Link every FR/NFR and test-case identifier to a current issue, schema/API,
   test and demonstration proof; preserve actual original wording.
3. Record deviations with the approval date and reviewer, without silently
   deleting requirements.
4. Make both DBMS students' real frontend/backend and database contributions
   explicit.
5. Update official PR #45 and its docs only through a separate reviewed
   documentation change; this workspace source PR does not alter PR #45.
