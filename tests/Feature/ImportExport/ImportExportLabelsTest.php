<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The import and export buttons name their entity in the reader's language
 * (module 18, decision D-5).
 *
 * Both are header actions with no record and, on a List page, no table behind
 * them, so Filament's own plural label fell through to Str::plural() — the
 * English pluraliser — and the leads list read «استيراد عميل محتملs». The
 * builder now takes the plural from the entity's own Resource, which reads it
 * from lang/, so the button, its modal heading and the bulk export agree with
 * the rest of the panel in both locales.
 */
final class ImportExportLabelsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function the_import_button_takes_its_plural_from_the_entity_resource_in_both_locales(): void
    {
        $manager = $this->salesManager();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([ListLeads::class, ListContacts::class, ListAccounts::class, ListDeals::class] as $page) {
                $plural = $page::getResource()::getPluralModelLabel();

                Livewire::actingAs($manager)
                    ->test($page)
                    ->assertActionHasLabel('import', __('imports.actions.import', ['label' => $plural]))
                    ->assertActionExists(
                        'import',
                        checkActionUsing: fn (Action $action): bool => $action->getPluralModelLabel() === $plural,
                    );
            }
        }
    }

    #[Test]
    public function the_export_buttons_take_their_plural_from_the_entity_resource_in_both_locales(): void
    {
        $rep = $this->salesRep();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([ListLeads::class, ListContacts::class, ListAccounts::class, ListDeals::class, ListTasks::class, ListActivities::class] as $page) {
                $plural = $page::getResource()::getPluralModelLabel();

                Livewire::actingAs($rep)
                    ->test($page)
                    ->assertActionHasLabel(
                        TestAction::make('export')->table(),
                        __('exports.actions.export', ['label' => $plural]),
                    )
                    // The bulk button says «تصدير المحدد» and names no entity, but
                    // its modal heading does, through the same plural label.
                    ->assertActionExists(
                        TestAction::make('export')->table()->bulk(),
                        checkActionUsing: fn (Action $action): bool => $action->getPluralModelLabel() === $plural,
                    );
            }
        }
    }

    #[Test]
    public function no_arabic_import_or_export_button_carries_a_latin_word_or_an_english_plural_s(): void
    {
        // The two shapes the fallback produced: «استيراد عميل محتملs» on a page
        // header action, where Filament pluralised the Arabic singular, and
        // «تصدير leads» on a table one, where it pluralised Filament's own
        // humanised class name. Neither is Arabic.
        app()->setLocale('ar');

        $manager = $this->salesManager();
        $rep = $this->salesRep();
        $labels = [];

        $collect = function (Action $action) use (&$labels): bool {
            $labels[] = (string) $action->getLabel();

            return true;
        };

        // The probe only means something while the English pluraliser is still
        // what Filament falls back to.
        $this->assertMatchesRegularExpression('/\p{Arabic}s$/u', Str::plural(__('leads.navigation.model')));

        foreach ([ListLeads::class, ListContacts::class, ListAccounts::class, ListDeals::class] as $page) {
            Livewire::actingAs($manager)
                ->test($page)
                ->assertActionExists('import', checkActionUsing: $collect);
        }

        foreach ([ListLeads::class, ListContacts::class, ListAccounts::class, ListDeals::class, ListTasks::class, ListActivities::class] as $page) {
            Livewire::actingAs($rep)
                ->test($page)
                ->assertActionExists(TestAction::make('export')->table(), checkActionUsing: $collect);
        }

        $this->assertCount(10, $labels);

        $offenders = array_values(array_filter(
            $labels,
            static fn (string $label): bool => preg_match('/[A-Za-z]/', $label) === 1,
        ));

        $this->assertSame([], $offenders, 'Arabic buttons carrying a Latin word: '.implode(', ', $offenders));
    }
}
