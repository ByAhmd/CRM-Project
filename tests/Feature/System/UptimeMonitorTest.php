<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Console\Commands\PreflightCommand;
use App\Listeners\FailHealthCheckWithoutSchedulerHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The external monitor (D-16): a scheduled GitHub workflow requests `/up`, and
 * in production `/up` answers 500 when the scheduler heartbeat is missing,
 * unreadable or older than ten minutes. Each assertion on the workflow is a way
 * the monitor could otherwise stay green while the site is down, stop running,
 * or leak the site's address into a public log.
 */
final class UptimeMonitorTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        // Normalised: the repository stores LF, a Windows checkout may hold CRLF.
        $this->workflow = str_replace("\r\n", "\n", (string) file_get_contents(base_path('.github/workflows/uptime.yml')));
    }

    #[Test]
    public function the_monitor_runs_every_fifteen_minutes_and_by_hand_only(): void
    {
        $this->assertMatchesRegularExpression("/^  schedule:\n    - cron: '\\*\\/15 \\* \\* \\* \\*'$/m", $this->workflow, 'the monitor must run every 15 minutes');
        $this->assertMatchesRegularExpression('/^  workflow_dispatch:/m', $this->workflow, 'the manual Run workflow button disappeared');
        $this->assertDoesNotMatchRegularExpression('/^  (push|pull_request|pull_request_target|workflow_run):/m', $this->workflow, 'the monitor is not a build: no other trigger belongs here');
    }

    #[Test]
    public function the_monitor_is_one_job_with_no_repository_permissions_on_a_pinned_runner(): void
    {
        $this->assertMatchesRegularExpression('/^permissions: \{\}$/m', $this->workflow, 'the monitor needs no token permission at all');
        $this->assertSame(1, preg_match_all('/^  [a-z][a-z0-9_-]*:\n    name: /m', $this->workflow), 'the monitor must stay one job');
        $this->assertStringContainsString('runs-on: ubuntu-24.04', $this->workflow);
        $this->assertStringNotContainsString('continue-on-error', $this->workflow, 'a failed check must fail the run, or GitHub never mails it');
    }

    #[Test]
    public function without_the_secret_the_run_skips_with_a_notice(): void
    {
        $this->assertStringContainsString('UPTIME_URL: ${{ secrets.UPTIME_URL }}', $this->workflow);
        $this->assertMatchesRegularExpression(
            '/if \[ -z "\$UPTIME_URL" \]; then\n\s+echo "::notice [^\n]*"\n\s+exit 0\n\s+fi/',
            $this->workflow,
            'an unset secret must skip with a notice, not fail every 15 minutes',
        );
    }

    #[Test]
    public function the_check_makes_three_attempts_about_two_minutes_apart_and_passes_on_the_first_200(): void
    {
        $this->assertStringContainsString('attempts=3', $this->workflow);
        $this->assertStringContainsString('for attempt in $(seq 1 "$attempts"); do', $this->workflow);
        $this->assertStringContainsString('sleep 120', $this->workflow, 'a release clears the heartbeat for a minute: one miss must not alert');
        $this->assertStringContainsString('curl --max-time 30 -fsS -o /dev/null -w \'%{http_code}\' "$UPTIME_URL"', $this->workflow);
        $this->assertMatchesRegularExpression(
            '/if \[ "\$rc" -eq 0 \] && \[ "\$status" = "200" \]; then\n\s+echo "[^"\n]*"\n\s+exit 0/',
            $this->workflow,
            'only a 200 answered without a curl error counts as up',
        );
    }

    #[Test]
    public function after_three_failures_the_run_fails_naming_the_last_http_status(): void
    {
        $this->assertMatchesRegularExpression(
            '/\n\s+done\n\n\s+echo "::error [^"\n]*Last HTTP status: \$status[^"\n]*"\n\s+exit 1\n$/',
            $this->workflow,
            'after the last attempt the run must end red, naming the status it saw',
        );
    }

    #[Test]
    public function the_site_address_never_reaches_the_public_log(): void
    {
        $this->assertSame(1, substr_count($this->workflow, '${{ secrets.'), 'the secret may appear only in the step env');
        $this->assertStringNotContainsString('set -x', $this->workflow, 'tracing would print the expanded URL');
        $this->assertStringContainsString('"$UPTIME_URL" 2>/dev/null)', $this->workflow, "curl's error messages name the host and must be dropped");

        foreach (explode("\n", $this->workflow) as $line) {
            if (preg_match('/\b(echo|printf)\b/', $line) === 1) {
                $this->assertStringNotContainsString('$UPTIME_URL', $line, 'a log line prints the URL: '.trim($line));
                $this->assertStringNotContainsString('${UPTIME_URL', $line, 'a log line prints the URL: '.trim($line));
            }
        }
    }

    #[Test]
    public function in_production_a_fresh_heartbeat_keeps_the_health_endpoint_up(): void
    {
        $this->inProduction();
        $this->freezeTime();
        Cache::put(PreflightCommand::HEARTBEAT_KEY, now()->subMinutes(FailHealthCheckWithoutSchedulerHeartbeat::MAX_AGE_MINUTES - 1)->toIso8601String(), now()->addMinutes(10));

        $this->get('/up')->assertOk();
        $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up']);
    }

    #[Test]
    public function in_production_a_stale_heartbeat_takes_the_health_endpoint_down(): void
    {
        Exceptions::fake();
        $this->inProduction();
        $this->freezeTime();
        Cache::put(PreflightCommand::HEARTBEAT_KEY, now()->subMinutes(FailHealthCheckWithoutSchedulerHeartbeat::MAX_AGE_MINUTES + 1)->toIso8601String(), now()->addMinutes(10));

        $this->get('/up')->assertStatus(500);
        $this->getJson('/up')->assertStatus(500)->assertExactJson(['status' => 'down']);

        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'minutes old'));
    }

    #[Test]
    public function in_production_a_missing_heartbeat_takes_the_health_endpoint_down(): void
    {
        Exceptions::fake();
        $this->inProduction();
        Cache::forget(PreflightCommand::HEARTBEAT_KEY);

        $this->get('/up')->assertStatus(500);

        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'No scheduler heartbeat'));
    }

    #[Test]
    public function in_production_an_unreadable_heartbeat_takes_the_health_endpoint_down(): void
    {
        Exceptions::fake();
        $this->inProduction();
        Cache::put(PreflightCommand::HEARTBEAT_KEY, 'not a time', now()->addMinutes(10));

        $this->get('/up')->assertStatus(500);

        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'unreadable'));
    }

    #[Test]
    public function outside_production_the_health_endpoint_ignores_the_heartbeat(): void
    {
        Cache::forget(PreflightCommand::HEARTBEAT_KEY);
        $this->get('/up')->assertOk();

        Cache::put(PreflightCommand::HEARTBEAT_KEY, now()->subHour()->toIso8601String(), now()->addMinutes(10));
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->get('/up')->assertOk();
    }

    /** Production as the host runs it: debug off, so the health route answers instead of rethrowing. */
    private function inProduction(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => false]);
        $this->assertTrue($this->app->environment('production'));
    }
}
