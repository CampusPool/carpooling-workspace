# Contributing

1. Wait for the approved, attributed application baseline. Do not copy the private prototype or reference artifacts without the owner's separate authorization.
2. Select your assigned [official issue](https://github.com/CampusPool/carpooling/issues). Branch from current `main`, for example `feature/17-route-matching`.
3. Make genuine changes within the agreed file ownership. Ask the integration coordinator before modifying shared routes, migrations, dependency locks or frontend entrypoints.
4. Use synthetic users and an isolated database. Never run `migrate:fresh`, destructive test suites or cleanup against the persistent demo MySQL database. Baseline-specific commands will accompany the approved distribution.
5. Open a focused PR here. Reference the full official issue URL; cross-repository links do not automatically close it. Include before/after behavior, exact test command and result, screenshots where relevant, and upstream provenance.
6. A different student reviews and verifies the change. Maintainers integrate only after approval and relevant checks. Documentation CI is not application CI; add real application checks when the baseline is authorized.
7. Reviewed integrated work moves to the official repository only through a separately reviewed integration PR. No direct pushes to official `main`, invented reviews, disguised authorship or copied contribution claims.

Arjun (`arjunsrikanthh`, also `noqtar`) coordinates integration; Zayaan (`zayaan-06`) owns UI integration; Nikhil (`NikhilJoshi441`) owns search/matching; Nithesh (`NitheshMogaveer777`) owns database integrity/lifecycle verification. These are responsibilities, not claims of prior authorship. Course-specific team eligibility and contributions require instructor confirmation.

Never commit credentials, personal records, private trip coordinates, DB dumps, reference-app artifacts or code whose redistribution obligations are unresolved. Retain original notices and separate upstream code from subsequent modifications.
