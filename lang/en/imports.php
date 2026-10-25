<?php

declare(strict_types=1);

// CSV and Excel imports and their history (module 18, decisions D-1, D-4).
return [

    'navigation' => [
        'label' => 'Imports',
        'model' => 'Import',
        'plural_model' => 'Imports',
    ],

    'sections' => [
        'summary' => 'Import run',
        'failed_rows' => 'Failed rows',
        'undo' => 'Undo',
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
        'mode' => 'Type',
        'dry_run' => 'Check only, write nothing',
        'undo_requested_at' => 'Undo requested',
        'undone_at' => 'Undo completed',
        'undone_by' => 'Undone by',
        'undone_rows' => 'Records deleted',
        'kept_rows' => 'Records kept',
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

    // A real import, or a check-only run that wrote nothing (D-29).
    'modes' => [
        'import' => 'Import',
        'dry_run' => 'Check only',
    ],

    'helpers' => [
        'dry_run' => 'The whole file is read and validated, and the import history shows the rows that would be refused with their reasons; no record is created or changed. Untick it to import the file for real.',
        'file' => 'CSV files and Excel workbooks (.xlsx). Excel 97-2003 (.xls) and OpenDocument (.ods) are not supported; open the file and save it as .xlsx, then upload that. Only the first worksheet is read, and its first row is the header row.',
        'duplicate_strategy' => 'A duplicate is an existing record you can see with the same email or phone (accounts: the same name or email; deals: the same title on the same account).',
        'failed_rows' => '{1} The first failed row is shown here; the download holds every failed row with its reason.|[2,*] The first :limit failed rows are shown here; the download holds every failed row with its reason.',
    ],

    'statuses' => [
        'completed' => 'Completed',
        'failed' => 'Failed',
        'processing' => 'Processing',
        'undoing' => 'Undoing',
        'undone' => 'Undone',
    ],

    'notifications' => [
        // Appended to Filament's own «import started» notice (A-7 amendment, 2026-10-25).
        'started' => [
            'body' => 'Processing usually starts within a minute, and the result reaches your notifications when it is done.',
        ],
        'completed' => '{0} No rows were imported.|{1} 1 row imported.|[2,*] :count rows imported.',
        'failed' => '{0} No rows failed.|{1} 1 row failed.|[2,*] :count rows failed.',
        'multiple_sheets' => [
            'title' => 'Only the first worksheet was read',
            'body' => '{0} The file holds no worksheets.|{1} The file holds one worksheet, ":sheet".|[2,*] The file holds :count worksheets; the first one, ":sheet", was read and the rest were left out.',
        ],
        // A check-only run (D-29): what would have been imported, and that nothing was written.
        'dry_run' => [
            'title' => 'File check completed',
            'valid' => '{0} No rows would be imported.|{1} 1 row would be imported.|[2,*] :count rows would be imported.',
            'nothing_written' => 'Nothing was written.',
            'started_body' => 'Check only: the file is read in the background and nothing is written; the result reaches your notifications.',
        ],
        // Undoing a run (D-29): the toast when the actor confirms, the bell notice when the job is done.
        'undo_started' => [
            'title' => 'Import undo started',
            'body' => 'What the import created is being deleted in the background, usually starting within a minute. The result reaches your notifications when it is done.',
        ],
        'undo_completed' => [
            'title' => 'Import undo completed',
            'undone' => '{0} No records were deleted.|{1} 1 record deleted.|[2,*] :count records deleted.',
            'kept' => '{0}|{1} 1 record kept.|[2,*] :count records kept.',
        ],
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
        'unreadable_spreadsheet' => 'The file could not be read; make sure it is an undamaged .xlsx workbook.',
        'empty_spreadsheet' => 'The first worksheet is empty; the file must start with a header row.',
    ],

    'actions' => [
        'import' => 'Import :label',
        'download_failed_rows' => 'Download failed rows',
        'view' => 'View',
        'track_progress' => 'Track progress',
        'undo' => 'Undo import',
    ],

    // The undo confirmation (D-29): the counts are said before anything happens;
    // each `keeps_*` sentence is shown only when its count is above zero.
    'confirmations' => [
        'undo' => [
            'heading' => 'Undo this import?',
            'submit' => 'Undo',
            'will_delete' => '{0} There is no record to delete.|{1} 1 record this import created and nobody has touched since will be deleted.|[2,*] :count records this import created and nobody has touched since will be deleted.',
            'restorable' => 'The deletion can be reversed from Deleted records.',
            'keeps_updated' => '{0}|{1} 1 record stays: it was updated, not created.|[2,*] :count records stay: they were updated, not created.',
            'keeps_touched' => '{0}|{1} 1 record stays: it was changed or worked on after the import.|[2,*] :count records stay: they were changed or worked on after the import.',
            'keeps_forbidden' => '{0}|{1} 1 record stays: you may not delete it.|[2,*] :count records stay: you may not delete them.',
            'keeps_deleted' => '{0}|{1} 1 record is already deleted.|[2,*] :count records are already deleted.',
            'nothing' => 'There is nothing to undo: everything this import created has since been changed or deleted, or is outside what you may delete.',
            'queued' => 'The undo runs in the background, usually starting within a minute; the result reaches your notifications.',
        ],
    ],

    'filters' => [
        'entity' => 'Records',
        'from' => 'From',
        'until' => 'Until',
    ],

    'empty' => [
        'heading' => 'No imports yet',
        'description' => 'Import a CSV or Excel file from a list page and the run appears here.',
    ],

];
