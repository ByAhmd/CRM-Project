<?php

declare(strict_types=1);

return [
    'navigation' => 'Dashboard',
    'title' => 'Dashboard',

    'filters' => [
        'period' => 'Period',
        'from' => 'From',
        'to' => 'To',
        'owner' => 'Owner',
        'team' => 'Team',
        'pipeline' => 'Pipeline',
        'reset' => 'Reset filters',
    ],

    'placeholders' => [
        'all_owners' => 'All owners',
        'all_teams' => 'All teams',
        'all_pipelines' => 'All pipelines',
    ],

    'helpers' => [
        'owner' => 'Only the people whose records you may see.',
        'range' => 'Up to :days days.',
    ],

    'validation' => [
        'range_too_large' => 'The period may not exceed :days days.',
        'to_before_from' => 'The end date must be on or after the start date.',
    ],

    'kpis' => [
        'new_leads' => 'New leads',
        'new_leads_description' => ':qualified qualified · :converted converted',
        'conversion_rate' => 'Conversion rate',
        'conversion_rate_description' => 'Converted of the leads created in the period',
        'open_deals' => 'Open deals',
        'open_deals_description' => ':amount',
        'weighted_pipeline' => 'Weighted pipeline',
        'weighted_pipeline_description' => 'Amount × probability of the open deals',
        'won' => 'Won',
        'won_description' => ':amount',
        'lost' => 'Lost',
        'lost_description' => ':amount',
        'win_rate' => 'Win rate',
        'win_rate_description' => 'Won of the deals closed in the period',
    ],

    'charts' => [
        'leads_by_status' => 'Open leads by status',
        'pipeline_by_stage' => 'Open deals by stage',
        'pipeline_by_stage_default' => 'Open deals by stage · :pipeline',
        'revenue_by_month' => 'Revenue won by month',
        'activity_counts' => 'Activities by kind',
        'dataset_amount' => 'Amount',
        'dataset_weighted' => 'Weighted',
        'dataset_count' => 'Count',
        'dataset_leads' => 'Leads',
        'dataset_revenue' => 'Won amount',
    ],

    'tables' => [
        'my_tasks_today' => 'My tasks today',
        'my_tasks_today_description' => ':today due today · :overdue overdue',
        'upcoming_follow_ups' => 'Upcoming follow-ups',
        'upcoming_follow_ups_description' => 'Follow-ups, calls and meetings in the next :days days',
        'stale_deals' => 'Stale deals',
        'stale_deals_description' => 'Open deals without activity for :days days',
        'columns' => [
            'title' => 'Title',
            'kind' => 'Kind',
            'priority' => 'Priority',
            'due_at' => 'Due',
            'subject' => 'Related to',
            'assignee' => 'Assignee',
            'account' => 'Account',
            'stage' => 'Stage',
            'amount' => 'Amount',
            'owner' => 'Owner',
            'last_activity_at' => 'Last activity',
        ],
    ],

    'empty' => [
        'tasks' => 'Nothing due today',
        'tasks_description' => 'No open task assigned to you is due or overdue.',
        'follow_ups' => 'No upcoming follow-ups',
        'follow_ups_description' => 'No follow-up, call or meeting is due in the coming days.',
        'stale_deals' => 'No stale deals',
        'stale_deals_description' => 'Every open deal in your reach was touched recently.',
        'chart' => 'Nothing to chart',
        'chart_description' => 'No records match the current filters.',
    ],
];
