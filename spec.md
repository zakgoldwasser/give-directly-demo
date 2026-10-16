# GivingLedger

## Product Requirements & Technical Design Specification

**Version:** 2.0  
**Status:** Ready for implementation  
**Primary stack:** Laravel 13, PHP 8.3+, PostgreSQL, Blade, vanilla JavaScript, CSS, Vite  
**License:** MIT (proposed)  
**Deployment model:** Public web application initially; optional self-hosting and private organizational workspaces later

---

# 1. Executive Summary

GivingLedger transforms messy donation spreadsheets into validated, normalized, reviewable CSV exports.

Nonprofit organizations frequently receive donation records from fundraising partners, payment platforms, corporate giving programs, and offline donation channels. The data often arrives with inconsistent formatting, incomplete information, duplicate transactions, or unfamiliar campaign names.

GivingLedger reduces the manual work required to prepare these records for donor databases.

The initial product will be usable by anyone without creating an account.

A visitor should be able to:

1. Open GivingLedger.
2. Select a built-in sample dataset or upload a CSV.
3. Map source columns to standardized donation fields.
4. Validate and normalize the records.
5. Review duplicates and other exceptions.
6. Correct or exclude problematic records.
7. Download a clean CSV.

The entire process should be achievable without registration, payment, or third-party integrations.

Phase 2 will introduce persistent accounts and organizational workflows using the same underlying processing engine.

## 1.1 Product Promise

"Turn messy donation spreadsheets into clean, trustworthy, CRM-ready data."

## 1.2 Primary Goals

**Phase 1**

- Deliver a genuinely functional public utility.
- Demonstrate high-quality financial-data processing.
- Make CSV cleanup accessible to nontechnical users.
- Provide a complete experience with no login.
- Support safe, temporary processing of uploaded files.
- Create a strong demonstration of Laravel engineering and system reliability.

**Phase 2**

- Enable recurring use by nonprofit teams.
- Preserve reviewed and approved donation records.
- Track imports, corrections, and export history.
- Support collaboration and organizational policies.
- Integrate with nonprofit CRM systems.

## 1.3 Non-Goals

GivingLedger is not:

- A donation checkout application.
- A payment processor.
- An accounting ledger.
- A donor relationship management system.
- A tax-receipt issuing system.
- A replacement for Salesforce.
- A system that independently verifies whether money was received.

The term "reconciliation" in Phase 1 means identifying inconsistencies and duplicate candidates within uploaded data. It does not establish payment settlement or compare transactions against a bank or CRM.

---

# 2. Product Phasing

## Phase 1 — Public MVP

Phase 1 must work without authentication.

### Included

- Public landing page.
- Built-in synthetic sample data.
- CSV upload.
- Column mapping.
- Date, amount, and currency normalization.
- Data validation.
- Exact duplicate detection.
- Possible duplicate detection.
- Exception review.
- Inline record correction.
- Manual duplicate resolution.
- Clean CSV export.
- Optional exceptions CSV export.
- Session-scoped temporary workspaces.
- Automatic expiration and deletion.
- Upload and processing rate limits.
- Comprehensive automated tests.
- Responsive interface.
- Open-source documentation.

### Excluded

- Registration.
- Login.
- Organization management.
- Saved user accounts.
- Persistent canonical donation database.
- Permanent import history.
- Team collaboration.
- Salesforce integration.
- Payment processor integration.
- Background recurring imports.
- AI-assisted cleanup.
- Cross-session duplicate detection.
- Large enterprise-sized imports.

## Phase 2 — Private Application

Phase 2 introduces:

- Authentication.
- Persistent organizations.
- User roles.
- Saved import history.
- Reusable partner mapping presets.
- Permanent donation records.
- Cross-import duplicate detection.
- Campaign and alias management.
- Approval workflows.
- Audit history.
- Persistent export history.
- Configurable retention.
- Team collaboration.

Direct Salesforce synchronization and payment integrations are separate post-MVP integration milestones, not prerequisites for the first private release.

---

# 3. Target Users

## 3.1 Public Visitor

A person who needs to clean a donation spreadsheet without creating an account.

Typical tasks:

- Standardize donation amounts and dates.
- Find duplicate transactions.
- Correct inconsistent records.
- Prepare data for importing elsewhere.
- Evaluate GivingLedger using sample data.

The visitor has access only to their own temporary workspace.

## 3.2 Fundraising Operations Specialist (Phase 2)

A nonprofit employee who regularly imports third-party donation records.

Additional needs:

- Saved mapping configurations.
- Import history.
- Repeatable validation rules.
- Cross-import deduplication.
- Traceable corrections and approvals.

## 3.3 Organization Administrator (Phase 2)

Manages an organization's users, campaigns, settings, imports, exports, and retention policies.

---

# 4. User Experience Architecture

## 4.1 Public Navigation

Public navigation should be minimal:

- GivingLedger logo.
- How it works.
- Try the tool.
- GitHub repository.
- About/privacy information.

No prominent login or signup requirement during Phase 1.

The main action should always be "Try with sample data" or "Upload a CSV."

## 4.2 Primary Workflow

Upload / Sample Data  
↓  
Map Columns  
↓  
Configure Parsing  
↓  
Process Data  
↓  
Review Results  
↓  
Resolve Exceptions  
↓  
Export Clean CSV

Every step must support returning to previous steps without unnecessarily restarting the process.

Changing mapping configuration after processing invalidates previously calculated results and requires reprocessing.

---

# 5. Phase 1 Functional Requirements

## 5.1 Landing Page

**Route:** GET /

The landing page introduces the tool and offers immediate access to the working application.

### Required Elements

- Product name and description.
- Primary CTA: "Try with sample data."
- Secondary CTA: "Upload your CSV."
- Three-step explanation of the workflow.
- Example validation outcomes.
- GitHub link.
- Privacy and data retention explanation.
- Disclaimer that the public deployment is intended for synthetic or non-sensitive data until an appropriate privacy review has been completed.

### Acceptance Criteria

- A visitor can start the demo with one click.
- No registration is required.
- The first interaction does not require reading documentation.
- Mobile visitors can start the same workflow.

