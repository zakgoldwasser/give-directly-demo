<?php

return [
    'max_csv_size_bytes' => (int) env('RECONCILIATION_MAX_CSV_SIZE_BYTES', 1_048_576),

    'max_data_rows' => (int) env('RECONCILIATION_MAX_DATA_ROWS', 2_000),

    'max_columns' => (int) env('RECONCILIATION_MAX_COLUMNS', 50),

    'max_field_length' => (int) env('RECONCILIATION_MAX_FIELD_LENGTH', 4_096),

    'max_active_imports_per_session' => (int) env('RECONCILIATION_MAX_ACTIVE_IMPORTS', 3),

    'workspace_lifetime_minutes' => (int) env('RECONCILIATION_WORKSPACE_LIFETIME_MINUTES', 1_440),

    'max_processing_seconds' => (int) env('RECONCILIATION_MAX_PROCESSING_SECONDS', 20),

    'operation_lease_seconds' => (int) env('RECONCILIATION_OPERATION_LEASE_SECONDS', 60),

    'new_workspaces_enabled' => (bool) env('RECONCILIATION_NEW_WORKSPACES_ENABLED', true),

    'workspace_start_limits' => [
        'per_session' => (int) env('RECONCILIATION_WORKSPACE_STARTS_PER_SESSION_PER_MINUTE', 5),
        'per_ip' => (int) env('RECONCILIATION_WORKSPACE_STARTS_PER_IP_PER_MINUTE', 5),
    ],

    'workspace_work_limits' => [
        'per_session' => (int) env('RECONCILIATION_WORK_PER_SESSION_PER_MINUTE', 10),
        'per_ip' => (int) env('RECONCILIATION_WORK_PER_IP_PER_MINUTE', 10),
    ],

    'cleanup_batch_size' => (int) env('RECONCILIATION_CLEANUP_BATCH_SIZE', 100),

    'cleanup_lock_seconds' => (int) env('RECONCILIATION_CLEANUP_LOCK_SECONDS', 120),

    'readiness_secret' => env('RECONCILIATION_READINESS_SECRET'),

    'cron_secret' => env('CRON_SECRET'),

    'max_absolute_money_minor_units' => (int) env('RECONCILIATION_MAX_ABSOLUTE_MONEY_MINOR_UNITS', 10_000_000_000),

    'allow_arbitrary_uploads' => (bool) env('RECONCILIATION_ALLOW_ARBITRARY_UPLOADS', false),

    'reference_fixture_manifest' => resource_path('fixtures/reference-v1/manifest.json'),

    'sample_scenarios' => [
        'monthly_v1' => [
            'title' => 'Update this month’s donations',
            'badge' => 'Start here',
            'contains' => 'New donations, existing donations that already match, and one incorrect CRM amount. Payouts balance.',
            'demonstrates' => 'Identify what to add, correct, and leave unchanged, then download approved changes.',
            'manifest' => resource_path('fixtures/monthly-v1/manifest.json'),
        ],
        'review_v1' => [
            'title' => 'Resolve records that need review',
            'badge' => 'Review decisions',
            'contains' => 'A repeated donation row, duplicate CRM records, a possible match missing its transaction ID, and an unfamiliar campaign code. Payouts balance.',
            'demonstrates' => 'Distinguish repeated rows from conflicting records, confirm supported matches, and keep unresolved donations excluded.',
            'manifest' => resource_path('fixtures/review-v1/manifest.json'),
        ],
        'payouts_v1' => [
            'title' => 'Explain a payout difference',
            'badge' => 'Payout checks',
            'contains' => 'Donations with fees and refunds, one payout that balances after deductions, and another with an unexplained difference. CRM identities are straightforward.',
            'demonstrates' => 'Explain the difference between gross donations and payouts, and distinguish expected deductions from a discrepancy needing investigation.',
            'manifest' => resource_path('fixtures/payouts-v1/manifest.json'),
        ],
        'reference_v1' => [
            'title' => 'Full reconciliation example',
            'badge' => 'Advanced',
            'contains' => 'The full sample combines matching, duplicates, campaign review, refunds, and payout exceptions.',
            'demonstrates' => 'Work through a realistic combination of cases after learning the individual steps.',
            'manifest' => resource_path('fixtures/reference-v1/manifest.json'),
        ],
    ],

    'rule_version' => 'demo_v1',

    'campaign_mappings' => [
        'GENERAL' => 'CAMPAIGN-GENERAL',
        'EMERGENCY' => 'CAMPAIGN-EMERGENCY',
    ],

    'campaign_suggestions' => [
        'NEW-PROGRAM' => 'CAMPAIGN-NEW-PROGRAM',
    ],

    'github_url' => filled(env('RECONCILIATION_GITHUB_URL'))
        ? env('RECONCILIATION_GITHUB_URL')
        : '#',
];
