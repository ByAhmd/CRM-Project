<?php

declare(strict_types=1);

// Activities: immutable events logged against leads, contacts, accounts and deals (decision A-10).
return [

    'navigation' => [
        'label' => 'Activities',
        'model' => 'Activity',
        'plural_model' => 'Activities',
    ],

    'tabs' => [
        'all' => 'All',
        'mine' => 'Mine',
        'calls' => 'Calls',
        'meetings' => 'Meetings',
        'emails' => 'Emails',
    ],

    'sections' => [
        'details' => 'Activity details',
        'related' => 'Related records',
        'ownership' => 'Ownership',
    ],

    'fields' => [
        'type' => 'Type',
        'kind' => 'Kind',
        'subject' => 'Subject',
        'body' => 'Details',
        'occurred_at' => 'Occurred at',
        'direction' => 'Direction',
        'duration_minutes' => 'Duration (minutes)',
        'outcome' => 'Outcome',
        'lead' => 'Lead',
        'contact' => 'Contact',
        'account' => 'Account',
        'deal' => 'Deal',
        'related' => 'Related record',
        'owner' => 'Owner',
        'created_by' => 'Logged by',
        'created_at' => 'Logged at',
    ],

    'placeholders' => [
        'subject' => 'What happened, in one line',
        'outcome' => 'e.g. Left a voicemail',
    ],

    'helpers' => [
        'related' => 'Link the activity to at least one record.',
        'type' => 'The kind of the type decides which fields apply: direction for calls and emails, duration for calls and meetings.',
    ],

    'filters' => [
        'kind' => 'Kind',
        'type' => 'Type',
        'owner' => 'Owner',
        'occurred_from' => 'Occurred from',
        'occurred_until' => 'Occurred until',
        'subject' => 'Linked to',
    ],

    'actions' => [
        'log' => 'Log activity',
        'log_heading' => 'Log an activity',
        'log_submit' => 'Log',
        'delete' => 'Delete',
    ],

    'notifications' => [
        'logged' => 'Activity logged',
        'deleted' => 'Activity deleted',
    ],

    'validation' => [
        'subject_required' => 'Link the activity to at least one lead, contact, account or deal.',
        'inactive_type' => 'That activity type is inactive and cannot be used.',
        'system_type_missing' => 'The system activity type for this kind is missing. Run the activity type seeder.',
        'immutable' => 'Activities are immutable events: they cannot be edited once logged. Delete and log again to correct one.',
        'record_not_accessible' => 'You cannot link to this record: it is outside your reach.',
    ],

    'empty' => [
        'heading' => 'No activities yet',
        'description' => 'Log the first call, meeting or email to start the timeline.',
    ],

];