---

## 5.2 Temporary Guest Workspace

The application creates a temporary workspace when a visitor starts the tool.

### Requirements

- Workspaces are scoped to a secure session.
- Each workspace has a random UUID.
- Workspaces are not publicly enumerable.
- Guests cannot access other guest workspaces.
- Sessions use Secure, HttpOnly, and SameSite cookies in production.
- Every workspace has an expiration timestamp.
- All workspace data is automatically deleted after expiration.
- Browser requests cannot supply or change the owning workspace ID.
- Import, row, and export access must be authorized against the current session.

### Default Limits

| Setting                            | Default                            |
| ---------------------------------- | ---------------------------------- |
| Maximum CSV size                   | 2 MB                               |
| Maximum data rows                  | 1,000                              |
| Maximum columns                    | 50                                 |
| Maximum field length               | 2,000 characters                   |
| Maximum active imports per session | 3                                  |
| Workspace lifetime                 | 60 minutes                         |
| Maximum active processing jobs     | Configurable                       |
| Export lifetime                    | No longer than workspace           |
| Maximum processing time            | Configurable, initially 60 seconds |

These values should be stored in Laravel configuration and adjustable through environment variables.

The application must clearly communicate these limits.

### Expiration Behavior

After expiration:

- Uploaded files become unavailable.
- Staged records are deleted.
- Generated exports are deleted.
- Review decisions are deleted.
- Associated temporary metadata is removed.
- The browser sees a friendly "Workspace expired" page.
- The visitor can start again.

Expiration must be enforced by access queries, not only by a scheduled cleanup job.

Scheduled cleanup removes expired data even if users never return.

The interface should show expiration information before the user invests significant time reviewing a file.

---

## 5.3 Sample Data

**Primary public onboarding path**

Visitors must be able to explore the product without uploading anything.

### Requirements

- Provide a built-in synthetic dataset.
- Include approximately 100–200 records for the instant demo.
- Provide a separate 1,000-row stress-test fixture for development.
- Never use real donor information.
- Automatically load a predefined mapping configuration.
- Optionally bypass the mapping screen for the instant demo.
- Process the sample through the same backend services used for actual uploads.
- Allow reset and replay.

### Sample Data Issues

Include:

- Valid donations.
- Duplicate transaction IDs.
- Possible duplicates without IDs.
- Missing donor email addresses.
- Inconsistent campaign names.
- Invalid amount formats.
- Ambiguous dates.
- Missing required fields.
- Repeated monthly donations.
- Different currencies.
- Formula-like spreadsheet values.

### Acceptance Criteria

A visitor clicking "Try with sample data" reaches meaningful validation results without needing to configure columns manually.

---

## 5.4 CSV Upload

**Route:** GET /try

### Interface

- Drag-and-drop upload area.
- File picker.
- File size indicator.
- Upload progress.
- Validation messages.
- Privacy guidance.

### Supported Files

- CSV.
- UTF-8.
- UTF-8 with BOM.
- Common delimiters: comma, semicolon, tab.
- Quoted fields, embedded newlines, and escaped quotes.

Do not support XLS or XLSX in Phase 1.

### Backend Validation

- Enforce upload size before parsing.
- Validate content rather than relying on filename extensions.
- Reject binary files.
- Reject unsupported encodings.
- Enforce maximum row and column limits while streaming.
- Enforce maximum individual field length.
- Reject malformed quoting.
- Prevent unsafe file paths.
- Generate server-side storage filenames.
- Store files outside the public web root.
- Record original filenames only as metadata.
- Never execute or evaluate imported content.

### Repeated Files

Calculate a SHA-256 checksum.

If the same guest uploads the same file again during the current workspace lifetime:

- Warn that the file already exists.
- Offer to reopen existing results.
- Allow the visitor to explicitly reprocess if desired.

Repeated imports must never silently overwrite review decisions.

---

# 6. Column Mapping

## 6.1 Purpose

Fundraising partners use inconsistent terminology.

The mapping screen translates source columns into GivingLedger's standardized donation fields.

### Example

| Incoming column    | Canonical field      |
| ------------------ | -------------------- |
| Gift Date          | Donation Date        |
| Supporter          | Donor Name           |
| Contact Email      | Donor Email          |
| Gift Value         | Amount               |
| Transaction Number | External Donation ID |
| Fund               | Campaign             |
| Currency Code      | Currency             |

## 6.2 Required Capabilities

- Preview first 10 records.
- Display original headers.
- Suggest likely mappings.
- Allow manual overrides.
- Support ignored columns.
- Detect conflicting mappings.
- Explain required fields.
- Show representative sample values.
- Provide date-format selection.
- Provide a default-currency option for files without a currency column.
- Provide a source-timezone selection if timestamp fields lack timezone information.
- Provide decimal separator/number locale selection when needed.

## 6.3 Canonical Fields

| Field                | Required | Notes                              |
| -------------------- | -------- | ---------------------------------- |
| Donation Date        | Yes      | Date or timestamp                  |
| Amount               | Yes      | Monetary value                     |
| Currency             | Yes      | Column or declared file-wide value |
| External Donation ID | No       | Strong duplicate identifier        |
| Donor Name           | No       | Optional                           |
| Donor Email          | No       | Optional                           |
| Campaign             | No       | Free-text in Phase 1               |
| Donation Frequency   | No       | Once, recurring, unknown           |
| Payment Method       | No       | Free-text                          |
| Source Partner       | No       | Optional import metadata           |

A donation may be valid without a donor email or external transaction ID.

## 6.4 Mapping Suggestions

Implement deterministic header-alias matching.

Example:

`gift_amount`, `amount`, `donation_amount`, `value` → Amount.

Matching should:

- Ignore casing.
- Normalize spaces, underscores, and hyphens.
- Use a controlled alias dictionary.
- Never auto-confirm ambiguous matches.
- Not use external AI services.

## 6.5 Acceptance Criteria

- A user can map an unfamiliar CSV without modifying the file manually.
- Required field conflicts are explained.
- Mapping changes persist during the guest session.
- The same mapping configuration produces deterministic results.

