<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The deployment documentation follows the code (go-live checklist section 9,
 * decision D-1): docs/DEPLOYMENT.md names every scheduled entry and every key
 * of .env.example, and every artisan command the application defines is
 * documented in DEPLOYMENT.md or OPERATIONS.md. A new schedule entry, command,
 * option or environment key fails here until the runbook explains it.
 */
final class DeploymentDocsTest extends TestCase
{
    #[Test]
    public function the_runbook_names_every_scheduled_command_and_named_callback(): void
    {
        $this->app->make(Kernel::class)->all();

        $events = $this->app->make(Schedule::class)->events();
        $deployment = $this->document('docs/DEPLOYMENT.md');
        $names = [];

        foreach ($events as $event) {
            $names[] = $event instanceof CallbackEvent
                ? class_basename((string) $event->description)
                : $this->artisanCommandName((string) $event->command);
        }

        $this->assertNotContains('', $names, 'a scheduled entry has neither a name nor an artisan command');
        $this->assertContains('scheduler:heartbeat', $names);
        $this->assertContains('queue:work', $names);

        $missing = array_values(array_unique(array_filter(
            $names,
            fn (string $name): bool => ! $this->mentions($deployment, $name),
        )));

        $this->assertSame([], $missing, 'docs/DEPLOYMENT.md does not name these scheduled entries: '.implode(', ', $missing));
        $this->assertMatchesRegularExpression(
            '/\* \* \* \* \* cd \S+ && (php|<php>) artisan schedule:run >> \/dev\/null 2>&1/',
            $deployment,
            'docs/DEPLOYMENT.md does not show the cron line that drives the scheduler',
        );
    }

    #[Test]
    public function every_artisan_command_of_the_application_is_documented_with_its_options(): void
    {
        $documents = $this->document('docs/DEPLOYMENT.md')."\n".$this->document('docs/OPERATIONS.md');
        $commands = $this->applicationCommandSignatures();
        $missing = [];

        $this->assertNotEmpty($commands);

        foreach ($commands as $name => $options) {
            if (! $this->mentions($documents, $name)) {
                $missing[] = $name;
            }

            foreach ($options as $option) {
                if (! $this->mentions($documents, '--'.$option)) {
                    $missing[] = $name.' --'.$option;
                }
            }
        }

        $this->assertSame([], $missing, 'docs/DEPLOYMENT.md and docs/OPERATIONS.md do not document: '.implode(', ', $missing));
    }

    #[Test]
    public function the_runbook_names_every_key_of_env_example(): void
    {
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $this->document('.env.example'), $matches);
        $keys = array_values(array_unique($matches[1]));
        $deployment = $this->document('docs/DEPLOYMENT.md');

        $this->assertNotEmpty($keys);

        $missing = array_values(array_filter(
            $keys,
            fn (string $key): bool => ! $this->mentions($deployment, $key),
        ));

        $this->assertSame([], $missing, 'docs/DEPLOYMENT.md does not name these .env.example keys: '.implode(', ', $missing));
    }

    /**
     * The artisan command name in a scheduled command string, which embeds the
     * platform-quoted PHP binary and artisan path before it.
     */
    private function artisanCommandName(string $command): string
    {
        return preg_match('/artisan[\'"]?\s+([a-z0-9:_-]+)/i', $command, $match) === 1 ? $match[1] : '';
    }

    /**
     * Name => option names of every artisan command whose class lives under
     * app/Console/Commands, read from the registered console application (so
     * `$signature`, `$name`, `#[AsCommand]` and `configure()` declarations are
     * all covered). Every PHP file of that directory must be such a registered
     * command: a file that yields no command fails instead of being skipped.
     *
     * @return array<string, list<string>>
     */
    private function applicationCommandSignatures(): array
    {
        $namespace = 'App\\Console\\Commands\\';
        $commands = [];
        $registeredClasses = [];

        foreach ($this->app->make(Kernel::class)->all() as $command) {
            $class = $command::class;

            if (! str_starts_with($class, $namespace)) {
                continue;
            }

            $name = (string) $command->getName();
            $this->assertNotSame('', $name, $class.' is registered without a command name');

            $registeredClasses[] = $class;
            $commands[$name] = array_values(array_map(
                static fn (InputOption $option): string => $option->getName(),
                $command->getNativeDefinition()->getOptions(),
            ));
        }

        foreach (Finder::create()->files()->in(app_path('Console/Commands'))->name('*.php') as $file) {
            $class = $namespace.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            $this->assertContains(
                $class,
                $registeredClasses,
                'app/Console/Commands/'.$file->getRelativePathname().' does not yield a registered artisan command, so its documentation cannot be checked',
            );
        }

        ksort($commands);

        return $commands;
    }

    /** Whether the text names the token on its own, not as part of a longer name. */
    private function mentions(string $text, string $token): bool
    {
        return preg_match('/(?<![A-Za-z0-9_:-])'.preg_quote($token, '/').'(?![A-Za-z0-9_:-])/', $text) === 1;
    }

    private function document(string $path): string
    {
        $contents = file_get_contents(base_path($path));

        $this->assertIsString($contents, $path.' cannot be read');

        return $contents;
    }
}
