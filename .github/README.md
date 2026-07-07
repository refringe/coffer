<h1 align="center"><em>Coffer</em></h1>
<p align="center">
<a href="https://www.mozilla.org/en-US/MPL/2.0/"><img src="https://img.shields.io/badge/License-MPL_2.0-blue.svg" alt="License: MPL 2.0"></a>
<a href="https://github.com/refringe/coffer/actions/workflows/tests.yml"><img src="https://github.com/refringe/coffer/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
<a href="https://github.com/refringe/coffer/actions/workflows/quality.yml"><img src="https://github.com/refringe/coffer/actions/workflows/quality.yml/badge.svg" alt="Quality"></a>
<a href="https://github.com/refringe/coffer/pkgs/container/coffer"><img src="https://img.shields.io/badge/Image-ghcr.io-2496ED?logo=docker&logoColor=ffffff" alt="Container Image"></a>
</p>

Coffer is a self-hosted, Laravel-based application for storing and sharing files within a team. It runs on a single node with no external services: SQLite for the database, and the local disk for file storage, the queue, the cache, and sessions. Sign-in is handled entirely by GitHub, so there are no passwords to manage.

It is currently under active development.

## Features

- **Shares** - named storage locations that everyone in your organization can browse. Each share is just a directory on disk, so you can point one at any path or mounted volume.
- **A familiar file browser** - drag-and-drop uploads, folders, rename, move, search, inline previews for images, PDFs, and text, and download as a single file or a generated zip.
- **Recycle bin** - deleted items are recoverable for a configurable window before a daily job purges them.
- **Activity log** - every upload, rename, move, and delete is recorded per share, with a global feed for administrators.
- **GitHub-org access control** - only members of your GitHub organization can sign in. Organization owners are administrators; everyone else is a regular user.

## Self-Hosting

Coffer ships as a single all-in-one Docker image (`ghcr.io/refringe/coffer`) that runs the web server, queue worker, and scheduler together. The SQLite database and on-box state live in one volume mounted at `/data`; uploaded files live under `/data/shares`.

### Requirements

