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
    ],

    'notifications' => [
        'status_changed' => 'Status changed to :status',
        'bulk_status_changed' => ':changed leads updated, :failed skipped',
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
        'qualification_note_required' => 'A qualification note is required to mark a lead as qualified.',
    ],

    'empty' => [
        'heading' => 'No leads yet',
        'description' => 'Add the first lead to start working the pipeline.',
    ],

];
