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

    // The example row of the downloadable CSV template, written in the downloader's language.
    // Lookup names match the seeded rows so the example imports as it is.
    'examples' => [
        'lead' => [
            'first_name' => 'Fahad',
            'last_name' => 'Al-Qahtani',
            'company_name' => 'Horizon Company',
            'job_title' => 'Procurement Manager',
            'address_line' => 'King Fahd Road',
            'city' => 'Riyadh',
            'region' => 'Riyadh Region',
            'source' => 'Website',
            'status' => 'New',
            'tags' => 'VIP|Enterprise',
            'description' => 'Met at the Riyadh expo.',
        ],
        'contact' => [
            'first_name' => 'Noura',
            'last_name' => 'Al-Otaibi',
            'account' => 'Horizon Company',
            'job_title' => 'Marketing Manager',
            'department' => 'Marketing',
            'address_line' => 'King Fahd Road',
            'city' => 'Riyadh',
            'region' => 'Riyadh Region',
            'tags' => 'VIP|Enterprise',
            'description' => 'Prefers email in the morning.',
        ],
        'account' => [
            'name' => 'Horizon Company',
            'industry' => 'Technology',
            'address_line' => 'King Fahd Road',
            'city' => 'Riyadh',
            'region' => 'Riyadh Region',
            'parent' => 'Horizon Holding Group',
            'tags' => 'VIP|Enterprise',
            'description' => 'Regional distributor.',
        ],
        'deal' => [
            'title' => 'Computer equipment supply',
            'account' => 'Horizon Company',
            'contact' => 'Noura Al-Otaibi',
            'pipeline' => 'Sales',
            'stage' => 'Qualification',
            'source' => 'Website',
            'tags' => 'VIP|Enterprise',
            'description' => 'Renewal of the 2025 contract.',
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
        'failed_rows' => '{1} The first failed row is shown here; the download holds every failed row with its reason.|[2,*] The first :limit failed rows are shown here; the download holds every failed row with its reason.',
    ],

    'statuses' => [
        'completed' => 'Completed',
        'failed' => 'Failed',
        'processing' => 'Processing',
    ],

    'notifications' => [
        'completed' => '{0} No rows were imported.|{1} 1 row imported.|[2,*] :count rows imported.',
        'failed' => '{0} No rows failed.|{1} 1 row failed.|[2,*] :count rows failed.',
    ],

    'validation' => [
        'duplicate' => 'Duplicate of record #:id.',
        'update_forbidden' => 'You are not allowed to update record #:id.',
        'create_forbidden' => 'You are not allowed to create records of this type; the row was not imported.',
        'converted_status' => 'The converted status is set by converting the lead, not by importing.',
        'qualified_status' => 'A lead cannot be imported as qualified: import it in an initial status, then qualify it with a qualification note.',
        'type_forbidden' => 'You are not allowed to set the account type ":value" by hand; a prospect becomes a customer when its first deal is won.',
        'owner_out_of_reach' => 'The owner ":value" is not a user you can assign to.',
        'unknown_lookup' => 'Unknown value ":value".',
        'account_not_found' => 'The account ":value" does not exist or is outside the records you can access.',
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