---

# 7. Normalization Engine

The normalization engine converts mapped source values into consistent internal representations.

Normalization must always preserve original source values.

## 7.1 Money

Use integer minor units.

Examples:

- USD 125.50 → 12550
- USD 10.00 → 1000
- JPY 500 → 500

Never use floating-point values for financial calculations.

### Rules

- Support explicitly selected numeric locales.
- Reject ambiguous formats unless the user specifies the convention.
- Reject nonnumeric amounts.
- Reject zero amounts for ordinary donations.
- Flag negative amounts for review.
- Reject unsupported currencies.
- Respect currency decimal precision.
- Reject excess decimal places rather than silently rounding.
- Use exact decimal arithmetic.
- Calculate totals separately by currency.

## 7.2 Dates

Supported initial formats:

- YYYY-MM-DD
- MM/DD/YYYY
- DD/MM/YYYY
- YYYY/MM/DD
- ISO 8601 timestamps

Rules:

- Require explicit handling of ambiguous dates.
- Reject impossible dates.
- Flag future donation dates.
- Preserve original date strings.
- Preserve date-only precision when no time is supplied.
- Normalize timezone-aware timestamps to UTC.
- Do not fabricate a time for date-only values.

## 7.3 Donor Names

- Trim leading and trailing whitespace.
- Normalize repeated whitespace.
- Preserve capitalization as entered.
- Preserve Unicode characters.
- Do not infer missing names.

## 7.4 Email Addresses

- Trim whitespace.
- Validate syntax.
- Normalize domains for comparison.
- Preserve the entered value separately.
- Allow missing addresses.
- Flag invalid nonempty addresses for review.

## 7.5 Campaign Names

- Trim whitespace.
- Normalize for comparison.
- Preserve original text.
- Do not silently merge similar campaign names.
- Provide suggestions for inconsistent labels.

Example:

"Emergency Fund" and " emergency fund " can normalize to a common key.

"Emergency Relief" and "General Relief" must remain distinct unless a user explicitly chooses otherwise.

## 7.6 Donation Frequency

Normalize known values:

- one-time
- once
- single

To: `once`

And:

- monthly
- recurring
- subscription

To: `recurring`

Unknown values remain `unknown` or trigger review when incompatible.

---

# 8. Validation Engine

Validation occurs after normalization.

Each issue must have:

- Stable issue code.
- Human-readable message.
- Severity.
- Field name when applicable.
- Original input value when safe to display.
- Suggested corrective action when available.

## 8.1 Issue Severity

**Error**

The record cannot be included in a clean export until corrected.

**Warning**

The record may be legitimate but requires review.

**Information**

The system has made a non-destructive normalization or identified an optional observation.

## 8.2 Example Issue Codes

| Code               | Severity    | Meaning                                                     |
| ------------------ | ----------- | ----------------------------------------------------------- |
| INVALID_AMOUNT     | Error       | Amount cannot be parsed                                     |
| ZERO_AMOUNT        | Error       | Donation amount is zero                                     |
| AMBIGUOUS_AMOUNT   | Error       | Separator convention unclear                                |
| INVALID_DATE       | Error       | Date cannot be parsed                                       |
| AMBIGUOUS_DATE     | Error       | Date convention unclear                                     |
| FUTURE_DATE        | Warning     | Donation date is in the future                              |
| INVALID_CURRENCY   | Error       | Unsupported currency                                        |
| INVALID_EMAIL      | Warning     | Nonempty email is malformed                                 |
| EXACT_DUPLICATE    | Warning     | Transaction appears repeated                                |
| POSSIBLE_DUPLICATE | Warning     | Similar donation exists                                     |
| ID_CONFLICT        | Error       | Same source transaction ID has conflicting financial values |
| CAMPAIGN_VARIATION | Information | Campaign resembles another label                            |
| NEGATIVE_AMOUNT    | Warning     | Possible adjustment or refund                               |

## 8.3 Initial Record Statuses

- `valid`: Passes required checks and has no unresolved blocking issues.
- `needs_review`: Contains one or more unresolved warnings or errors.
- `rejected`: Cannot be made exportable without correction.
- `excluded`: User intentionally omitted record.
- `approved`: Eligible for the clean export.

A row's status must be derived from current validation and review decisions rather than directly editable by the browser.

---

# 9. Duplicate Detection

Duplicate detection is a central Phase 1 feature.

## 9.1 Scope

Phase 1 compares records within the current temporary workspace.

It does not compare against:

- Salesforce.
- Bank records.
- Other users' uploads.
- Expired sessions.
- Historical private imports.

These capabilities belong in Phase 2 or later.

## 9.2 Exact Identifier Matches

If two rows share the same nonempty external donation ID and source partner, compare financial fields.

When they agree:

- Mark as exact duplicate candidates.
- Explain the matching fields.
- Suggest keeping one record.

When they disagree:

- Flag an ID conflict.
- Show the conflicting amounts, currencies, or dates.
- Require a manual decision.

Do not silently choose which conflicting row is correct.

## 9.3 Possible Duplicates

When stable transaction IDs are missing, find candidates using:

- Same currency.
- Same amount.
- Same donation date.
- Matching donor email when present.
- Compatible donor name when present.

Require meaningful donor identity evidence before treating otherwise identical amounts and dates as probable duplicates.

Two different donors giving $50 on the same day must not automatically be treated as duplicates.

Recurring donations must not be treated as duplicates simply because they have identical amounts.

## 9.4 Duplicate Review Interface

Display candidate records side by side.

Include:

- Original row numbers.
- Donor information.
- Amount and currency.
- Donation date.
- Transaction ID.
- Campaign.
- Explanation of why the records matched.

Actions:

- Exclude this row as a duplicate.
- Keep both as distinct donations.
- Edit details.
- Leave unresolved.

Decisions must survive page refreshes during the guest session.

## 9.5 Revalidation

Changing a matching field must trigger duplicate detection again.

Previously resolved decisions must be invalidated when their underlying matching facts materially change.

Duplicate decisions are scoped to the relevant record versions.

## 9.6 Determinism

