<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guest Workspace Limits
    |--------------------------------------------------------------------------
    |
    | Phase 1 public demo limits from the product specification. Each value
    | is adjustable through environment variables for local and production use.
    |
    */

    'max_csv_size_bytes' => (int) env('GIVINGLEDGER_MAX_CSV_SIZE_BYTES', 2 * 1024 * 1024),

    'max_data_rows' => (int) env('GIVINGLEDGER_MAX_DATA_ROWS', 1000),

    'max_columns' => (int) env('GIVINGLEDGER_MAX_COLUMNS', 50),

    'max_field_length' => (int) env('GIVINGLEDGER_MAX_FIELD_LENGTH', 2000),

    'max_active_imports_per_session' => (int) env('GIVINGLEDGER_MAX_ACTIVE_IMPORTS', 3),

    'workspace_lifetime_minutes' => (int) env('GIVINGLEDGER_WORKSPACE_LIFETIME_MINUTES', 60),

    'max_active_processing_jobs' => (int) env('GIVINGLEDGER_MAX_ACTIVE_PROCESSING_JOBS', 1),

    'max_processing_seconds' => (int) env('GIVINGLEDGER_MAX_PROCESSING_SECONDS', 60),

    'github_url' => filled(env('GIVINGLEDGER_GITHUB_URL'))
        ? env('GIVINGLEDGER_GITHUB_URL')
        : '#',

];
