# Partner Donation Reconciliation

An independent Laravel demonstration that reconciles partner donation reports, payout reports, and a CRM snapshot into explainable proposed changes.

The application currently provides isolated temporary workspaces, private CSV storage, bounded parsing, immutable source-row staging, versioned mappings, canonical record history, deterministic CRM matching, and payout reconciliation. The guided reference reports produce persisted, explainable reconciliation outcomes. Reviewers can record campaign mappings, confirm supported CRM candidates, approve the current run, download an immutable reviewed ZIP package, and compare its approved actions with a fixed post-import CRM snapshot.

## Demonstration boundary

- Use a catalogued synthetic sample or upload your own donation, payout, and CRM CSVs.
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

## Sample datasets

The landing page and workspace offer four sample cards. Each explains what its CSVs contain and what it demonstrates, with separate **View sample CSVs** and **Process this sample** controls. Processing populates a fresh workspace and runs reconciliation without downloading or uploading files. Reset reloads the selected sample. Optional report downloads remain available.

CSV previews are embedded from the checksum-verified source fixtures, with Donations, Payouts, and CRM tabs. Scenario annotations highlight important rows and explain why they matter; the **Only show highlighted rows** filter retains the original row numbers and values. Previewing does not create a workspace or make a network request. With JavaScript enabled, processing uses a background request and leaves preview controls active. If a preview is open when processing finishes, it stays open and offers **View reconciliation**. Otherwise the browser opens the results automatically. Opening the reconciliation shows a spinner and navigation status until the next page appears. Processing errors keep the previews usable and allow a retry; ordinary form navigation remains the fallback without JavaScript.

Review, mapping, approval, export, confirmation, verification, reset, and expired-session submissions show action-specific spinners and prevent repeat submissions through navigation. Internal page links show opening feedback; same-page anchors, external links, and modified clicks retain their normal behavior. Downloads briefly show “Download requested” rather than claiming completion. Upload controls stay busy until navigation, recover on errors, and reset with the other controls when returning with browser Back.

| Sample | CSV contents | Demonstrates |
| --- | --- | --- |
| Update this month's donations | Six donations, balanced payouts, and a CRM snapshot with missing gifts and one incorrect amount | Two creates, one correction, and three unchanged donations |
| Resolve records that need review | Six donations plus a repeated row, balanced payouts, duplicate CRM targets, a possible match without identifiers, and an unmapped campaign | Source-row deduplication, evidence-backed review decisions, and exclusion of unresolved CRM duplicates |
| Explain a payout difference | Six donations, fees and refunds, one balanced payout, and one payout with a GBP 3 difference | Expected deductions versus an unexplained discrepancy; affected gifts stay held |
| Full reconciliation example | The original reference set combining matching, review, refunds, pending payouts, and exceptions | The complete review, export, and simulated confirmation workflow |

Each set has its own checksum-verified manifest and fixed post-import CRM snapshot under `resources/fixtures`. Confirmation and verification use the selected set's snapshot. The full reference set and its original API default remain available for existing integrations.

Run the tests with:

```powershell
docker compose exec laravel.test php artisan test
docker compose exec laravel.test node --test tests/JavaScript/feedback.test.js
```

## CSV uploads

Below the sample datasets, choose a donation, payout, or CRM CSV to upload. The first upload starts a temporary workspace separate from any sample; subsequent uploads use the same upload workspace. Uploads open a report modal with separate Mapping and Preview tabs. Save and validate inside the modal, then continue to the next report. Closing preserves the file and the report card’s Review mapping link reopens it. The standalone import page remains available as a fallback. File-size, row-count, validation, rate, and processing limits apply to all uploads. Refunded amount is optional in donation and CRM reports: an absent or unmapped column, or an empty cell, normalizes to 0.00. Non-empty values retain the usual amount and refund-consistency checks; original source values remain unchanged.

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