The same dataset, mapping, configuration, and decisions must yield the same result regardless of row processing order.

The system must use deterministic tie-breaking when recommending which of two otherwise identical rows to keep.

---

# 10. Review and Correction

## 10.1 Results Dashboard

After processing, display:

- Total imported rows.
- Valid rows.
- Rows requiring review.
- Rejected rows.
- Excluded rows.
- Approved rows.
- Duplicate candidates.
- Totals by currency.

Avoid presenting all imported donation amounts as "confirmed revenue." GivingLedger validates records but does not establish payment settlement.

## 10.2 Review Table

Required columns:

- Row number.
- Donor.
- Date.
- Amount.
- Currency.
- Campaign.
- Status.
- Issues.
- Actions.

Filters:

- All.
- Valid.
- Needs review.
- Rejected.
- Duplicates.
- Approved.
- Excluded.

Support:

- Pagination.
- Sorting.
- Search within current import.
- Row detail expansion.
- Inline correction.

## 10.3 Row Detail Panel

Show:

**Original record:** Immutable data as uploaded.

**Normalized record:** Current interpreted values.

**Issues:** Validation and duplicate warnings.

**Corrections:** Editable normalized fields.

**Decisions:** User resolutions.

Every correction must preserve both old and new values during the temporary session.

## 10.4 Approval

Valid records may be approved in bulk.

Rows with unresolved blocking issues cannot be approved.

The server must independently enforce this rule.

Before export, show a summary of:

- Approved rows.
- Excluded rows.
- Unresolved rows.
- Total amounts by currency.

---

# 11. CSV Export

## 11.1 Clean Export

The primary output is a standardized CSV containing approved records only.

### Fields

- source_row_number
- external_donation_id
- donor_name
- donor_email
- donation_date
- amount
- currency
- campaign
- donation_frequency
- payment_method
- source_partner

Export monetary amounts as exact decimal strings.

Do not perform currency conversions.

Do not include internal session identifiers.

## 11.2 Exceptions Export

Provide an optional separate CSV containing:

- Source row number.
- Original values.
- Current normalized values.
- Issue codes.
- Status.
- Resolution notes.

This allows operators to continue investigating unresolved records.

## 11.3 Spreadsheet Injection Prevention

Imported text may start with characters interpreted by spreadsheet software as formulas.

Implement an explicit, tested strategy for human-facing CSV downloads.

Do not assume standard CSV quoting alone prevents formula execution.

Because spreadsheet-safe escaping can change underlying values, avoid claiming the output is automatically compatible with every CRM.

Phase 1 should document its export format and provide a human-readable spreadsheet-safe profile. Any later target-specific CRM format should have separately tested rules.

## 11.4 Export Acceptance Criteria

- Only approved records appear in the clean export.
- The export preserves monetary precision.
- Column order is stable.
- CSV quoting is correct.
- Unicode survives.
- Exported records correspond to the current approved state.
- The export cannot be downloaded after workspace expiration.
- Another visitor cannot download the file using its URL.

---

# 12. Phase 1 Data Model

Phase 1 should not require organizations or user accounts.

Use temporary guest workspace records and staged donation data.

## 12.1 guest_workspaces

| Column               | Type              |
| -------------------- | ----------------- |
| id                   | UUID, primary key |
| session_binding_hash | string            |
| created_at           | timestamp         |
| expires_at           | timestamp         |
| last_activity_at     | timestamp         |
| state                | string            |

The session binding must be server-generated and never exposed in URLs or API responses.

Do not use the workspace UUID alone as proof of authorization.

## 12.2 import_batches

| Column                | Type               |
| --------------------- | ------------------ |
| id                    | UUID, primary key  |
| guest_workspace_id    | UUID, foreign key  |
| original_filename     | string             |
| private_file_path     | string             |
| sha256_checksum       | string             |
| source_partner        | nullable string    |
| mapping_config        | JSONB              |
| parsing_config        | JSONB              |
| status                | string             |
| total_rows            | integer            |
| processed_rows        | integer            |
| valid_rows            | integer            |
| review_rows           | integer            |
| rejected_rows         | integer            |
| processing_version    | integer            |
| processing_error_code | nullable string    |
| created_at            | timestamp          |
| completed_at          | nullable timestamp |
| expires_at            | timestamp          |

Statuses:

- `uploaded`
- `mapping_required`
- `queued`
- `processing`
- `completed`
- `failed`
- `expired`

## 12.3 import_rows

| Column            | Type              |
| ----------------- | ----------------- |
| id                | UUID, primary key |
| import_batch_id   | UUID, foreign key |
| row_number        | integer           |
| raw_data          | JSONB             |
| normalized_data   | JSONB             |
| corrected_data    | JSONB, nullable   |
| status            | string            |
| validation_issues | JSONB             |
| row_version       | integer           |
| created_at        | timestamp         |
| updated_at        | timestamp         |

Unique constraint: `(import_batch_id, row_number)`.

Store only data required for the workflow.

Sensitive input values must not appear in ordinary application logs.

## 12.4 duplicate_matches

| Column          | Type               |
| --------------- | ------------------ |
| id              | UUID               |
| import_batch_id | UUID               |
| first_row_id    | UUID               |
| second_row_id   | UUID               |
| match_type      | string             |
| match_reason    | JSONB              |
| status          | string             |
| decided_at      | nullable timestamp |

Match types:

- `exact_identifier`
- `possible_duplicate`
- `identifier_conflict`

Each unordered pair must be stored once.

## 12.5 review_decisions

| Column          | Type          |
| --------------- | ------------- |
| id              | UUID          |
| import_batch_id | UUID          |
| import_row_id   | UUID          |
| action          | string        |
| before_values   | JSONB         |
| after_values    | JSONB         |
| notes           | nullable text |
| row_version     | integer       |
| created_at      | timestamp     |

Phase 1 review history is temporary, not a permanent organization audit trail.

## 12.6 export_batches

| Column             | Type      |
| ------------------ | --------- |
| id                 | UUID      |
| guest_workspace_id | UUID      |
| import_batch_id    | UUID      |
| export_type        | string    |
| file_path          | string    |
| row_count          | integer   |
| data_version       | integer   |
| created_at         | timestamp |
| expires_at         | timestamp |

