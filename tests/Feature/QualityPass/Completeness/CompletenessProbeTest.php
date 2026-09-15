<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Completeness;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Services\Tasks\CalendarFeed;
use Filament\Facades\Filament;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Completeness critic probes for the step-12 quality pass: what the plan's
 * step 12 exit criteria and CLAUDE.md section 6 require but the pass left
 * undone, and where the documents still disagree with the code.
 */
final class CompletenessProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_step_12_exit_checklist_exists(): void
    {
        // docs/ARCHITECTURE_PLAN.md section 5, step 12: "Checklist in docs/GoLive_Checklist.md complete".
        $this->assertFileExists(base_path('docs/GoLive_Checklist.md'));
    }

    #[Test]
    public function global_search_is_offered_only_on_the_five_resources_plan_section_3_8_names(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->actingAs($this->superAdmin());

        $searchable = [];

        foreach (Filament::getCurrentOrDefaultPanel()->getResources() as $resource) {
            if (is_subclass_of($resource, FilamentResource::class) && $resource::canGloballySearch()) {
                $searchable[] = $resource;
            }
        }

        $this->assertEqualsCanonicalizing(
            [LeadResource::class, ContactResource::class, AccountResource::class, DealResource::class, TaskResource::class],
            $searchable,
        );
    }

    #[Test]
    public function the_plan_names_every_scheduled_entry(): void
    {
        $plan = (string) file_get_contents(base_path('docs/ARCHITECTURE_PLAN.md'));
        $missing = array_values(array_filter(
            ['tasks:send-reminders', 'tasks:notify-overdue', 'leads:notify-stale', 'RescoreLeads', 'attachments:prune-temporary', 'uploads:prune-livewire-temporary', 'reports:prune-downloads'],
            static fn (string $entry): bool => ! str_contains($plan, $entry),
        ));

        $this->assertSame([], $missing, 'schedule entries in routes/console.php the plan does not name: '.implode(', ', $missing));
    }

    #[Test]
    public function readme_and_claude_md_cite_the_current_architect_decision_range(): void
    {
        preg_match_all('/^\| A-(\d+) \|/m', (string) file_get_contents(base_path('docs/DECISIONS.md')), $matches);
        $last = max(array_map('intval', $matches[1]));
        $stale = [];

        foreach (['README.md', 'CLAUDE.md'] as $document) {
            if (! str_contains((string) file_get_contents(base_path($document)), "A-1 … A-{$last}")) {
                $stale[] = $document;
            }
        }

        $this->assertSame([], $stale, "documents not citing A-1 … A-{$last}: ".implode(', ', $stale));
    }

    #[Test]
    public function the_security_plan_claims_strict_models_only_if_the_provider_enables_them(): void
    {
        $plan = (string) file_get_contents(base_path('docs/ARCHITECTURE_PLAN.md'));
        $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

        if (str_contains($plan, 'Model::shouldBeStrict()')) {
            $this->assertTrue(
                str_contains($provider, 'shouldBeStrict'),
                'plan section 7 claims Model::shouldBeStrict() outside production; AppServiceProvider::configureModels() does not call it',
            );
        } else {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function importer_example_rows_carry_no_hardcoded_text(): void
    {
        // CLAUDE.md section 3: no user-facing string in code, in any language. The example
        // values end up in the downloadable example CSV every user sees, whatever their locale.
        // Machine values are not text: an enum key or boolean token (prospect, high, yes), a
        // locale or ISO country code (ar, SA), an e-mail address and a URL read the same in
        // every locale and must stay literal, because the importer parses exactly that value.
        $machine = '/^(?:[a-z0-9_]+|[A-Z]{2}|[^\s@\'"]+@[^\s@\'"]+\.[a-z]{2,}|https?:\/\/[^\s\'"]+)$/';
        $literal = [];

        foreach (glob(app_path('Filament/Imports/*Importer.php')) ?: [] as $file) {
            preg_match_all('/->example\(\s*[\'"]([^\'"]*\p{L}[^\'"]*)[\'"]\s*\)/u', (string) file_get_contents($file), $matches);
            $count = count(array_filter($matches[1], static fn (string $value): bool => preg_match($machine, $value) !== 1));

            if ($count > 0) {
                $literal[] = basename($file).': '.$count;
            }
        }

        $this->assertSame([], $literal, 'importers with literal example text: '.implode(', ', $literal));
    }

    #[Test]
    public function the_calendar_feed_caps_the_number_of_events_one_range_returns(): void
    {
        // Performance audit: the range is capped at 62 days but the entry count is not.
        $constants = array_keys((new \ReflectionClass(CalendarFeed::class))->getConstants());

        $this->assertContains('MAX_EVENTS', $constants, 'CalendarFeed has no per-range event cap');
    }
}
