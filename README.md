# GivingLedger

Turn messy donation spreadsheets into clean, trustworthy, CRM-ready data.

Phase 1 Milestone 2 provides the public landing experience, isolated temporary guest workspaces, built-in sample data, CSV upload, and parsing into temporary staging rows. Column mapping, validation, duplicate review, and export come in later milestones.

## Requirements

- Docker Desktop
- Docker Compose v2

PHP and Composer are not required on the host. The Sail containers provide PHP 8.4 and PostgreSQL.

## Quick start (PowerShell)

From the repository root:

```powershell
# Copy environment file if needed, then start the stack
Copy-Item .env.example .env -ErrorAction SilentlyContinue
docker compose up -d --build

# Generate an app key if APP_KEY is empty
docker compose exec laravel.test php artisan key:generate

# Run migrations
docker compose exec laravel.test php artisan migrate

# Optional: build frontend assets
docker compose exec laravel.test npm install
docker compose exec laravel.test npm run build
```

Open [http://localhost](http://localhost).

### Tests

```powershell
docker compose exec laravel.test php artisan test
```

### Useful commands

```powershell
docker compose ps
docker compose logs -f laravel.test
docker compose down
```

The Sail bash wrapper (`./vendor/bin/sail`) is not the Windows entry point. Prefer `docker compose` as shown above.

## Configuration

Guest workspace limits live in `config/givingledger.php` and can be overridden with `GIVINGLEDGER_*` environment variables.

| Setting | Default |
| --- | --- |
| Max CSV size | 2 MB |
| Max data rows | 1,000 |
| Max columns | 50 |
| Max field length | 2,000 characters |
| Max active imports | 3 |
| Workspace lifetime | 60 minutes |

## Sessions

Local Docker uses HTTP, so `SESSION_SECURE_COOKIE` defaults to `false`. Production deployments over HTTPS must set `SESSION_SECURE_COOKIE=true`. Session cookies remain HttpOnly with SameSite=`lax`.

## Product specification

See [spec.md](spec.md) for full product and technical requirements.
