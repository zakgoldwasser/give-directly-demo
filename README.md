# Partner Donation Reconciliation

An independent Laravel demonstration that reconciles partner donation reports, payout reports, and a CRM snapshot into explainable proposed changes.

The repository is being rebaselined from an earlier generic CSV-cleaning concept. Its retained foundation currently provides isolated temporary workspaces, private CSV storage, bounded parsing, immutable source-row staging, and local evaluation uploads. Matching, payout reconciliation, reviewed exports, and the reference scenario are the next implementation milestones.

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

Configuration lives in `config/reconciliation.php` and uses the `RECONCILIATION_*` environment variables documented in `.env.example`.

## Product specification

See [spec.md](spec.md). The complete source specification is retained at [docs/Partner_Donation_Reconciliation_Spec.docx](docs/Partner_Donation_Reconciliation_Spec.docx).