If the reviewed dataset changes, previous exports must be marked stale and no longer presented as current.

## 12.7 Phase 1 Data Restrictions

Do not create a permanent canonical `donations` table for guest uploads.

All guest donation records are staging records.

Do not use guest data to build a cross-user donor index.

Do not use donor fields for behavioral analytics.

Guest data must not enter Phase 2 organizational datasets automatically.

---

# 13. Backend Architecture

## 13.1 Core Principle

Separate the reconciliation engine from web controllers and data persistence.

The same normalization and validation services must be reusable in Phase 2.

## 13.2 Core Services

### CsvParserService

Responsibilities:

- Open uploaded CSV.
- Detect supported delimiter.
- Parse rows safely.
- Enforce limits.
- Return streaming row data.
- Report malformed CSV errors.

### ColumnMappingService

Responsibilities:

- Suggest mappings.
- Validate mapping definitions.
- Normalize column aliases.
- Identify conflicting assignments.

### DonationNormalizerService

Responsibilities:

- Convert mapped data into canonical donation values.
- Handle amount precision.
- Normalize dates.
- Normalize text.
- Preserve source values.
- Return structured normalization issues.

### DonationValidatorService

Responsibilities:

- Enforce financial-data rules.
- Validate required fields.
- Generate issue codes.
- Distinguish errors from warnings.
- Determine whether records are eligible for approval.

### DuplicateDetectionService

Responsibilities:

- Identify exact transaction matches.
- Find possible duplicate candidates.
- Produce explainable match reasons.
- Recompute candidates after edits.
- Avoid automatically discarding ambiguous rows.

### ReviewService

Responsibilities:

- Apply corrections.
- Validate review actions.
- Update record versions.
- Resolve duplicate candidates.
- Record temporary review decisions.
- Recalculate export eligibility.

### CsvExportService

Responsibilities:

- Generate clean CSVs.
- Generate exception CSVs.
- Apply export safety rules.
- Preserve exact financial values.
- Use private file storage.
- Check export version and expiry.

### WorkspaceService

Responsibilities:

- Create guest workspaces.
- Resolve workspace identity from the session.
- Authorize access.
- Enforce expiration.
- Remove expired workspaces and files.

## 13.3 Jobs

Use Laravel's queue system for processing tasks.

Required jobs:

- `ProcessImportBatch`
- `GenerateCsvExport`
- `PurgeExpiredGuestWorkspaces`

Use database-backed queues initially.

A separate worker should process jobs outside the HTTP request lifecycle.

## 13.4 Job Reliability

- Retrying processing must not create duplicate rows.
- Jobs must verify workspace expiration.
- Processing must be tied to a specific mapping and processing version.
- Only one active processing job per import version is allowed.
- Partial processing must be recoverable or safely replaceable.
- Do not publish incomplete results as completed.
- Use explicit job timeouts.
- Enforce concurrency limits.
- Retry only recoverable failures.
- Log operational metadata without logging donor data.

---

# 14. Public Routes and Endpoints

Use Laravel's web middleware, session handling, and request-forgery protections.

All state-changing requests require CSRF protection.

## 14.1 Page Routes

| Method | Route                           | Purpose             |
| ------ | ------------------------------- | ------------------- |
| GET    | `/`                             | Landing page        |
| GET    | `/try`                          | Public upload page  |
| GET    | `/try/imports/{import}/mapping` | Column mapping      |
| GET    | `/try/imports/{import}/review`  | Results and review  |
| GET    | `/privacy`                      | Privacy information |
| GET    | `/about`                        | About the project   |

## 14.2 Action Endpoints

| Method | Route                                      | Purpose                   |
| ------ | ------------------------------------------ | ------------------------- |
| POST   | `/try/sample`                              | Create sample import      |
| POST   | `/try/imports`                             | Upload CSV                |
| POST   | `/try/imports/{import}/mapping`            | Save mapping              |
| POST   | `/try/imports/{import}/process`            | Start processing          |
| GET    | `/try/imports/{import}/status`             | Processing progress       |
| GET    | `/try/imports/{import}/rows`               | Paginated records         |
| GET    | `/try/imports/{import}/duplicates`         | Duplicate candidates      |
| PATCH  | `/try/imports/{import}/rows/{row}`         | Correct record            |
| POST   | `/try/imports/{import}/rows/{row}/resolve` | Resolve exception         |
| POST   | `/try/imports/{import}/approve`            | Approve eligible records  |
| POST   | `/try/imports/{import}/exports`            | Generate export           |
| GET    | `/try/exports/{export}/download`           | Download CSV              |
| POST   | `/try/reset`                               | Reset temporary workspace |

All nested resource IDs must be checked against the current workspace.

A valid UUID from another workspace must return a non-disclosing authorization response.

## 14.3 Response Conventions

Use JSON for asynchronous actions.

Successful responses:

```json
{
  "data": {
    "status": "processing",
    "processed_rows": 600,
    "total_rows": 1000
  }
}
```

Validation errors:

```json
{
  "error": {
    "code": "INVALID_MAPPING",
    "message": "A donation amount column is required.",
    "fields": {
      "amount": ["Select a source column."]
    }
  }
}
```

Expired workspaces return HTTP 410 with a documented error code.

Concurrent stale writes return HTTP 409.

Use consistent handling for 401, 403, 404, 413, 422, 429, and 500-class responses as appropriate.

---

# 15. Frontend Architecture

## 15.1 Technology

- Blade templates.
- HTML.
- CSS.
- Vanilla JavaScript.
- Fetch API.
- Vite.

Do not use React, Vue, Alpine, Livewire, or Inertia in Phase 1.

The UI should be mostly server-rendered, with JavaScript used for dynamic functionality.

## 15.2 Blade Responsibilities

Blade should render:

- Page layouts.
- Navigation.
- Forms.
- Import preview tables.
- Results tables.
- Empty states.
- Errors.
- Reusable UI components.

