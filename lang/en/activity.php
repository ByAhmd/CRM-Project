<?php

declare(strict_types=1);

// The audit ledger.
return [

    'navigation' => [
        'label' => 'Audit log',
        'model' => 'Audit entry',
        'plural_model' => 'Audit log',
    ],

    'fields' => [
        'recorded_at' => 'Date and time',
        'area' => 'Area',
        'action' => 'Event',
        'subject' => 'Affected record',
        'causer' => 'By',
    ],

    'filters' => [
        'date_range' => 'Period',
        'from' => 'From',
        'until' => 'Until',
        'area' => 'Area',
        'event' => 'Event',
        'causer' => 'User',
    ],

    'actions' => [
        'details' => 'Details',
        'close' => 'Close',
    ],

    'details' => [
        'heading' => 'Event details',
        'event' => 'Event',
        'subject' => 'Record',
        'causer' => 'By',
        'recorded_at' => 'Date and time',
        'from_to' => 'from ":old" to ":new"',
    ],

    'log_names' => [
        'auth' => 'Sign-in',
        'user' => 'Users',
        'role' => 'Roles',
        'team' => 'Teams',
        'settings' => 'Settings',
    ],

    'events' => [
        'auth.login' => 'Signed in',
        'auth.logout' => 'Signed out',
        'auth.failed' => 'Failed sign-in attempt',
        'auth.password_reset' => 'Password reset',
        'user.created' => 'User created',
        'user.updated' => 'User updated',
        'user.deleted' => 'User deleted',
        'user.restored' => 'User restored',
        'user.invited' => 'Invitation sent',
        'user.roles_changed' => 'User roles changed',
        'role.created' => 'Role created',
        'role.updated' => 'Role updated',
        'role.deleted' => 'Role deleted',
        'role.permissions_changed' => 'Role permissions changed',
        'team.created' => 'Team created',
        'team.updated' => 'Team updated',
        'team.deleted' => 'Team deleted',
        'team.restored' => 'Team restored',
        'settings.updated' => 'Settings updated',
        'settings.lookup_created' => 'Settings item added',
        'settings.lookup_updated' => 'Settings item updated',
        'settings.lookup_deleted' => 'Settings item deleted',
        'settings.lookup_restored' => 'Settings item restored',
    ],

    'subject_types' => [
        'User' => 'User',
        'Role' => 'Role',
        'Team' => 'Team',
        'LeadSource' => 'Lead source',
        'LeadStatus' => 'Lead status',
        'Industry' => 'Industry',
        'Pipeline' => 'Pipeline',
        'PipelineStage' => 'Pipeline stage',
        'ActivityType' => 'Activity type',
        'DealCloseReason' => 'Close reason',
        'Competitor' => 'Competitor',
        'Tag' => 'Tag',
        'Product' => 'Product',
    ],

    'subject' => [
        'deleted' => 'Deleted :type (#:id)',
    ],

    'attributes' => [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Mobile number',
        'locale' => 'Language',
        'status' => 'Status',
        'team_id' => 'Team',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'manager_user_id' => 'Team manager',
        'is_active' => 'Active',
        'is_default' => 'Default',
        'kind' => 'Kind',
        'color' => 'Colour',
        'icon' => 'Icon',
        'sort' => 'Sort order',
        'probability' => 'Probability',
        'pipeline_id' => 'Pipeline',
        'code' => 'Code',
        'unit_price' => 'Unit price',
        'website' => 'Website',
        'notes' => 'Notes',
        'added' => 'Added',
        'removed' => 'Removed',
        'changes' => 'Changes',
        'ip' => 'IP address',
        'known_account' => 'Known account',
    ],

    'values' => [
        'yes' => 'Yes',
        'no' => 'No',
    ],

    'sources' => [
        'system' => 'System',
    ],

    'placeholders' => [
        'no_subject' => '—',
        'unknown_account' => 'Unknown account',
    ],

    'empty' => [
        'heading' => 'No events recorded',
        'description' => 'Every change and security event appears here as it happens.',
    ],

];
