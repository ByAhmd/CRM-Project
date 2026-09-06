<?php

declare(strict_types=1);

// CSV imports and their history (module 18, decisions D-1, D-4).
return [

    'navigation' => [
        'label' => 'Imports',
        'model' => 'Import',
        'plural_model' => 'Imports',
    ],

    'sections' => [
        'summary' => 'Import run',
        'failed_rows' => 'Failed rows',
    ],

    'fields' => [
        'file_name' => 'File',
        'entity' => 'Records',
        'user' => 'Imported by',
        'total_rows' => 'Rows',
        'processed_rows' => 'Processed',
        'successful_rows' => 'Imported',
        'failed_rows' => 'Failed',
        'status' => 'Status',
        'created_at' => 'Started',
        'completed_at' => 'Completed',
        'duplicate_strategy' => 'Duplicates',
        'row_data' => 'Row',
        'error' => 'Reason',
    ],

    'columns' => [
        'lead' => [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'company_name' => 'Company',
            'job_title' => 'Job title',
            'email' => 'Email',
            'phone' => 'Phone',
            'website' => 'Website',
            'address_line' => 'Address',
            'city' => 'City',
            'region' => 'Region',
            'country' => 'Country (code)',
            'postal_code' => 'Postal code',
            'source' => 'Source',
            'status' => 'Status',
            'priority' => 'Priority',
            'owner' => 'Owner (email)',
            'tags' => 'Tags',
            'description' => 'Notes',
        ],
        'contact' => [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'account' => 'Company',
            'job_title' => 'Job title',
            'department' => 'Department',
            'email' => 'Email',
            'mobile' => 'Mobile',
            'phone' => 'Phone',
            'preferred_locale' => 'Correspondence language',
            'linkedin_url' => 'LinkedIn URL',
            'is_primary' => 'Primary contact',
            'address_line' => 'Address',
            'city' => 'City',
            'region' => 'Region',
            'country' => 'Country (code)',
            'postal_code' => 'Postal code',
            'owner' => 'Owner (email)',
            'tags' => 'Tags',
            'description' => 'Notes',
        ],
        'account' => [
            'name' => 'Company name',
            'type' => 'Type',
            'industry' => 'Industry',
            'size' => 'Company size',
            'website' => 'Website',
            'email' => 'Email',
            'phone' => 'Phone',
            'address_line' => 'Address',
            'city' => 'City',
            'region' => 'Region',
            'country' => 'Country (code)',
            'postal_code' => 'Postal code',
            'parent' => 'Parent company',
            'owner' => 'Owner (email)',
            'tags' => 'Tags',
            'description' => 'Notes',
        ],
        'deal' => [
            'title' => 'Title',
            'account' => 'Account',
            'contact' => 'Primary contact',
            'pipeline' => 'Pipeline',
            'stage' => 'Stage',
            'amount' => 'Amount',
            'probability' => 'Probability (%)',
            'expected_close_date' => 'Expected close date',
            'forecast_category' => 'Forecast category',
            'source' => 'Source',
            'owner' => 'Owner (email)',
            'tags' => 'Tags',
            'description' => 'Notes',
        ],
    ],

    'options' => [
        'duplicate_strategy' => [
            'skip' => 'Skip duplicates',
            'update' => 'Update duplicates',
        ],
    ],

    'helpers' => [
        'duplicate_strategy' => 'A duplicate is an existing record you can see with the same email or phone (accounts: the same name or email; deals: the same title on the same account).',
        'failed_rows' => 'The first :limit failed rows are shown here; the download holds every failed row with its reason.',
    ],

    'statuses' => [
        'completed' => 'Completed',
        'failed' => 'Failed',
        'processing' => 'Processing',
    ],

    'notifications' => [
        'completed' => ':successful rows imported, :failed rows failed.',
    ],

    'validation' => [
        'duplicate' => 'Duplicate of record #:id.',
        'update_forbidden' => 'You are not allowed to update record #:id.',
        'create_forbidden' => 'You are not allowed to create records of this type; the row was not imported.',
        'converted_status' => 'The converted status is set by converting the lead, not by importing.',
        'owner_out_of_reach' => 'The owner ":value" is not a user you can assign to.',
        'unknown_lookup' => 'Unknown value ":value".',
        'account_not_found' => 'The account ":value" does not exist or is outside your reach.',
        'stage_not_open' => 'The stage ":value" is a closed stage; a deal must start in an open stage.',
        'stage_outside_pipeline' => 'The stage ":value" belongs to another pipeline.',
        'pipeline_unavailable' => 'No default pipeline with an open stage is configured; ask an administrator.',
    ],

    'actions' => [
        'import' => 'Import :label',
        'download_failed_rows' => 'Download failed rows',
        'view' => 'View',
    ],

    'filters' => [
        'entity' => 'Records',
        'from' => 'From',
        'until' => 'Until',
    ],

    'empty' => [
        'heading' => 'No imports yet',
        'description' => 'Import a CSV file from a list page and the run appears here.',
    ],

];
