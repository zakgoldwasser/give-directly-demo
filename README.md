# Partner Donation Reconciliation

An independent Laravel demonstration that reconciles partner donation reports, payout reports, and a CRM snapshot into explainable proposed changes.

The application currently provides isolated temporary workspaces, private CSV storage, bounded parsing, immutable source-row staging, versioned mappings, canonical record history, deterministic CRM matching, and payout reconciliation. The guided reference reports produce persisted, explainable reconciliation outcomes. Reviewers can record campaign mappings, confirm supported CRM candidates, approve the current run, download an immutable reviewed ZIP package, and compare its approved actions with a fixed post-import CRM snapshot.

## Demonstration boundary

- Public mode accepts only catalogued synthetic fixtures.
- Local evaluation can explicitly enable arbitrary CSV files.
- The application does not write to Salesforce or verify bank receipt.
- Report schemas and rules are proposed demonstration contracts.

## Requirements

- Docker Desktop
- Docker Compose v2

The Sail containers provide PHP 8.4 and PostgreSQL.

## Quick start

From the repository root in PowerShell:

```powershell
Copy-Item .env.example .env -ErrorAction SilentlyContinue
docker compose up -d --build
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate
docker compose exec laravel.test npm install
docker compose exec laravel.test npm run build
```

Open [http://localhost](http://localhost).

Run the tests with:

```powershell
docker compose exec laravel.test php artisan test
```

## Local evaluation uploads

Arbitrary CSV uploads are disabled by default. To enable them only in a local evaluation environment, set:

```dotenv
RECONCILIATION_ALLOW_ARBITRARY_UPLOADS=true
```

The workspace presents separate report slots for donations, payouts, and a CRM snapshot. Public deployments must leave this value false and configure the approved fixture checksums instead.

## Reviewed export package

An approved current run produces a private ZIP containing `creates.csv`, `updates.csv`, `held.csv`, `audit.csv`, and `manifest.json`. Creating or downloading this package does not write to a CRM. The generic destination profile exports only gift fields; donor names, email addresses, and contact consent are excluded.

Configure `external_gift_key`, `source_transaction_id`, `crm_record_id`, and `campaign_id` as text columns in the destination importer so leading-zero identifiers remain unchanged. Configure `gross_amount` and `refunded_amount` as fixed two-decimal values, and validate the manifest checksums before import.

## Simulated CRM confirmation

The guided flow can compare every approved create or update with the supplied `crm-post-import.csv` snapshot. It records matches, field mismatches, duplicate keys, and cases that cannot be verified because snapshot coverage is incomplete. The comparison reads only the exported gift fields and persists the snapshot checksum and per-action evidence. It never calls or writes to an external CRM, and a match does not prove that an external import ran.

After confirmation, the guided flow can promote that immutable snapshot to the active CRM baseline and run the existing reconciliation engine again with the recorded review decisions. The reference result is 0 creates, 0 updates, 8 unchanged gifts, and the same 4 held gifts. The original CRM import and every earlier run remain available as historical evidence.

The workspace run history presents that evidence as a read-only timeline. Each run detail links its source checksums and mapping profiles to review decisions, gift and payout outcomes, approvals, export packages, snapshot confirmations, and any later verification run.

## Bounded operations

Every import, mapping, review, approval, export, confirmation, and verification mutation records a pending, running, completed, or failed workspace operation. Browser forms and API clients can supply an idempotency key; completed requests replay their recorded response, changed input under the same key returns a conflict, and duplicate running work is rejected. Processing uses a 20-second cooperative deadline and a 60-second lease. Expired leases become retryable failures when the workspace is read, and timeout responses include a non-sensitive trace ID.

## Workspace cleanup

Expired guest workspaces and their private import and export directories are purged by the `workspaces:purge-expired` command. Each invocation processes a configurable bounded batch and is safe to repeat. Docker Compose runs Laravel's scheduler, while Vercel Cron calls the bearer-protected `/internal/cron/workspaces/purge` endpoint every 15 minutes. A workspace database record is retained when either private storage directory cannot be deleted so a later sweep can retry.

## Public release safeguards

Workspace creation and reset requests are limited independently by browser session and IP address. Import, mapping, review, approval, export, confirmation, and verification mutations share a second per-session and per-IP limit. The defaults are 5 workspace starts and 10 work requests per minute. Set `RECONCILIATION_NEW_WORKSPACES_ENABLED=false` to stop creating temporary workspaces without taking the rest of the application offline.

Laravel's public `/up` route reports basic application health. The `/ready` route verifies the database and both private storage disks, requires `Authorization: Bearer <RECONCILIATION_READINESS_SECRET>`, and fails closed when the secret is absent. The cleanup endpoint uses the same bearer pattern with `CRON_SECRET`; Vercel automatically sends that value to configured cron requests.

## Vercel container deployment

`Dockerfile.vercel` packages PHP 8.4, FrankenPHP, PostgreSQL support, locked Composer dependencies, and compiled frontend assets. `Caddyfile` exposes only `public`, and `vercel.json` routes traffic to the container, limits invocations to 30 seconds, and schedules the bounded cleanup sweep.

Production must use external PostgreSQL, database-backed sessions and cache, and S3-compatible private object storage. Set `RECONCILIATION_STORAGE_DRIVER=s3`, the `AWS_*` connection values, distinct `RECONCILIATION_IMPORTS_ROOT` and `RECONCILIATION_EXPORTS_ROOT` prefixes, `APP_KEY`, `CRON_SECRET`, and `RECONCILIATION_READINESS_SECRET` in Vercel. When Vercel's server-side runtime flag is present, the limiter uses Vercel's edge-overwritten client-IP header; other environments use the socket IP. Run `php artisan migrate --force` as a release step; migrations are intentionally not run when a container starts.

## Current limits

| Setting | Default |
| --- | ---: |
| Maximum CSV size | 1 MiB per file |
| Maximum data rows | 2,000 per file |
| Maximum columns | 50 |
| Maximum field length | 4,096 characters |
| Active imports | 3 per workspace |
| Workspace lifetime | 24 hours |
| Processing deadline | 20 seconds |
| Operation lease | 60 seconds |
| Workspace starts | 5 per session and IP per minute |
| Imports and runs | 10 per session and IP per minute |
| Cleanup sweep | 100 workspaces per invocation |

Configuration lives in `config/reconciliation.php` and uses the `RECONCILIATION_*` environment variables documented in `.env.example`.

## Product specification

See [spec.md](spec.md). The complete source specification is retained at [docs/Partner_Donation_Reconciliation_Spec.docx](docs/Partner_Donation_Reconciliation_Spec.docx).
