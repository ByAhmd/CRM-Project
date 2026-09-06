<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tasks\TaskReminderService;
use Illuminate\Console\Command;

/**
 * Sends the reminders for tasks whose reminder time has passed (decisions
 * A-10, D-1). Idempotent — each task is reminded about once — so the
 * scheduler in routes/console.php may run it every minute on shared hosting
 * without a queue worker.
 */
final class SendTaskReminders extends Command
{
    protected $signature = 'tasks:send-reminders';

    protected $description = 'Notify assignees of tasks whose reminder time has passed (idempotent)';

    public function handle(TaskReminderService $reminders): int
    {
        $count = $reminders->sendDueReminders(now());

        $this->info(sprintf('[tasks] %d reminder%s sent.', $count, $count === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
