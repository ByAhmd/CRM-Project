<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tasks\RoutineTaskService;
use Illuminate\Console\Command;

/**
 * Hands out the tasks of every routine whose day has come (decision D-22).
 * Idempotent — a routine's next day advances in the transaction that
 * produces its tasks — so the scheduler in routes/console.php runs it every
 * hour on shared hosting without a queue worker (D-1), and a doubled run
 * creates nothing.
 */
final class GenerateRoutineTasksCommand extends Command
{
    protected $signature = 'tasks:generate-routines';

    protected $description = 'Create the tasks of every routine task whose scheduled day has come (idempotent)';

    public function handle(RoutineTaskService $routines): int
    {
        $count = $routines->generateDue();

        $this->info(sprintf('[tasks] %d routine task%s created.', $count, $count === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
