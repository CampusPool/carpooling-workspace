# CampusPool Sprint 1 source package

This local package is a reproducible source bundle for review. It contains the
pinned Carpoolear backend at revision `6ba3d8913a8f77e664e848a526f196690a03c1e8`,
the CampusPool extension, and explicitly attributed integration patches. The
upstream tree was exported from a clean checkout; Composer dependencies are
installed during reproduction and are not bundled.

The package is for local evaluation only. It does not include credentials,
database dumps, imported `.git` history, `.jarvis` state, raw reference-app captures or
the upstream press archive. Review `PROVENANCE.md`, `LICENSES.md` and
`MANIFEST.sha256` before any distribution decision.

## Reproduce

Clone the local sanitized baseline into a directory shared with Docker (for example under the user home directory). Docker Compose, Git, Python 3 and Node.js are required. From the clone root:

```sh
sh reproduction/verify-package.sh
```

The script creates a unique Compose project and database volume, validates the
source and patch manifest, runs Composer installation and the focused static
checks, boots the campus UI inside the isolated stack, verifies
`/mobility/` returns HTTP 200, runs the 10-case campus
workflow suite (208 assertions) against a disposable `testing` schema, and
then removes the temporary project and volume. The verifier itself does not
publish a host port. It never targets the MobilityPlatform checkout or an
existing database.

For an interactive local run, use the separate startup helper:

```sh
sh reproduction/start-local.sh  # waits for the page to respond
# open http://127.0.0.1:18180/mobility/
sh reproduction/stop-local.sh  # preserves local configuration and database volume
```

The interactive stack starts the UI, a synthetic routing service and synthetic account database. It exercises the booking workflow but **does not provide road-accurate routing**. To configure providers, copy `config/.env.example` to the ignored `reproduction/package.env` and edit it before starting; the helper preserves this file. Run verification from a separate clean clone.

The default interactive startup uses a bundled **synthetic straight-line routing service**; the browser
UI has no bundled road graph or provider key. The `.env.example` contains no external OSRM endpoint,
so live/road-accurate interactive search requires separately reviewed
`MOBILITY_ROUTING_PROVIDER=osrm`, per-mode `MOBILITY_ROUTING_{CAR,BIKE,FOOT}_PROFILE`,
`MOBILITY_ROUTING_{CAR,BIKE,FOOT}_BASE_URL` endpoints and matching
`MOBILITY_ROUTING_{CAR,BIKE,FOOT}_EXPECTED_DATA_VERSION` pins, plus any
separately approved Maps browser key.

This local baseline verifies the campus workflow with test fixtures. Student authentication, live routing and payment collection require further integration.

## License and source attribution

This project is a modified GPL-3.0 Carpoolear backend with project-specific
campus commute extensions. Upstream authors and source revisions are documented
in `PROVENANCE.md`; licensing and omitted media are explained in `LICENSES.md`.
Do not present the backend as a wholly original student implementation.
