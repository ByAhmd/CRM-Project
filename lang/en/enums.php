<?php

declare(strict_types=1);

// Every enum label lives here and nowhere else.
return [

    'user_status' => [
        'active' => 'Active',
        'pending' => 'Invitation pending',
        'disabled' => 'Disabled',
    ],

    'roles' => [
        'super_admin' => 'Super administrator',
        'admin' => 'Administrator',
        'sales_manager' => 'Sales manager',
        'sales_rep' => 'Sales representative',
        'support' => 'Support',
        'read_only' => 'Read only',
    ],

    'visibility_level' => [
        'none' => 'Nothing',
        'own' => 'Own records',
        'team' => 'Team records',
        'all' => 'All records',
    ],

    'lead_status_kind' => [
        'new' => 'New',
        'working' => 'Working',
        'qualified' => 'Qualified',
        'unqualified' => 'Unqualified',
        'converted' => 'Converted',
    ],

    'stage_kind' => [
        'open' => 'Open',
        'won' => 'Won',
        'lost' => 'Lost',
    ],

    'activity_kind' => [
        'call' => 'Call',
        'meeting' => 'Meeting',
        'email' => 'Email',
        'note' => 'Note',
        'task' => 'Task',
        'system' => 'System',
        'other' => 'Other',
    ],

    'close_reason_kind' => [
        'won' => 'Win reason',
        'lost' => 'Loss reason',
    ],

    'badge_color' => [
        'primary' => 'Blue',
        'gray' => 'Gray',
        'success' => 'Green',
        'warning' => 'Orange',
        'danger' => 'Red',
        'info' => 'Sky',
    ],

    'account_type' => [
        'prospect' => 'Prospect',
        'customer' => 'Customer',
        'partner' => 'Partner',
        'other' => 'Other',
    ],

    'company_size' => [
        '1_10' => '1 – 10 employees',
        '11_50' => '11 – 50 employees',
        '51_200' => '51 – 200 employees',
        '201_500' => '201 – 500 employees',
        '501_1000' => '501 – 1000 employees',
        '1000_plus' => 'More than 1000 employees',
    ],

    'lead_priority' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
    ],

    'lead_scoring_rule_kind' => [
        'source' => 'Lead source',
        'status' => 'Lead status',
        'field_filled' => 'Field filled in',
        'activity_recency' => 'Recent activity',
    ],

    'deal_status' => [
        'open' => 'Open',
        'won' => 'Won',
        'lost' => 'Lost',
    ],

    'forecast_category' => [
        'pipeline' => 'Pipeline',
        'best_case' => 'Best case',
        'commit' => 'Commit',
        'omitted' => 'Omitted',
    ],

    'task_status' => [
        'pending' => 'Pending',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    'task_priority' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],

    'task_kind' => [
        'task' => 'Task',
        'follow_up' => 'Follow-up',
        'call' => 'Call',
        'meeting' => 'Meeting',
    ],

    'recurrence_frequency' => [
        'none' => 'Does not repeat',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
    ],

    'activity_direction' => [
        'inbound' => 'Inbound',
        'outbound' => 'Outbound',
    ],

    'deal_contact_role' => [
        'decision_maker' => 'Decision maker',
        'influencer' => 'Influencer',
        'champion' => 'Champion',
        'user' => 'End user',
        'other' => 'Other',
    ],

    'custom_field_type' => [
        'text' => 'Short text',
        'textarea' => 'Long text',
        'number' => 'Whole number',
        'decimal' => 'Decimal number',
        'date' => 'Date',
        'datetime' => 'Date and time',
        'boolean' => 'Yes / no',
        'select' => 'Single choice',
        'multiselect' => 'Multiple choice',
        'url' => 'Link',
        'email' => 'Email',
    ],

    'custom_field_entity' => [
        'lead' => 'Leads',
        'contact' => 'Contacts',
        'account' => 'Accounts',
        'deal' => 'Deals',
    ],

];
