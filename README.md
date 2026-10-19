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
