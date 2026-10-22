# Provenance

| Area | Source | Status |
|---|---|---|
| Backend | `STS-Rosario/carpoolear_backend`, commit `6ba3d8913a8f77e664e848a526f196690a03c1e8` | upstream export |
| Extension | `backend/extensions/carpoolear` from private MobilityPlatform revision `faccb66ebb65512cfca41116109609a7120111de` | authored extension; attribution to current workspace owner is unverified |
| Integration | `backend/integrations/carpoolear/*.patch` and `MobilityIntegration/` | authored integration materials; attribution to current workspace owner is unverified |
| Test/runtime support | focused extension tests, probes and package reproduction scripts | authored support material; attribution to current workspace owner is unverified |

The pinned vendor checkout was inspected before packaging. Its tracked working
tree was clean. The separate hardened evaluation checkout contained unrelated
working changes and generated lock files; it was not copied. Only the named
patch files and extension paths were selected.

No Git history or author claims are included. `MANIFEST.sha256` is the exact
file inventory for this package, and `SOURCE_MAP.tsv` maps every inventory
entry to its pinned upstream source, an explicitly selected extension or
integration path, or package-only support. Private distribution may proceed only after reviewing this exact revised source set,
confirming the rights to project-specific additions, and documenting any academic
reuse approval still required. No claim of original authorship of Carpoolear is made.


The upstream `package.json` declares the dev dependencies `axios`,
`laravel-vite-plugin` and `vite`, but the package contains no npm lockfile.
The included frontend checks use Node built-ins and do not require npm install
or a frontend build. This is a repository-state record, not a claim about all
upstream browser pages.

The upstream deployment Compose file and deployment shell scripts were omitted
because they bind host port 3306 and contain environment-specific development
passwords. `reproduction/compose.yml` is the only runnable package stack and
uses a unique disposable database volume with no published ports in the
verifier. The optional `compose.local.yml` override publishes only
`127.0.0.1:18180` for interactive local review.

## Asset and modification disclosure (23 October 2026)

This review candidate excludes the legacy upstream image, logo, photograph,
poster and font directories. These are not inputs to the campus demo UI.
The code remains a Carpoolear-derived work and must retain the GPLv3 notices.
The project-specific extension was imported from the user-maintained
MobilityPlatform revision; historical individual authorship is not verified.
