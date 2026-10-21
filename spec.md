# Partner Donation Reconciliation

## Product and technical specification

**Version:** 1.0

**Date:** 18 October 2026

**Status:** Authoritative replacement for the previous project specification

The complete specification is stored in [`docs/Partner_Donation_Reconciliation_Spec.docx`](docs/Partner_Donation_Reconciliation_Spec.docx). This file records the implementation contract that should guide repository work.

## Product contract

Build one complete workflow that loads partner donation reports, payout reports, and an existing CRM snapshot; validates and reconciles the evidence; supports audited exception review; produces a reviewed import package; and compares a refreshed CRM snapshot with the exported expectations.

The public application is an independent demonstration with synthetic sample datasets and isolated temporary workspaces. Users can upload their own CSVs from the homepage without a configuration flag. This upload behavior supersedes the fixture-only restriction in the retained complete specification.

## Required first release

- Laravel 13, PHP 8.4, Blade, plain JavaScript, PostgreSQL, and private object storage.
- Donation, payout-component, and CRM-snapshot imports with explicit report context and immutable source rows.
- Versioned mappings, schema-change detection, deterministic identity matching, CRM field differences, payout reconciliation, and coverage checks.
- Separate provider-gift, payout, reconciliation, CRM-match, review, export, and confirmation states.
- Reviewed creates and updates, unchanged records, held records, append-only audit evidence, and optimistic concurrency.
- A repeatable ZIP containing `creates.csv`, `updates.csv`, `held.csv`, `audit.csv`, and `manifest.json`.
- Comparison with a later CRM snapshot without performing a CRM write.
- A guided synthetic reference scenario yielding 2 creates, 4 updates, 2 unchanged gifts, and 4 held gifts.
- A reproducible local evaluation environment using the same engine and schemas.

## Deferred integrations

Live Salesforce writes, real CAF adapters, bank-statement matching, multiple currencies, Gift Aid calculation, donor merging, scheduled partner ingestion, multi-user approvals, and organizational authentication remain outside the first release.

## Public operating limits

- 1 MiB and 2,000 data rows per file.
- Three files in a report set, 50 columns, and 4,096 characters per cell.
- Bounded synchronous import, reconciliation, and export work.
- A 20-second application deadline, 30-second function maximum, and 60-second operation lease.
- Configurable per-session and per-IP limits, initially 5 workspace starts and 10 imports or runs per minute.
- Workspaces expire after 24 hours; cleanup completes within the following 24 hours.
- Bundled sample fixture checksums are verified; uploaded CSVs retain checksums for evidence and duplicate detection.

When this summary and the retained complete specification differ, the complete specification controls.
