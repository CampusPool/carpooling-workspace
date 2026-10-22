#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$root"
if [ -f reproduction/.local-project ]; then
  project=$(cat reproduction/.local-project)
elif [ -n "${COMPOSE_PROJECT_NAME:-}" ]; then
  project=$COMPOSE_PROJECT_NAME
else
  echo 'No local project is recorded in this clone; set COMPOSE_PROJECT_NAME only if recovering its startup.' >&2
  exit 1
fi
export COMPOSE_PROJECT_NAME="$project"
docker compose -f reproduction/compose.yml -f reproduction/compose.local.yml -f reproduction/compose.demo.yml down --remove-orphans
rm -f .env reproduction/.local-project
printf '%s\n' "Stopped $project; local configuration and database volume were preserved."
