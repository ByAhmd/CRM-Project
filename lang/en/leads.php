<?php

declare(strict_types=1);

// Leads (decision D-7).
return [

    'navigation' => [
        'label' => 'Leads',
        'model' => 'Lead',
        'plural_model' => 'Leads',
    ],

    'tabs' => [
        'open' => 'Open',
        'qualified' => 'Qualified',
        'unqualified' => 'Unqualified',
        'converted' => 'Converted',
        'all' => 'All',
    ],

    'sections' => [
        'person' => 'Lead details',
        'classification' => 'Status and scoring',
        'contact' => 'Contact details',
        'ownership' => 'Owner and tags',
        'conversion' => 'Conversion',
        'status_history' => 'Status history',
        'notes' => 'Notes',
        'convert_account' => 'Account',
        'convert_contact' => 'Contact',
        'convert_deal' => 'Deal',
    ],

    'fields' => [
        'name' => 'Name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'company_name' => 'Company',
        'job_title' => 'Job title',
        'email' => 'Email',
        'phone' => 'Phone',
        'website' => 'Website',
        'city' => 'City',
        'source' => 'Source',
        'lead_source_id' => 'Source',
        'status' => 'Status',
        'status_note' => 'Note',
        'priority' => 'Priority',
        'score' => 'Score',
        'score_override' => 'Manual score',
        'owner' => 'Owner',
        'qualified_at' => 'Qualified on',
        'qualified_by' => 'Qualified by',
        'last_activity_at' => 'Last activity',
        'converted_at' => 'Converted on',
        'converted_by' => 'Converted by',
        'converted_account' => 'Account',
        'converted_contact' => 'Contact',
        'converted_deal' => 'Deal',
        'account_mode' => 'Account',
        'account_name' => 'Account name',
        'account_id' => 'Existing account',
        'contact_mode' => 'Contact',
        'contact_id' => 'Existing contact',
        'create_deal' => 'Create a deal',
        'deal_title' => 'Deal title',
        'pipeline' => 'Pipeline',
        'deal_amount' => 'Amount',
        'expected_close_date' => 'Expected close date',
        'conversion_note' => 'Note',
        'description' => 'Notes',
        'created_by' => 'Created by',
        'created_at' => 'Created',
        'updated_at' => 'Last updated',
    ],

    'helpers' => [
        'initial_status' => 'The status the lead starts in. Later changes go through the "Change status" action so they are logged.',
        'status_readonly' => 'Use the "Change status" action to move this lead.',
        'score_override' => 'Leave empty to use the computed score (0–100).',
        'status_note' => 'Required when moving the lead into a qualified status.',
        'account_mode' => 'The company becomes an account. Choose "no account" for an individual.',
        'contact_existing' => 'A contact with the same email already exists: :name.',
        'create_deal' => 'Opens a deal in the first stage of the chosen pipeline, owned by the lead owner.',
    ],

    'filters' => [
        'status' => 'Status',
        'source' => 'Source',
        'priority' => 'Priority',
        'owner' => 'Owner',
        'trashed' => 'Deleted',
    ],

    'actions' => [
        'change_status' => 'Change status',
        'change_status_heading' => 'Change lead status',
        'change_status_submit' => 'Change status',
        'convert' => 'Convert',
        'convert_heading' => 'Convert lead',
        'convert_submit' => 'Convert',
    ],

    'options' => [
        'account_mode' => [
            'new' => 'Create a new account',
            'existing' => 'Link an existing account',
            'none' => 'No account (individual)',
        ],
        'contact_mode' => [
            'new' => 'Create a new contact',
            'existing' => 'Link an existing contact',
        ],
    ],

    'notifications' => [
        'status_changed' => 'Status changed to :status',
        'bulk_status_changed' => ':changed leads updated, :failed skipped',
        'converted' => 'Lead converted: :name',
    ],

    'history' => [
        'changed_at' => 'When',
        'from' => 'From',
        'to' => 'To',
        'by' => 'By',
        'notes' => 'Note',
    ],

    'validation' => [
        'already_converted' => 'This lead has already been converted and can no longer change.',
        'inactive_status' => 'That status is inactive and cannot be used.',
        'converted_status_reserved' => 'The converted status is set by converting the lead, not by hand.',
        'initial_status_reserved' => 'A lead cannot be created as qualified or converted: create it in an initial status, then qualify it with a qualification note.',
        'qualification_note_required' => 'A qualification note is required to mark a lead as qualified.',
        'not_qualified' => 'Only a qualified lead can be converted.',
        'converted_status_missing' => 'No status of kind "Converted" is configured; ask an administrator to add one.',
        'account_name_required' => 'An account name is required to create the account.',
        'account_not_accessible' => 'That account does not exist or is outside the records you can access.',
        'contact_not_accessible' => 'That contact does not exist or is outside the records you can access.',
        'pipeline_has_no_default_stage' => 'The chosen pipeline has no default stage, so the deal cannot be created.',
        'pipeline_inactive' => 'The chosen pipeline does not exist or is inactive, so the deal cannot be created.',
    ],

    'empty' => [
        'heading' => 'No leads yet',
        'description' => 'Add the first lead to start working the pipeline.',
    ],

];