Use Laravel's HTML escaping and CSRF features.

## 15.3 JavaScript Responsibilities

Vanilla JavaScript should handle:

- Drag-and-drop uploads.
- Upload feedback.
- Column mapping selection.
- Processing progress polling.
- Review filters.
- Duplicate comparison interactions.
- Inline correction forms.
- Modal dialogs.
- Export status.
- Toast messages.
- Session expiration notices.

Avoid creating an unnecessary client-side application framework.

## 15.4 JavaScript Modules

Suggested structure:

```text
resources/js/
  app.js
  modules/
    api.js
    upload.js
    mapping.js
    processing.js
    review-table.js
    duplicate-review.js
    row-editor.js
    export.js
    notifications.js
```

Modules should use native ES module imports and exports.

Every fetch action must handle failure states.

User-submitted donor values must be rendered using safe text APIs, not unsanitized `innerHTML`.

---

# 16. UI Screens

## Screen 1 — Landing

Primary content:

- Clear product value proposition.
- "Try with sample data" button.
- "Upload your CSV" button.
- Short workflow explanation.
- Privacy notice.

Success metric: visitor starts the tool quickly.

## Screen 2 — Upload

Primary content:

- Upload zone.
- Supported format and limits.
- Sample dataset alternative.
- File validation errors.

Success metric: user supplies usable data.

## Screen 3 — Mapping

Primary content:

- Original source columns.
- Sample values.
- Target-field selectors.
- Date and number format options.
- Mapping warnings.

Success metric: user understands what each source field means.

## Screen 4 — Processing

Primary content:

- Processing progress.
- Rows processed.
- Current stage.
- Recoverable failure messages.

Success metric: user understands what the application is doing.

## Screen 5 — Results

Primary content:

- Validation summary.
- Filters.
- Record table.
- Duplicate count.
- Error count.
- Export readiness.

Success metric: user can identify and prioritize issues.

## Screen 6 — Duplicate Comparison

Primary content:

- Side-by-side comparison.
- Matching reasons.
- Highlighted differences.
- Keep/exclude actions.
- Manual review option.

Success metric: user can make an informed duplicate decision.

## Screen 7 — Row Editor

Primary content:

- Original row.
- Normalized row.
- Validation issues.
- Editable fields.
- Save and revalidate action.

Success metric: user can correct a record without returning to a spreadsheet.

## Screen 8 — Export

Primary content:

- Approved record count.
- Excluded and unresolved counts.
- Totals by currency.
- Clean CSV download.
- Optional exceptions download.
- Expiration reminder.

Success metric: user gets the intended output.

---

# 17. Security and Privacy

The public demo is an unauthenticated file-processing service. Security and resource controls are first-class product requirements.

## 17.1 Guest Isolation

- Bind every guest workspace to a secure session.
- Never rely solely on a UUID in the URL for authorization.
- Validate ownership at the controller or policy layer.
- Use scoped queries for nested resources.
- Test horizontal access attempts across sessions.

## 17.2 Upload Safety

- Validate actual content.
- Reject unsupported files.
- Enforce file, row, column, and field limits.
- Prevent decompression or parser resource exhaustion.
- Do not execute imported data.
- Store files privately.
- Sanitize generated download filenames.
- Escape displayed content.

## 17.3 Abuse Prevention

- Apply IP- and session-based rate limits.
- Limit active jobs.
- Limit per-workspace storage.
- Restrict upload processing time.
- Configure request and worker memory limits.
- Return clear errors when limits are reached.
- Monitor aggregate resource use.
- Add stronger bot protection only if operationally necessary.

## 17.4 Data Retention

- Default guest workspace lifetime: 60 minutes.
- No permanent donor records.
- No donor values in operational logs.
- No third-party analytics tracking on pages containing uploaded donor information unless independently reviewed and appropriately configured.
- No routine backups of temporary donor content.
- Scheduled deletion of expired files and rows.
- Document unavoidable infrastructure-level temporary storage or backup behavior.

## 17.5 Privacy Notice

The interface must explain:

- What information is uploaded.
- How processing occurs.
- Whether data reaches the server.
- How long data is stored.
- What security limits apply.
- How deletion works.
- That the tool does not verify payments.
- That the public deployment is not yet approved for sensitive production donor data.

A 60-minute deletion policy is not a substitute for appropriate privacy, security, and legal review before real donor data is accepted.

## 17.6 Export Safety

Implement spreadsheet-formula injection defenses and test them with hostile inputs.

Do not claim one CSV export format is simultaneously ideal for every spreadsheet and CRM.

---

# 18. Automated Testing

Use Pest or PHPUnit.

## 18.1 Unit Tests

Cover:

- CSV parsing.
- Delimiter handling.
- Quoted fields.
- Amount parsing.
- Currency precision.
- Date parsing.
- Email normalization.
- Campaign normalization.
- Duplicate matching.
- Issue severity.
- Export formatting.

## 18.2 Feature Tests

Cover:

- Public workspace creation.
- Upload validation.
- Sample data initialization.
- Column mapping.
- Background processing.
- Row correction.
- Duplicate resolution.
- Approval.
- CSV export.
- Workspace expiration.

## 18.3 Security Tests

Required cases:

1. Guest A cannot access Guest B's import.
2. Guest A cannot modify Guest B's rows.
3. Expired workspaces cannot generate exports.
4. Uploaded files cannot be accessed directly through public storage.
5. Uploaded HTML or JavaScript is displayed as text.
6. Formula-like values cannot execute in the supported spreadsheet export profile.
7. Rate limits prevent excessive upload attempts.
8. Oversized CSV files are rejected safely.
9. Excessively long fields do not exhaust processing resources.
10. Concurrent requests cannot corrupt review state.

## 18.4 Reliability Tests

Required cases:

1. Retrying a processing job does not duplicate staged records.
2. A crash halfway through processing produces no misleading completed result.
3. Reprocessing after changing mapping configuration invalidates stale results.
4. Changing a corrected donation amount triggers duplicate checks again.
5. Two simultaneous review actions cannot overwrite each other silently.
6. Repeated sample imports produce predictable outcomes.
7. Exported values retain exact currency precision.
8. Failed exports do not leave downloadable partial files.
9. Expiration during background processing stops further access and results in cleanup.
10. Approval is rejected when unresolved blocking issues remain.

