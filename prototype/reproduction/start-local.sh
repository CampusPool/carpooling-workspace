#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$root"
command -v docker >/dev/null
command -v curl >/dev/null
docker compose version >/dev/null
project=${COMPOSE_PROJECT_NAME:-campuspool-interactive}
export COMPOSE_PROJECT_NAME="$project"
printf '%s\n' "$project" > reproduction/.local-project
python3 reproduction/configure-demo-env.py
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/suburbs storage/geojson bootstrap/cache
docker compose -f reproduction/compose.yml -f reproduction/compose.local.yml -f reproduction/compose.demo.yml config --quiet
docker compose -f reproduction/compose.yml -f reproduction/compose.local.yml -f reproduction/compose.demo.yml up -d --build app
attempt=0
until curl -fsS --max-time 2 http://127.0.0.1:18180/mobility/ >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 30 ]; then
    echo 'CampusPool did not become reachable on 127.0.0.1:18180; inspect Compose app logs.' >&2
    exit 1
  fi
  sleep 1
done
printf '%s\n' 'CampusPool SYNTHETIC demo (not real routes): http://127.0.0.1:18180/mobility/?demo=campus-route'
