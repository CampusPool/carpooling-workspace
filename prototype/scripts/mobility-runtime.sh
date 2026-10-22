#!/bin/sh

# Shared local-only runtime helpers. Keep all arguments as argv; callers must
# pass one Compose argument per shell word.

mobility_require_command() (
  command -v "$1" >/dev/null 2>&1 || {
    printf 'Missing required command: %s\n' "$1" >&2
    return 2
  }
)

mobility_sha256() (
  [ "$#" -eq 1 ] && [ -f "$1" ] || {
    printf '%s\n' 'SHA-256 input file is missing or ambiguous.' >&2
    return 2
  }
  if command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$1" | awk '{print $1}'
  elif command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  else
    printf '%s\n' 'Missing SHA-256 utility (shasum or sha256sum).' >&2
    return 2
  fi
)

mobility_env_value() (
  awk -F= -v key="$1" '$1 == key {sub(/^[^=]*=/, ""); print; exit}' "$2"
)

mobility_validate_test_env() (
  env_file=$1
  [ -f "$env_file" ] || { printf 'Missing test environment: %s\n' "$env_file" >&2; return 2; }
  if ! awk -F= '/^[A-Za-z_][A-Za-z0-9_]*=/ {if (seen[$1]++) exit 1}' "$env_file"; then
    printf '%s\n' 'Refusing duplicate environment keys in local evaluation env.' >&2
    return 2
  fi
  [ "$(mobility_env_value APP_ENV "$env_file")" = testing ] || { printf '%s\n' 'Refusing non-testing APP_ENV in local evaluation env.' >&2; return 2; }
  [ "$(mobility_env_value APP_DEBUG "$env_file")" = false ] || { printf '%s\n' 'Refusing APP_DEBUG in local evaluation env.' >&2; return 2; }
  [ "$(mobility_env_value DB_HOST "$env_file")" = mysql ] || { printf '%s\n' 'Refusing non-local DB_HOST in local evaluation env.' >&2; return 2; }
  [ "$(mobility_env_value MERCADO_PAGO_ACCESS_TOKEN "$env_file")" = smoke-placeholder ] || { printf '%s\n' 'Refusing non-synthetic provider access token in local evaluation env.' >&2; return 2; }
  [ "$(mobility_env_value MERCADO_PAGO_WEBHOOK_SECRET "$env_file")" = smoke-placeholder ] || { printf '%s\n' 'Refusing non-synthetic provider webhook secret in local evaluation env.' >&2; return 2; }
  [ -z "$(mobility_env_value OPENROUTER_API_KEY "$env_file")" ] || { printf '%s\n' 'Refusing external model key in local evaluation env.' >&2; return 2; }
  [ "$(mobility_env_value OSRM_ROUTER_BASE_URL "$env_file")" = http://127.0.0.1:9 ] || { printf '%s\n' 'Refusing non-local routing endpoint in local evaluation env.' >&2; return 2; }
)

mobility_init_test_env() (
  env_file=$1
  if [ -f "$env_file" ]; then
    mobility_validate_test_env "$env_file"
    return $?
  fi
  umask 077
  cat >"$env_file" <<'EOF'
APP_NAME=CarpoolearSmoke
APP_ENV=testing
APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=
APP_DEBUG=false
APP_URL=http://127.0.0.1:18000
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=carpoolear
DB_USERNAME=carpoolear
DB_PASSWORD=smoke-db-password
QUEUE_CONNECTION=sync
CACHE_STORE=file
SESSION_DRIVER=file
MAIL_MAILER=log
IDENTITY_VALIDATION_ENABLED=false
MERCADO_PAGO_ACCESS_TOKEN=smoke-placeholder
MERCADO_PAGO_WEBHOOK_SECRET=smoke-placeholder
OSRM_ROUTER_BASE_URL=http://127.0.0.1:9
OPENROUTER_API_KEY=
JWT_SECRET=0123456789abcdef0123456789abcdef
EOF
  printf 'Created synthetic test environment: %s\n' "$env_file" >&2
)

mobility_compose() (
  project=$1
  shift
  context=${MOBILITY_DOCKER_CONTEXT:-colima-mobility}
  mobility_require_command docker
  docker --context "$context" compose --project-name "$project" "$@"
)
