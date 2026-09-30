<?php

declare(strict_types=1);

// The weekly summary e-mail to the people who hand out work (decision D-18).
return [

    'sections' => [
        'assignees' => 'By assignee',
        'overdue' => 'Overdue now',
        'stalled' => 'Stalled',
        'health' => 'System health',
    ],

    'fields' => [
        'completed' => 'Tasks completed this week',
        'overdue' => 'Open tasks overdue now',
        'stalled' => 'Tasks stalled',
        'handed_out' => 'Tasks handed out this week',
        'assignee' => 'Assignee',
        'unassigned' => 'Unassigned',
        'open' => 'Open',
        'overdue_short' => 'Overdue',
        'completed_short' => 'Completed this week',
        'failed_jobs' => 'Failed background jobs this week',
        'logged_errors' => 'Errors logged this week',
    ],

    'empty' => [
        'assignees' => 'No open tasks and none completed this week.',
        'overdue' => 'No open task is overdue.',
        'stalled' => 'No task in progress has been left without an update for three days.',
    ],

    'notifications' => [
        'title' => 'Your weekly task summary',
        'body' => 'Completed: :completed · Overdue: :overdue · Stalled: :stalled · Handed out: :handed_out',
        'open' => 'Open the tasks board',
    ],

    'mail' => [
        'subject' => 'Weekly task summary, :from to :to',
        'greeting' => 'Hello :name,',
        'intro' => 'Here is how the work went from :from to :to.',
        'open_board' => 'Open the tasks board',
        'assignees_more' => '{1} … and :count more person with tasks.|[2,*] … and :count more people with tasks.',
        'tasks_more' => '{1} … and :count more task on the tasks board.|[2,*] … and :count more tasks on the tasks board.',
        'overdue_detail' => 'Due :date · :late',
        'days_late' => '{0} less than a day late|{1} :count day late|[2,*] :count days late',
        'stalled_detail' => 'Last update :date · :quiet',
        'days_quiet' => '{0} quiet for less than a day|{1} quiet for :count day|[2,*] quiet for :count days',
        'backup_none' => 'No backup has been taken yet.',
        'backup_latest' => 'Newest backup: :time — database :database, files :files, :total in total.',
        'backup_stale' => '{1} The newest backup is more than :count day old: check that the scheduler runs.|[2,*] The newest backup is more than :count days old: check that the scheduler runs.',
        'at_least' => 'at least :count',
        'health_hint' => 'Details are on the Backups page, in the failed jobs list and in the log files on the server. Log contents are never sent by e-mail.',
        'preferences_hint' => 'You receive this summary because you hand out tasks. Switch it off under Notification preferences.',
    ],

];
