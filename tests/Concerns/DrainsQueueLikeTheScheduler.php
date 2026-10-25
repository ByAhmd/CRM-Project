<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the queue the way production runs it (routes/console.php, decision
 * D-1): the scheduler's `queue:work --stop-when-empty --max-time=50` in a
 * console process — no current panel, no signed-in user, the locale the
 * application starts with rather than the one a run was started in.
 *
 * `--sleep=0` skips the worker's idle pause and `--memory` lifts the cap the
 * test process itself would trip; nothing else differs from the scheduled
 * line. The test must put the queue on the `database` connection first
 * (phpunit.xml runs the suite on `sync`).
 */
trait DrainsQueueLikeTheScheduler
{
    /** Drains everything queued; returns the worker's output for failure messages. */
    protected function drainQueueLikeTheScheduler(string $consoleLocale = 'ar'): string
    {
        $this->enterConsoleContext($consoleLocale);

        $exit = Artisan::call('queue:work --stop-when-empty --max-time=50 --sleep=0 --memory=1024');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);

        return $output;
    }

    /** One job only — what one drain cut by its time cap got through; returns the worker's output. */
    protected function drainOneJobLikeACutScheduler(string $consoleLocale = 'ar'): string
    {
        $this->enterConsoleContext($consoleLocale);

        $exit = Artisan::call('queue:work --once --sleep=0 --memory=1024');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);

        return $output;
    }

    /** What a console process starts with: no panel, no user, the default locale. */
    protected function enterConsoleContext(string $locale = 'ar'): void
    {
        Filament::setCurrentPanel(null);
        auth()->forgetGuards();
        app()->setLocale($locale);
    }
}
