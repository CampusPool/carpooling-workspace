#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$root"
command -v git >/dev/null
command -v python3 >/dev/null
command -v node >/dev/null || { echo 'Node.js is required for frontend verification.' >&2; exit 2; }
test -f composer.json
test -f LICENSE
test -f campuspool-extension/migrations/2026_10_17_000000_create_mobility_lifecycle_tables.php
test -f integration-patches/lifecycle-integration.patch
test -f integration-patches/critical-invariants.patch
python3 scripts/check-package.py
node --test tests/frontend/test-commute-ui.mjs tests/frontend/test-commute-map.mjs tests/frontend/test-place-search.mjs campuspool-extension/web/driver-onboarding-ui.test.mjs campuspool-extension/web/tracking-state.test.mjs
project="campuspool-sprint1-$(date +%s)-$$"
export COMPOSE_PROJECT_NAME="$project"
cp config/.env.example reproduction/package.env
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/suburbs storage/geojson bootstrap/cache
cleanup() {
  status=$?
  if command -v docker >/dev/null && docker compose version >/dev/null 2>&1; then
    # Dependencies run as root in Docker; remove generated files INSIDE this
    # isolated package mount, never via a cross-device host mv/chown.
    docker compose -f reproduction/compose.yml run --rm --no-deps --user 0 deps       sh -c 'rm -rf /var/www/vendor /var/www/storage /var/www/bootstrap/cache/*' >/dev/null 2>&1 || true
    docker compose -f reproduction/compose.yml down -v --remove-orphans >/dev/null 2>&1 || true
  fi
  rm -f reproduction/package.env
  rm -f .env
  if [ -d vendor ] || [ -d storage ]; then
    printf '%s\n' 'Generated package runtime files remain; inspect cleanup permissions.' >&2
    [ "$status" -ne 0 ] || status=1
  fi
  exit "$status"
}
trap cleanup EXIT INT TERM
if command -v docker >/dev/null && docker compose version >/dev/null 2>&1; then
  docker compose -f reproduction/compose.yml config --quiet
  docker compose -f reproduction/compose.yml build deps
  docker compose -f reproduction/compose.yml run --rm --no-deps deps composer install --no-interaction --prefer-dist --no-progress
  docker compose -f reproduction/compose.yml up -d app
  docker compose -f reproduction/compose.yml exec -T app php artisan --version
  docker compose -f reproduction/compose.yml exec -T app php -r '$b=@file_get_contents("http://127.0.0.1:8000/api/health",false,stream_context_create(["http"=>["timeout"=>5]])); $j=json_decode($b?:"",true); echo $b?:"<empty>","\n"; if(($j["status"]??null)!=="ok"||($j["database"]??null)!=="up")exit(1);'
  docker compose -f reproduction/compose.yml exec -T app php -r '$h=fopen("http://127.0.0.1:8000/mobility/","r"); $s=$h?($http_response_header[0]??""):""; if(!$h||strpos($s," 200 ")===false)exit(1); echo "campus commute route $s\n";'
  docker compose -f reproduction/compose.yml exec -T -e DB_USERNAME=root -e DB_PASSWORD=smoke-root-password app php artisan test --compact tests/Unit/Services/Logic/TripLiveShareManagerTest.php
  docker compose -f reproduction/compose.yml exec -T mysql mysql -uroot -psmoke-root-password -e 'CREATE DATABASE IF NOT EXISTS testing'
  docker compose -f reproduction/compose.yml exec -T -e DB_DATABASE=testing -e DB_USERNAME=root -e DB_PASSWORD=smoke-root-password app php artisan test --compact tests/extension/MobilityCommuteProductTest.php
else
  printf '%s\n' 'Docker Compose unavailable; required runtime verification was not executed.' >&2
  exit 2
fi
printf '%s\n' 'Package verification passed.'
