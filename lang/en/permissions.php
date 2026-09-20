<?php

declare(strict_types=1);

// Labels for App\Enums\Permission: groups are the prefix, verbs the suffix.
return [

    'groups' => [
        'lead' => 'Leads',
        'contact' => 'Contacts',
        'account' => 'Accounts (companies and customers)',
        'deal' => 'Deals',
        'activity' => 'Activities',
        'task' => 'Tasks',
        'note' => 'Notes',
        'attachment' => 'Attachments',
        'email' => 'Email',
        'email_template' => 'Email templates',
        'product' => 'Products',
        'reports' => 'Reports',
        'imports' => 'Imports',
        'exports' => 'Exports',
        'audit' => 'Audit log',
        'settings' => 'Settings',
        'users' => 'Users',
        'teams' => 'Teams',
        'roles' => 'Roles',
    ],

    'verbs' => [
        'view_any' => 'View own records',
        'view_team' => 'View team records',
        'view_all' => 'View all records',
        'create' => 'Create',
        'update' => 'Edit',
        'delete' => 'Delete',
        'restore' => 'Restore',
        'assign' => 'Reassign to another user',
        'change_status' => 'Change status',
        'convert' => 'Convert to customer',
        'export' => 'Export',
        'import' => 'Import',
        'merge' => 'Merge duplicates',
        'set_type' => 'Set the lifecycle type by hand',
        'change_stage' => 'Change stage',
        'close' => 'Close (won / lost)',
        'download' => 'Download',
        'send' => 'Send',
        'view' => 'View',
        'manage' => 'Manage',
    ],

];