- Docker with the Compose plugin.
- A GitHub organization. Sign-in is restricted to its members, and its owners become Coffer's administrators.
- A URL where users will reach Coffer (for example `https://files.example.com`). Coffer serves plain HTTP by default and expects a TLS-terminating reverse proxy in front; it can also terminate TLS itself (see [Serving HTTPS](#serving-https)).

### 1. Register a GitHub OAuth App

This is what lets people sign in. In GitHub, go to **Settings → Developer settings → OAuth Apps → New OAuth App** (register it under your organization or your personal account; either works), and fill in:

| Field | Value |
| --- | --- |
| Application name | `Coffer` (anything you like) |
| Homepage URL | your `APP_URL`, e.g. `https://files.example.com` |
| Authorization callback URL | `{APP_URL}/auth/github/callback`, e.g. `https://files.example.com/auth/github/callback` |

Register the app, then **Generate a new client secret**. Keep the **Client ID** and **Client secret** for the next step.

> If your organization restricts third-party OAuth app access, an owner must approve this app once (**Organization → Settings → Third-party Access**) so Coffer can read who is an owner versus a member.

### 2. Get the files

```bash
# Compose file and environment template
curl -O https://raw.githubusercontent.com/refringe/coffer/main/docker-compose.yml
curl -o .env https://raw.githubusercontent.com/refringe/coffer/main/.env.docker.example
```

### 3. Configure

Generate an application key (keep it; changing it invalidates encrypted data):

```bash
docker compose run --rm app php artisan key:generate --show
```

Open `.env` and set the five required values:

- `APP_KEY` - the key you just generated.
- `APP_URL` - your public URL, e.g. `https://files.example.com`.
- `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` - from the OAuth App.
- `GITHUB_ORG` - your organization's slug (the name in its GitHub URL, `github.com/<org>`).

Everything else in `.env` is optional and documented inline.

### 4. Start it

```bash
docker compose up -d
```

Open your `APP_URL` and click **Sign in with GitHub**. The first organization owner to sign in becomes the first administrator. There is no setup screen; administrators create shares from **Admin → Shares**, and any organization member can then use them.

### Serving HTTPS

By default the container listens on plain HTTP at `:8080`, published on the host port set by `COFFER_HTTP_PORT` (default `8777`). Point your reverse proxy (nginx, Caddy, Traefik, a tunnel, ...) at that port and let it terminate TLS.

To have the container terminate TLS itself instead, set `SERVER_NAME` to your domain and publish ports `80`/`443` in `docker-compose.yml`; FrankenPHP will obtain and renew a certificate automatically.

### Configuration reference

Required:

| Variable | Description |
| --- | --- |
| `APP_KEY` | Encryption key. Generate once with `key:generate --show`. |
| `APP_URL` | Public URL Coffer is served on. |
| `GITHUB_CLIENT_ID` | OAuth App client ID. |
| `GITHUB_CLIENT_SECRET` | OAuth App client secret. |
| `GITHUB_ORG` | Organization slug whose members may sign in; owners become admins. |

Optional (defaults are baked into the image):

| Variable | Default | Description |
| --- | --- | --- |
| `APP_NAME` | `Coffer` | Name shown in the UI. |
| `COFFER_HTTP_PORT` | `8777` | Host port published for the web app. |
| `SERVER_NAME` | `:8080` | Listen address; set to your domain to serve HTTPS directly. |
| `COFFER_STORAGE_PATH` | `/data/shares` | Base directory new shares are created under. |
| `COFFER_MAX_FILE_SIZE` | `0` | Largest single upload in bytes (`0` = unlimited). |
| `COFFER_TRASH_DAYS` | `30` | Days deleted items stay in the recycle bin. |
| `COFFER_ZIP_TTL_HOURS` | `24` | Hours a generated zip archive is kept. |
| `COFFER_ACTIVITY_DAYS` | `90` | Days activity is kept (`0` = forever). |
| `RUN_MIGRATIONS` | `true` | Run migrations automatically on container start. |

Coffer sends no email, so no SMTP configuration is required.

### Storage and per-share disks

Every share is a directory under `COFFER_STORAGE_PATH` (`/data/shares`), persisted in the `shares` volume. To place a share on its own disk, mount that disk under `/data/shares` in `docker-compose.yml` and create the share in the admin UI with a matching path. The commented examples in `docker-compose.yml` show how.

### Updating

```bash
docker compose pull
docker compose up -d
```

Migrations run automatically on start (unless you set `RUN_MIGRATIONS=false`).

### Backups

Back up the `data` volume (the SQLite database and on-box state) and your share storage (the `shares` volume, plus any disks you mounted for individual shares). Stopping the container first gives the most consistent SQLite snapshot.

### Alternative: `docker run`

```bash
docker run -d \
  --name coffer \
  -e APP_KEY="base64:..." \
  -e APP_URL="https://files.example.com" \
  -e GITHUB_CLIENT_ID="..." \
  -e GITHUB_CLIENT_SECRET="..." \
  -e GITHUB_ORG="my-org" \
  -p 8777:8080 \
  -v coffer-data:/data \
  ghcr.io/refringe/coffer:latest
```

See [`.env.docker.example`](../.env.docker.example) for every supported setting.

## Local Development

Local development uses [Laravel Herd](https://herd.laravel.com). No MySQL or Redis is required; everything runs on SQLite and the local-disk drivers.

1. **Clone the repository** and point Herd at the project directory.
2. **Set up the environment:**
   ```bash
   cp .env.example .env
   composer install
   npm install
   php artisan key:generate
   ```
3. **Create the SQLite database and run migrations:**
   ```bash
   touch database/database.sqlite
   php artisan migrate
   ```
4. **Run the dev stack** (server, queue, logs, and Vite):
   ```bash
   composer run dev
   ```

The site is then available at `https://coffer.test`. To exercise sign-in locally, register a separate GitHub OAuth App with a callback of `https://coffer.test/auth/github/callback` and set the `GITHUB_*` values in `.env`.

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request. Run `composer sendit` to execute the full formatting, linting, and test suite before submitting.

## License

Coffer is open-source software licensed under the [Mozilla Public License 2.0](https://www.mozilla.org/en-US/MPL/2.0/).