## 18.5 Performance Tests

Initial targets:

- Process the 1,000-row public limit without request timeouts.
- Keep typical review interactions responsive.
- Paginate records on the server.
- Limit memory usage during CSV parsing.
- Keep progress updates responsive.

Measure actual performance before publishing benchmarks.

---

# 19. Phase 1 Delivery Plan

## Milestone 1 — Foundation

Build:

- Laravel project.
- PostgreSQL.
- Blade layout.
- Public routing.
- Guest workspace service.
- Temporary storage.
- Database migrations.
- Docker setup.

**Completion criteria:** Visitors can start isolated temporary sessions without logging in.

## Milestone 2 — Sample Data and Import

Build:

- Synthetic fixture.
- Upload interface.
- CSV parser.
- Import records.
- Processing jobs.
- Status polling.

**Completion criteria:** Sample data and uploaded CSVs are parsed into temporary staging records.

## Milestone 3 — Mapping and Validation

Build:

- Column mapping UI.
- Mapping suggestions.
- Normalization engine.
- Validation rules.
- Results dashboard.

**Completion criteria:** Visitors can map and validate arbitrary supported CSV layouts.

## Milestone 4 — Duplicate Review

Build:

- Exact duplicate detection.
- Possible duplicate detection.
- Side-by-side comparison.
- Record correction.
- Resolution actions.
- Revalidation.

**Completion criteria:** Visitors can resolve ambiguous duplicate cases without deleting unrelated records.

## Milestone 5 — Approval and Export

Build:

- Approval workflow.
- Export readiness checks.
- Clean CSV.
- Exceptions CSV.
- Spreadsheet safety.
- Expiring download access.

**Completion criteria:** Approved records can be exported accurately and safely.

## Milestone 6 — Release Hardening

Build:

- Abuse prevention.
- Expiration cleanup.
- Security tests.
- Reliability tests.
- Accessible UI.
- Deployment configuration.
- Public documentation.
- README and demo walkthrough.

**Completion criteria:** An unfamiliar visitor can complete the demo without assistance, and all critical tests pass.

---

# 20. Phase 1 Definition of Done

The MVP is complete when:

- Visitors can use the application without creating an account.
- The instant sample demo works from start to finish.
- Visitors can upload supported CSV files.
- Mapping works with unfamiliar column names.
- Normalization does not silently change ambiguous financial values.
- Duplicate detection identifies exact and probable matches.
- Users can inspect and resolve exceptions.
- Users can edit normalized values.
- The system revalidates edits.
- Only approved records enter the clean export.
- CSV exports preserve financial precision.
- Workspaces are isolated between visitors.
- Expired records and files are inaccessible and cleaned up.
- File-processing limits are enforced.
- No production donor data is required for public demonstration.
- Core unit, feature, reliability, and security tests pass.
- The code is publicly documented and deployable.

**Phase 1 must be a useful standalone product, not merely a preview of Phase 2.**

---

# 21. Phase 2 — Private Organizational Workspaces

Phase 2 begins after the public MVP is complete and validated with users.

The public demo remains available.

## 21.1 Product Model

Users may sign in to create and manage persistent organizational workspaces.

Private organizations can:

- Import larger files.
- Save mapping configurations.
- Maintain historical donation records.
- Review duplicates across imports.
- Manage campaigns.
- Collaborate.
- Preserve corrections and approvals.
- Track exports.
- Configure data retention.

## 21.2 Authentication

Use Laravel's built-in authentication ecosystem.

Requirements:

- Login and logout.
- Secure password hashing.
- Password reset.
- Email verification.
- Session management.
- Login throttling.
- Organization-level authorization.

Optional future enhancements:

- SSO.
- Multi-factor authentication.
- Enterprise identity providers.

## 21.3 Organizations

Introduce an `organizations` table.

Fields:

- id
- name
- slug
- settings
- created_at
- updated_at

All persistent business records must belong to an organization.

## 21.4 User Membership

Introduce an `organization_memberships` table.

Fields:

- id
- user_id
- organization_id
- role
- created_at
- updated_at

Roles:

**Administrator**

- Manage users and organization settings.
- Manage data retention.
- Review, approve, and export donations.

**Operator**

- Upload files.
- Review records.
- Correct issues.
- Approve and export records.

**Viewer**

- Inspect imports and approved records.
- Cannot modify or export donor data unless explicitly permitted.

All actions must be enforced server-side.

## 21.5 Persistent Donation Records

Introduce a canonical `donations` table for approved records.

Fields:

- id
- organization_id
- source_import_id
- source_row_id
- external_donation_id
- source_partner
- donor_name
- donor_email
- amount_minor
- currency
- donation_date
- campaign_id
- donation_frequency
- payment_method
- approved_by
- approved_at
- created_at
- updated_at

Use scoped unique identifiers where appropriate.

Do not assume donor email, donation date, and amount alone establish transaction uniqueness.

## 21.6 Import History

Retain:

- Source metadata.
- Mapping configuration.
- Processing outcome.
- Validation issues.
- Approval status.
- Export history.
- Review decisions.

Retention must be configurable.

Deleting raw files should not automatically destroy required historical reconciliation metadata.

## 21.7 Mapping Presets

Allow organizations to save configurations for repeated partner imports.

Example:

"Corporate Giving Partner A — Monthly Donations"

Presets may contain:

- Header mappings.
- Date format.
- Number format.
- Currency setting.
- Partner identity.
- Campaign aliases.

## 21.8 Campaign Management

Create:

- Campaign records.
- Campaign aliases.
- External CRM identifiers.

Unknown campaign labels can be reviewed and mapped.

Mapping changes should not silently rewrite previously approved historical records without a controlled operation.

## 21.9 Cross-Import Deduplication

Extend DuplicateDetectionService to compare incoming staging records against an organization's approved donations.

Flag:

- Repeated external transaction IDs.
- Conflicting financial values.
- Probable duplicates.
- Repeated partner submissions.

