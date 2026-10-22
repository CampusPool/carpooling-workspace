# zayaan-06 — Sprint 1 task packet

**Focus:** Driver/rider interface, pickup consent and Maps failure states

**Official issues:** [#12](https://github.com/CampusPool/carpooling/issues/12), [#21](https://github.com/CampusPool/carpooling/issues/21)

**Branch:** `s1/zayaan-web-commute`

## Before you begin

Do not duplicate the project or copy the imported baseline into a personal subfolder. From the workspace repo root:

```sh
git fetch origin
git switch import/sprint1-attributed-sanitized  # until private PR #1 is reviewed and merged
git switch -c s1/zayaan-web-commute
```

After PR #1 is merged, start new work from updated `main` instead. If this feature branch was made earlier, rebase it carefully onto approved main.

## Existing code to inspect

The paths below exist in the shared root; directory ownership is advisory, not a reason to copy code.
- `prototype/campuspool-extension/web/commute.mjs`
- `prototype/campuspool-extension/web/commute-map.mjs`
- `prototype/campuspool-extension/web/commute.html`
- `prototype/campuspool-extension/web/commute.css`
- `prototype/tests/frontend/test-commute-ui.mjs`
- `prototype/tests/frontend/test-commute-map.mjs`

## Actual contribution required

1. Keep fare, pickup and walking consent readable if Maps fails.
2. Validate car/bike offer forms, errors, and pending/accepted/cancelled UI states.
3. Add genuine UI regression tests and attach sanitized screenshots for behavior changed.

## Verification

The full verifier creates and removes **its own** temporary Docker Compose database/volume and must never be pointed at the original running MobilityPlatform database.

```sh
cd prototype && node --test tests/frontend/test-commute-ui.mjs tests/frontend/test-commute-map.mjs tests/frontend/test-place-search.mjs
```

## PR handoff

- Submit a **real change**, not a commit containing only unchanged copied code.
- Suggested PR title: `fix: improve commute forms and map failure states`.
- Link the full official issue URLs and distinguish satisfied criteria from remaining ones.
- Supply tests run, test output and reproducible evidence.
- Never mark the entire issue Done if other acceptance criteria remain unfinished.
- Coordinate edits to shared controllers/routes/migrations with the responsible owner.
- Request an independent teammate review before merge.
