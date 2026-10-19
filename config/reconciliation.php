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

    'max_absolute_money_minor_units' => (int) env('RECONCILIATION_MAX_ABSOLUTE_MONEY_MINOR_UNITS', 10_000_000_000),

    'allow_arbitrary_uploads' => (bool) env('RECONCILIATION_ALLOW_ARBITRARY_UPLOADS', false),

    'reference_fixture_manifest' => resource_path('fixtures/reference-v1/manifest.json'),

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
