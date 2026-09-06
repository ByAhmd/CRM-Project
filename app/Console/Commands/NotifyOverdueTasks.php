<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tasks\TaskReminderService;
use Illuminate\Console\Command;

/**
 * Tells assignees once about open tasks that passed their due date
 * (decisions A-10, D-1). Idempotent, so the scheduler in routes/console.php
 * may run it as often as it likes on shared hosting without a queue worker.
 */
final class NotifyOverdueTasks extends Command
{
    protected $signature = 'tasks:notify-overdue';

    protected $description = 'Notify assignees of open tasks that are past their due date (idempotent)';

    public function handle(TaskReminderService $reminders): int
    {
        $count = $reminders->notifyOverdue(now());

        $this->info(sprintf('[tasks] %d overdue notification%s sent.', $count, $count === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
