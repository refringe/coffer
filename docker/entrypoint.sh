#!/usr/bin/env bash
set -euo pipefail

APP_USER="appuser"
DATA_DIR="/data"
DB_DIR="${DATA_DIR}/database"
UPLOADS_DIR="${DATA_DIR}/app"
SHARES_DIR="${COFFER_STORAGE_PATH:-/data/shares}"

# ---------------------------------------------------------------------------
# One-off commands: when args are passed (e.g. `docker compose run --rm app
# php artisan key:generate --show`), run them directly and skip the full app
# boot — so utility commands work before APP_KEY/migrations are in place.
# A bare `docker run` (no args) falls through to the normal boot below.
# ---------------------------------------------------------------------------
if [[ "$#" -gt 0 ]]; then
    exec "$@"
fi

# ---------------------------------------------------------------------------
# Resolve APP_KEY (supports Docker secrets via APP_KEY_FILE).
# ---------------------------------------------------------------------------
if [[ -z "${APP_KEY:-}" && -n "${APP_KEY_FILE:-}" && -f "${APP_KEY_FILE}" ]]; then
    APP_KEY="$(cat "${APP_KEY_FILE}")"
    export APP_KEY
fi

if [[ -z "${APP_KEY:-}" ]]; then
    cat >&2 <<'EOF'
================================================================================
 Coffer cannot start: APP_KEY is not set.

 Generate one and provide it to the container as the APP_KEY environment
 variable (or via APP_KEY_FILE). A regenerated key invalidates all encrypted
 data, so set it once and keep it:

     docker run --rm ghcr.io/refringe/coffer php artisan key:generate --show

 Then run with, e.g.:  -e APP_KEY=base64:....
================================================================================
EOF
    exit 1
fi

# ---------------------------------------------------------------------------
# Prepare persisted state on the /data volume.
# ---------------------------------------------------------------------------
mkdir -p "${DB_DIR}" "${UPLOADS_DIR}/public" "${UPLOADS_DIR}/private" "${SHARES_DIR}"

# Caddy/FrankenPHP config + data (certificates when serving HTTPS directly)
# live on the volume so they persist and are writable by the runtime user.
mkdir -p "${DATA_DIR}/caddy/config" "${DATA_DIR}/caddy/data"

# Persist uploads: replace storage/app with a symlink into the data volume.
if [[ ! -L /app/storage/app ]]; then
    rm -rf /app/storage/app
    ln -s "${UPLOADS_DIR}" /app/storage/app
fi

# Framework scratch space (compiled views, cache) — ephemeral but must exist.
mkdir -p \
    /app/storage/framework/cache/data \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs \
    /app/bootstrap/cache

# Create the SQLite database file if it does not exist yet.
DB_PATH="${DB_DATABASE:-${DB_DIR}/database.sqlite}"
mkdir -p "$(dirname "${DB_PATH}")"
[[ -f "${DB_PATH}" ]] || touch "${DB_PATH}"

# ---------------------------------------------------------------------------
# One-time boot tasks (run as root, then ownership is fixed below).
# ---------------------------------------------------------------------------
if [[ "${RUN_MIGRATIONS:-true}" != "false" ]]; then
    php /app/artisan migrate --force --no-interaction
fi

php /app/artisan storage:link --no-interaction || true

# Cache config/routes/views for production performance.
php /app/artisan optimize --no-interaction

# Hand all writable paths to the runtime user.
chown -R "${APP_USER}:${APP_USER}" "${DATA_DIR}" "${SHARES_DIR}" /app/storage /app/bootstrap/cache

# ---------------------------------------------------------------------------
# Launch the process supervisor (web + queue + scheduler).
# ---------------------------------------------------------------------------
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
