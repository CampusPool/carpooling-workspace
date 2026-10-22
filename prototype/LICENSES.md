# Licenses and notices (CampusPool Sprint 1 review)

## Primary program license — GNU GPL version 3

The pinned upstream [Carpoolear backend](https://github.com/STS-Rosario/carpoolear_backend), revision
`6ba3d8913a8f77e664e848a526f196690a03c1e8`, explicitly identifies its
backend license as **GPL-3.0** in its README. Its root `LICENSE` contains
GNU GPL version 3; that license is preserved verbatim in this package.

The `MIT` field in the upstream `composer.json` describes the
`laravel/laravel` skeleton metadata and must **not** be used to relicense
Carpoolear or this combined derivative work. It is preserved solely to
avoid altering upstream dependency metadata without separately testing that change.

Modifications and project-specific extension/integration source intended for
distribution as part of this combined Carpoolear-derived program must be
GPLv3-compatible and carry notices of changes. The user maintaining this
prototype should confirm they control the rights to their contributions; no
rights are claimed over upstream or unknown third-party contributions.

A private GitHub repository limits repository visibility, but does not negate
GPLv3 recipients' rights to copy, modify and redistribute GPL-covered code.

## Deliberately excluded images, marks and fonts

Historical Carpoolear photos, event posters, organization logos, personal
headshots, upstream site artwork, third-party fonts and the upstream test image
were removed from this campus-only review source to avoid making unsupported
redistribution assumptions. As a consequence, unsupported legacy promotional
pages may show missing images; those pages are not part of the campus MVP.
The CampusPool commute UI and its isolated tests do not depend on those assets.

## Other dependencies

`LICENSE-INVENTORY.md` enumerates the 142 Composer lock entries. Each fetched
dependency retains its own terms; the inventory is not a legal compatibility
opinion. Node frontend harnesses use built-in APIs and do not require installing
npm packages. Real maps/routing provider keys and road graph data are not included.

## Academic use

The code is not represented as wholly original. The faculty course's
originality/reuse permission and the actual student contribution records
are separate academic requirements and are not certified by a software license.
