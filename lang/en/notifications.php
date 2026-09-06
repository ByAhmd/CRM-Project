<?php

declare(strict_types=1);

// Notification preferences and the event notifications (plan section 3.6, decision D-10).
return [

    'navigation' => [
        'label' => 'Notification preferences',
    ],

    'title' => 'Notification preferences',

    'sections' => [
        'records' => 'Records',
        'tasks' => 'Tasks',
        'deals' => 'Deals',
        'leads' => 'Leads',
        'notes' => 'Notes',
    ],

    'fields' => [
        'database' => 'In the app',
        'mail' => 'By email',
    ],

    'helpers' => [
        'database' => 'Shown in the bell at the top of every page.',
        'mail_not_configured' => 'Email is not configured on this installation; your choice is kept for when it is.',
    ],

    'actions' => [
        'save' => 'Save',
    ],

    'notifications' => [
        'saved' => 'Notification preferences saved',
    ],

    'validation' => [
        'unknown_event' => 'Unknown notification event ":event".',
        'invalid_channel' => 'The channels for ":event" must be two yes/no values: in the app and by email.',
    ],

    'events' => [
        'deal_stage_changed' => [
            'title' => 'Your deal moved to another stage',
            'body' => ':by moved the deal ":record" from :from to :to.',
        ],
        'deal_closed' => [
            'title_won' => 'A deal was won',
            'title_lost' => 'A deal was lost',
            'body_won' => ':by marked the deal ":record" as won (:reason).',
            'body_lost' => ':by marked the deal ":record" as lost (:reason).',
        ],
        'lead_converted' => [
            'title' => 'Your lead was converted',
            'body' => ':by converted the lead ":record".',
            'account' => 'Account: :account',
            'contact' => 'Contact: :contact',
            'deal' => 'Deal: :deal',
        ],
        'lead_stale' => [
            'title' => 'A lead of yours has gone quiet',
            'body' => '{1} The lead ":record" has had no activity for one day.|[2,*] The lead ":record" has had no activity for :days days.',
        ],
        'note_mention' => [
            'title' => 'You were mentioned in a note',
            'body' => ':author mentioned you in a note on ":record": :excerpt',
        ],
    ],

    'common' => [
        'greeting' => 'Hello :name,',
        'open' => 'Open record',
    ],

];