Production must use external PostgreSQL, database-backed sessions and cache, and S3-compatible private object storage. Set `RECONCILIATION_STORAGE_DRIVER=s3`, `RECONCILIATION_S3_ACCESS_KEY_ID`, `RECONCILIATION_S3_SECRET_ACCESS_KEY`, the remaining `AWS_*` connection values, distinct `RECONCILIATION_IMPORTS_ROOT` and `RECONCILIATION_EXPORTS_ROOT` prefixes, `APP_KEY`, `CRON_SECRET`, and `RECONCILIATION_READINESS_SECRET` in Vercel. The reconciliation-specific credential names avoid platform-provided AWS credential variables; standard AWS credential names remain a local fallback. When Vercel's server-side runtime flag is present, the limiter uses Vercel's edge-overwritten client-IP header; other environments use the socket IP. Run `php artisan migrate --force` as a release step; migrations are intentionally not run when a container starts.

For the lightweight demo deployment, [`infra/sam/template.yaml`](infra/sam/template.yaml) provisions the private S3 storage and least-privilege IAM user. [`infra/sam/README.md`](infra/sam/README.md) contains the SAM deployment commands, access-key handoff, and the pooled Neon PostgreSQL variables. Neon requires no application adapter: Laravel uses its standard PostgreSQL connection through `DB_URL`, with `DB_SSLMODE=require` retained for the deployment preflight.

## Deployment acceptance

Run the production preflight inside the release container after migrations:

```sh
php artisan reconciliation:verify-deployment
```

It fails unless the application is in production mode with debug disabled, PostgreSQL and encrypted connections configured, database-backed sessions and cache, synchronous request work, secure cookies, current migrations, strong operational secrets, and working private object storage. `--json` produces machine-readable output. A local container rehearsal may use `--allow-local`; that exception must not be used as a production release gate.

The deterministic synthetic benchmark contains 1,000 total rows across donations, payouts, and CRM data. Run the required 30 warm reconciliation samples against PostgreSQL with:

```sh
php artisan reconciliation:benchmark --runs=30 --json
```

The command reports min, median, p95, max, and peak PHP memory, fails at a p95 of 5 seconds or more, removes its temporary workspace, and refuses to run in production.

Measured locally on 19 October 2026 in the Docker PHP 8.4 application container with PostgreSQL 18: 30 warm runs produced 596.88 ms minimum, 639.08 ms median, 788.03 ms p95, 1,032.32 ms maximum, and 58.5 MiB peak PHP memory. This synthetic local result passes the 5-second warm reconciliation target; it is not a Vercel measurement.

The HTTP acceptance script completes the guided review and export, records warm landing and guided-start timings, and saves a session checkpoint plus the exported ZIP checksum. Run `prepare`, deploy a new version without changing the database, object-store prefixes, or `APP_KEY`, then run `verify` to prove that the workspace and exact private export survived container replacement:

```sh
DEPLOYMENT_URL=https://example.vercel.app \
RECONCILIATION_READINESS_SECRET=replace-me \
node scripts/deployment-acceptance.mjs prepare

# Deploy the new release, then run:
DEPLOYMENT_URL=https://example.vercel.app \
RECONCILIATION_READINESS_SECRET=replace-me \
node scripts/deployment-acceptance.mjs verify
```

The checkpoint contains a live anonymous session cookie and is ignored by Git. Delete `.deployment-acceptance` when verification is complete. Independently cold starts still require platform-level sampling; record five results with region and release metadata before public launch.

On Windows, the complete isolated local rehearsal is one command:

```powershell
.\scripts\local-deployment-rehearsal.ps1
```

It builds `Dockerfile.vercel`, creates a temporary PostgreSQL database and persistent private-storage volume, runs migrations and the release preflight, performs the prepare phase, replaces the request container, performs the verify phase, and removes its temporary database, volume, secrets, and checkpoint.

The local production-image rehearsal on 19 October 2026 passed all 19 preflight checks, measured a 50.06 ms warm landing p95 over 30 samples and a 684.04 ms guided-start request, then verified the same 4,038-byte export with checksum `ec0b9b68e6c4433916f794087e5906fbbae7319afaed5d2a51f2c71675761578` after replacing the request container. These are local Docker measurements; Vercel cold-start and regional measurements remain a production release gate.

For rollback, redeploy the previous immutable image while retaining the current `APP_KEY`, database, and object-store prefixes. Apply only backward-compatible migrations before the acceptance check. If a release needs a destructive schema change, take a database backup and prepare an explicit reverse migration before deployment. Disable new workspaces with `RECONCILIATION_NEW_WORKSPACES_ENABLED=false` while recovering, then rerun the preflight, `/ready`, and the saved persistence verification before reopening the demo.

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