Preserve manual review for ambiguous matches.

## 21.10 Audit History

Introduce durable append-only audit records for:

- Imports.
- Mapping changes.
- Edits.
- Duplicate decisions.
- Approvals.
- Exports.
- Data deletion.
- Administrative changes.

Avoid storing unnecessary sensitive personal data in audit payloads.

## 21.11 Private Export History

Provide:

- Export timestamps.
- User identities.
- File type.
- Record counts.
- Dataset version.
- Source import references.
- Download history when required.

All exports remain organization-scoped.

## 21.12 Phase 2 Migration Strategy

Do not automatically promote guest records to persistent organizational records.

Shared reconciliation services should be reused, but ownership and retention must remain distinct.

The initial implementation should depend on clear processing interfaces rather than checking whether a user is authenticated inside every normalization service.

Recommended separation:

- Core domain services: no knowledge of users or organizations.
- Public application layer: guest ownership and temporary persistence.
- Private application layer: organization ownership and permanent persistence.
- Infrastructure layer: database, file storage, queues, exports.

Do not copy guest data into private storage without an explicit user action and a clearly defined authorization process.

---

# 22. Future Integrations (After Private Workspaces)

## Salesforce

Potential capabilities:

- CRM field mapping.
- Read-only reconciliation.
- Matching donations to existing CRM records.
- Controlled import dry runs.
- Idempotent CRM writes.
- Retry and exception handling.

## Stripe

Potential capabilities:

- Signed webhook ingestion.
- Payment status reconciliation.
- Duplicate event handling.
- Refund tracking.
- Recurring payment monitoring.

## Additional Platforms

Potential integrations:

- Donor management systems.
- Fundraising partner exports.
- Secure file transfers.
- Cloud storage.
- Scheduled ingestion.

These integrations must be independently designed and tested. They must not be implemented as shortcuts inside the Phase 1 public workflow.

---

# 23. Suggested Repository Structure

```text
givingledger/
├── app/
│   ├── Domain/
│   │   └── Donations/
│   │       ├── Data/
│   │       ├── Enums/
│   │       ├── Rules/
│   │       └── Services/
│   │           ├── CsvParserService.php
│   │           ├── ColumnMappingService.php
│   │           ├── DonationNormalizerService.php
│   │           ├── DonationValidatorService.php
│   │           ├── DuplicateDetectionService.php
│   │           └── CsvExportService.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Public/
│   │   ├── Middleware/
│   │   └── Requests/
│   ├── Jobs/
│   ├── Models/
│   ├── Policies/
│   └── Services/
│       ├── WorkspaceService.php
│       └── ReviewService.php
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   ├── landing/
│   │   ├── public/
│   │   │   ├── upload.blade.php
│   │   │   ├── mapping.blade.php
│   │   │   ├── processing.blade.php
│   │   │   ├── review.blade.php
│   │   │   └── export.blade.php
│   │   └── components/
│   ├── js/
│   │   ├── app.js
│   │   └── modules/
│   └── css/
│       └── app.css
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── tests/
│   ├── Unit/
│   ├── Feature/
│   ├── Security/
│   └── Fixtures/
├── routes/
│   ├── web.php
│   └── console.php
├── docker/
├── README.md
├── CONTRIBUTING.md
├── LICENSE
├── docker-compose.yml
├── composer.json
└── package.json
```

The architecture should favor Laravel conventions and avoid unnecessary interfaces or abstraction layers before multiple implementations actually exist.

---

# 24. Release and Deployment Requirements

## Development

Provide a Docker Compose setup with:

- Laravel application.
- PHP runtime.
- PostgreSQL.
- Queue worker.
- Scheduler.

Use Vite for frontend assets.

## Production

Require:

- HTTPS.
- Secure sessions.
- Private upload storage.
- Database backups that exclude temporary donor content where practical.
- Worker supervision.
- Scheduled cleanup.
- Rate limits.
- Operational logging.
- Health checks.
- Deployment documentation.

## Open-Source Readiness

Include:

- Installation instructions.
- Environment configuration.
- Sample data.
- Architecture overview.
- Security disclosure instructions.
- Contribution guidelines.
- License.
- Known limitations.
- Privacy and retention notes.
- A documented way to run all tests.

---

# 25. Success Criteria

## Phase 1 Metrics

**Usability**

- Visitors can initiate the sample demo in one click.
- Nontechnical users can complete the full workflow.
- Review warnings are understandable.

**Correctness**

- Monetary amounts retain exact precision.
- Parsing ambiguities are not silently resolved.
- Duplicate candidates are explainable.
- Clean exports contain only approved records.

**Reliability**

- Import retries do not duplicate records.
- Processing failures do not corrupt state.
- Temporary workspaces expire correctly.
- Guests cannot access each other's data.

**Practical value**

- Pilot users can prepare a CSV for downstream importing.
- Users identify issues they otherwise would have corrected manually.
- Manual cleanup time can be measured against their existing process.

Do not claim time savings or error reductions until validated through real use.

## Phase 2 Metrics

- Organizations actively importing data.
- Repeat partner imports using saved presets.
- Cross-import duplicates detected.
- Time required to review exceptions.
- Successful approved exports.
- Correctness of CRM synchronization when added.

---

# 26. Final Product Requirements

GivingLedger must remain:

1. **Useful without authentication.** The public tool is a real product.
2. **Conservative with financial data.** Never guess when the consequences affect monetary correctness.
3. **Transparent.** Every duplicate suggestion and validation issue should be understandable.
4. **Recoverable.** Failures and retries must not silently corrupt results.
5. **Private by design.** Guest data is temporary and isolated.
6. **Maintainable.** Use Laravel conventions and a small vanilla JavaScript frontend.
7. **Extensible.** Private workspaces reuse the same processing engine rather than replacing it.
8. **Focused.** CRM integrations and expanded workflows do not delay delivery of the core utility.

**Definition of success:** Someone with a messy donation CSV can visit GivingLedger, understand what's wrong with their data, correct it, and download a reliable cleaned version—without needing an account or developer assistance.
