<?php

declare(strict_types=1);

// The record timeline: one chronological feed per lead, contact, account and deal (module row 12, D-13).
return [

    'section' => 'Timeline',

    'kinds' => [
        'activity' => 'Activity',
        'note' => 'Note',
        'task' => 'Task',
        'status_change' => 'Status change',
        'stage_change' => 'Stage change',
        'attachment' => 'Attachment',
        'assignment' => 'Assignment',
        'conversion' => 'Conversion',
        'lifecycle' => 'Lifecycle',
        'audit' => 'Audit',
    ],

    'titles' => [
        'note_added' => ':author added a note',
        'task_created' => 'Task created: :title',
        'task_completed' => 'Task completed: :title',
        'status_changed' => 'Status changed: :from → :to',
        'stage_changed' => 'Stage changed: :from → :to',
        'attachment_uploaded' => 'File attached: :name',
        'assigned' => 'Owner changed: :from → :to',
        'unassigned' => 'Owner removed',
        'merged' => 'Merged with :label',
        'converted' => 'Lead converted',
        'converted_from' => 'Created by converting the lead :label',
        'became_customer' => 'Became a customer',
        'created' => 'Record created',
        'restored' => 'Record restored',
        'deleted' => 'Record deleted',
        'qualified' => 'Lead qualified',
        'won' => 'Deal won',
        'lost' => 'Deal lost',
        'reopened' => 'Deal reopened',
    ],

    'fields' => [
        'by' => 'By',
        'duration' => 'Time in previous stage',
        'pinned' => 'Pinned',
        'size' => 'Size',
        'priority' => 'Priority',
        'due' => 'Due',
        'outcome' => 'Outcome',
        'lead' => 'Lead',
        'account' => 'Account',
        'contact' => 'Contact',
        'deal' => 'Deal',
        'amount' => 'Amount',
        'close_reason' => 'Close reason',
    ],

    'values' => [
        'yes' => 'Yes',
    ],

    'formats' => [
        'meta' => ':label: :value',
    ],

    'actions' => [
        'load_more' => 'Load more',
    ],

    'empty' => [
        'heading' => 'Nothing on the timeline yet',
        'description' => 'Activities, notes, tasks, files and changes will appear here as they happen.',
    ],

    'hints' => [
        'capped' => '{1} Showing the latest entry only. Older items remain in the activities, notes and history sections of the record.|[2,*] Showing the latest :count entries. Older items remain in the activities, notes and history sections of the record.',
        'loading' => 'Loading the timeline…',
    ],

];
