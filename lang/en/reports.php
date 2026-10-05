<?php

declare(strict_types=1);

// The nine report pages (module 23, decisions D-4, D-8, D-13).
return [

    'navigation' => [
        'leads' => 'Lead report',
        'funnel' => 'Conversion funnel',
        'pipeline' => 'Pipeline report',
        'sales' => 'Sales performance',
        'activities' => 'Activity report',
        'sources' => 'Source performance',
        'win_loss' => 'Win / loss report',
        'forecast' => 'Forecast report',
        'tasks' => 'Task performance',
        'employee' => 'Employee dashboard',
    ],

    'pages' => [
        'leads' => [
            'title' => 'Lead report',
            'description' => 'Leads created in the period, grouped by status, source or owner, with how many were qualified and converted.',
        ],
        'funnel' => [
            'title' => 'Conversion funnel',
            'description' => 'How many leads entered each stage of the funnel during the period — new, contacted, qualified, converted — and the conversion between the steps.',
        ],
        'pipeline' => [
            'title' => 'Pipeline report',
            'description' => 'The open deals of a pipeline by stage: count, amount, weighted amount and how long they have been open.',
        ],
        'sales' => [
            'title' => 'Sales performance',
            'description' => 'Won and lost deals per owner in the period, win rate, average deal size, sales cycle and the open pipeline.',
        ],
        'activities' => [
            'title' => 'Activity report',
            'description' => 'Calls, meetings, emails and notes logged in the period, per user or over time.',
        ],
        'sources' => [
            'title' => 'Source performance',
            'description' => 'Leads created per source in the period, how many qualified and converted, and the deals won from each source.',
        ],
        'win_loss' => [
            'title' => 'Win / loss report',
            'description' => 'Deals won and lost in the period, month by month, and the reasons they closed.',
        ],
        'forecast' => [
            'title' => 'Forecast report',
            'description' => 'Open deals by expected close month and forecast category: amount and weighted amount for the coming six months.',
        ],
        'tasks' => [
            'title' => 'Task performance',
            'description' => 'Tasks completed per assignee in the period, on time or late, how long they took on average, what is still open and what is overdue now.',
        ],
        'employee' => [
            'title' => 'Employee dashboard',
            'description' => "One employee's task work on one screen: what is open and overdue now, what they completed this week and this month, the last eight weeks, what needs attention and their latest updates.",
        ],
    ],

    'sections' => [
        'filters' => 'Filters',
        'chart' => 'Chart',
        'table' => 'Details',
    ],

    'filters' => [
        'from' => 'From',
        'to' => 'To',
        'owner' => 'Owner',
        'team' => 'Team',
        'pipeline' => 'Pipeline',
        'group_by' => 'Group by',
        'run' => 'Run report',
        'reset' => 'Reset',
        'all_owners' => 'Everyone in my scope',
        'all_teams' => 'All teams',
        'all_pipelines' => 'All pipelines',
        'options' => [
            'group_by' => [
                'status' => 'Status',
                'source' => 'Source',
                'owner' => 'Owner',
                'day' => 'Day',
                'week' => 'Week',
            ],
        ],
    ],

    'columns' => [
        'lead' => [
            'label' => 'Group',
            'leads' => 'Leads',
            'qualified' => 'Qualified',
            'converted' => 'Converted',
            'conversion_rate' => 'Conversion %',
        ],
        'funnel' => [
            'label' => 'Stage',
            'leads' => 'Leads',
            'step_rate' => 'Step conversion %',
            'overall_rate' => 'Overall %',
        ],
        'pipeline' => [
            'label' => 'Stage',
            'deals' => 'Deals',
            'amount' => 'Amount',
            'weighted' => 'Weighted amount',
            'age_days' => 'Average age (days)',
        ],
        'sales' => [
            'label' => 'Owner',
            'won_count' => 'Won',
            'won_amount' => 'Won amount',
            'lost_count' => 'Lost',
            'lost_amount' => 'Lost amount',
            'win_rate' => 'Win rate %',
            'avg_deal_size' => 'Average deal size',
            'avg_cycle_days' => 'Sales cycle (days)',
            'open_amount' => 'Open pipeline',
        ],
        'activity' => [
            'label' => 'User',
            'day' => 'Day',
            'week' => 'Week of',
            'calls' => 'Calls',
            'meetings' => 'Meetings',
            'emails' => 'Emails',
            'notes' => 'Notes',
            'other' => 'Other',
            'total' => 'Total',
        ],
        'source' => [
            'label' => 'Source',
            'leads' => 'Leads',
            'qualified' => 'Qualified',
            'converted' => 'Converted',
            'deals_won' => 'Deals won',
            'won_amount' => 'Won amount',
            'conversion_rate' => 'Conversion %',
        ],
        'win_loss' => [
            'label' => 'Close reason',
            'kind' => 'Outcome',
            'deals' => 'Deals',
            'amount' => 'Amount',
            'share' => 'Share %',
        ],
        'forecast' => [
            'label' => 'Expected close',
            'pipeline_amount' => 'Pipeline',
            'pipeline_weighted' => 'Pipeline (weighted)',
            'best_case_amount' => 'Best case',
            'best_case_weighted' => 'Best case (weighted)',
            'commit_amount' => 'Commit',
            'commit_weighted' => 'Commit (weighted)',
            'omitted_amount' => 'Omitted',
            'omitted_weighted' => 'Omitted (weighted)',
            'total_amount' => 'Total',
            'total_weighted' => 'Total (weighted)',
        ],
        'task' => [
            'label' => 'Assignee',
            'completed' => 'Completed',
            'on_time' => 'On time',
            'late' => 'Late',
            'still_open' => 'Still open',
            'overdue_now' => 'Overdue now',
            'completion_rate' => 'Completion %',
            'avg_time_to_finish' => 'Average time to finish',
        ],
    ],

    'totals' => 'Total',

    'chart' => [
        'leads' => 'Leads',
        'qualified' => 'Qualified',
        'converted' => 'Converted',
        'amount' => 'Amount',
        'weighted' => 'Weighted amount',
        'won_amount' => 'Won amount',
        'won' => 'Won',
        'lost' => 'Lost',
        'deals_won' => 'Deals won',
        'completed' => 'Completed',
        'on_time' => 'On time',
        'late' => 'Late',
        'kinds' => [
            'calls' => 'Calls',
            'meetings' => 'Meetings',
            'emails' => 'Emails',
            'notes' => 'Notes',
            'other' => 'Other',
        ],
    ],

    'labels' => [
        'unassigned' => 'Unassigned',
        'no_source' => 'No source',
        'no_reason' => 'No reason given',
        'overdue' => 'Overdue',
        'later' => 'Later',
        'unscheduled' => 'No close date',
        'in_hours' => ':column (hours)',
    ],

    // A time to finish on the task report (D-21): whole days and hours.
    'durations' => [
        'days' => '{0} less than a day|{1} :count day|[2,*] :count days',
        'hours' => '{0} less than an hour|{1} :count hour|[2,*] :count hours',
        'days_and_hours' => ':days :hours',
    ],

    // The employee dashboard (D-26): one employee's task work on one screen.
    'employee' => [
        'sections' => [
            'choose' => 'Employee',
            'now' => 'Right now',
            'performance' => 'Performance',
            'trend' => 'Tasks completed per week',
            'activity' => 'Latest activity',
        ],
        'fields' => [
            'employee' => 'Employee',
        ],
        'placeholders' => [
            'employee' => 'Choose an employee',
        ],
        'options' => [
            'deleted' => ':name (deleted account)',
            'disabled' => ':name (disabled account)',
            'pending' => ':name (invitation not accepted yet)',
        ],
        'kpis' => [
            'open' => 'Open tasks',
            'overdue' => 'Overdue',
            'overdue_description' => 'Open and past their due date',
            'due_today' => 'Due today',
            'due_today_description' => 'Open and due during today',
            'completed_week' => 'Completed this week',
            'completed_month' => 'Completed this month',
            'on_time_rate' => 'On-time rate',
            'on_time_rate_description' => "Of this month's completed tasks",
            'average_time_to_finish' => 'Average time to finish',
            'average_time_to_finish_description' => "This month's completed tasks, from assignment to completion",
        ],
        'chart' => [
            'completed' => 'Completed',
        ],
        'lists' => [
            'overdue' => [
                'heading' => 'Overdue tasks',
                'empty' => 'No overdue tasks.',
                'detail' => 'Due :date · overdue for :days',
            ],
            'stalled' => [
                'heading' => 'Stalled tasks',
                'empty' => 'No stalled tasks.',
                'detail' => 'Last progress :date · quiet for :days',
            ],
            'checklist_open' => [
                'heading' => 'Completed with checklist items open',
                'empty' => 'No task completed this month left checklist items open.',
                'detail' => 'Completed :date · checklist :progress',
            ],
            'days' => '{0} less than a day|{1} :count day|[2,*] :count days',
            'more' => '{1} +:count more task|[2,*] +:count more tasks',
        ],
        'empty' => [
            'choose' => [
                'heading' => 'Choose an employee',
                'description' => 'Pick someone from the list above to see their task work.',
            ],
            'nobody' => [
                'heading' => 'No one in your reach',
                'description' => 'Your task visibility does not reach any employee yet.',
            ],
            'activity' => 'No progress updates or comments yet.',
        ],
    ],

    'actions' => [
        'export' => 'Export',
        'export_csv' => 'Download CSV',
        'export_xlsx' => 'Download Excel',
    ],

    'empty' => [
        'no_data' => 'Nothing matches these filters yet.',
    ],

    'validation' => [
        'range_too_large' => 'A report covers at most :days days at a time.',
        'to_before_from' => 'The end date must not be before the start date.',
    ],

];
