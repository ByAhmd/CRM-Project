<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Enums\CustomFieldEntity;
use App\Filament\Imports\AccountImporter;
use App\Filament\Imports\ContactImporter;
use App\Filament\Imports\DealImporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\ImportExportActions;
use App\Listeners\StampCheckOnlyImport;
use App\Models\Account;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Import;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\Imports\Events\ImportStarted;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Concerns\DrainsQueueLikeTheScheduler;
use Tests\Concerns\ReadsFilamentNotifications;
use Tests\TestCase;

/**
 * The check-only run (decision D-29, «تحقق فقط دون كتابة»): the import modal's
 * option that reads and validates every row exactly as a real import —
 * lookups, duplicates, policy verbs — records the valid and refused rows in
 * the history, and writes no record, tag, custom-field value, owner change,
 * notice or ledger entry.
 *
 * Every run here is started through the panel action, the way a user starts
 * one, with a file of three rows: a new record (owner the importer, one tag,
 * one custom field; a contact also names an account and asks to be its
 * primary contact), a duplicate of an existing record under the «update»
 * strategy that names a new owner within reach, and a row refused for an
 * unknown tag. The custom field is one definition per entity, so the column
 * costs one factory row and one CSV cell.
 *
 * The sync connection shows the request path for all four importers; one run
 * on the `database` connection, drained as the scheduler drains it, shows that
 * the flag is on the history row before any job runs and that the completion
 * notice says «check only» in the run's locale. A real run through the same
 * modal is the control: it writes everything the check-only run withheld and
 * stamps the run on the records it created.
 */
final class ImportDryRunTest extends TestCase
{
    use CreatesCrmFixtures;
    use DrainsQueueLikeTheScheduler;
    use ReadsFilamentNotifications;
    use RefreshDatabase;

    /** The one custom field each scenario defines on its entity (D-9); its CSV header is the prefixed key. */
    private const CUSTOM_FIELD_KEY = 'budget';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function every_importer_offers_the_check_only_option_after_the_duplicate_strategy(): void
    {
        foreach ([LeadImporter::class, ContactImporter::class, AccountImporter::class, DealImporter::class] as $importer) {
            $components = $importer::getOptionsFormComponents();

            $this->assertCount(2, $components, $importer);
            $this->assertInstanceOf(Select::class, $components[0], $importer);
            $this->assertSame('duplicate_strategy', $components[0]->getName(), $importer);

            $checkbox = $components[1];
            $this->assertInstanceOf(Checkbox::class, $checkbox, $importer);
            $this->assertSame(ImportExportActions::DRY_RUN_OPTION, $checkbox->getName(), $importer);
            $this->assertSame(__('imports.fields.dry_run'), $checkbox->getLabel(), $importer);
            $this->assertFalse($checkbox->getDefaultState(), "{$importer}: a real import unless the user ticks the option");
        }

        // The modal carries the option with its label and its explanation (the
        // helper text is the Text component Filament puts below the field).
        Livewire::actingAs($this->salesManager())
            ->test(ListLeads::class)
            ->mountAction('import')
            ->assertFormFieldExists(ImportExportActions::DRY_RUN_OPTION, static function (Checkbox $field): bool {
                $helper = $field->getChildSchema(Checkbox::BELOW_CONTENT_SCHEMA_KEY)?->getComponents()[0] ?? null;

                return $field->getLabel() === __('imports.fields.dry_run')
                    && $helper instanceof Text
                    && $helper->getContent() === __('imports.helpers.dry_run');
            });
    }

    /**
     * Filament fires ImportStarted right after it saves the Import row and
     * before it dispatches the batch; the listener is the one place that sees
     * the row and the modal's options together, so the flag it stamps is what
     * the jobs, the completion notice, the history and ImportPolicy::undo read.
     * It is discovered from app/Listeners exactly once: a second registration
     * would stamp every run twice.
     */
    #[Test]
    public function the_check_only_flag_is_stamped_on_the_history_row_by_one_listener_of_filaments_import_started_event(): void
    {
        $registrations = array_filter(
            Event::getRawListeners()[ImportStarted::class] ?? [],
            static fn (mixed $listener): bool => is_string($listener) && str_starts_with($listener, StampCheckOnlyImport::class),
        );
        $this->assertCount(1, $registrations, 'discovered from app/Listeners, and not registered a second time by hand');

        $manager = $this->salesManager();
        $real = $this->startedRun($manager);
        $unticked = $this->startedRun($manager);
        $check = $this->startedRun($manager);

        event(new ImportStarted($real, [], ['duplicate_strategy' => LeadImporter::DUPLICATE_SKIP]));
        event(new ImportStarted($unticked, [], ['duplicate_strategy' => LeadImporter::DUPLICATE_SKIP, ImportExportActions::DRY_RUN_OPTION => false]));
        event(new ImportStarted($check, [], ['duplicate_strategy' => LeadImporter::DUPLICATE_SKIP, ImportExportActions::DRY_RUN_OPTION => true]));

        $this->assertTrue($check->dry_run, 'stamped on the very instance the event carries, which the batch serialises afterwards');
        $this->assertTrue($check->refresh()->dry_run, 'and saved, so a worker reading the row back sees it');
        $this->assertFalse($real->refresh()->dry_run);
        $this->assertFalse($unticked->refresh()->dry_run);
    }

    /**
     * @param  class-string  $importer
     */
    #[Test]
    #[DataProvider('importers')]
    public function a_check_only_run_validates_every_row_records_the_result_and_writes_nothing(string $importer): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $rep->forceFill(['email' => 'rep@example.com'])->save();
        Tag::factory()->create(['name_en' => 'VIP', 'name_ar' => 'كبار العملاء']);
        $scenario = $this->scenario($importer, $manager, $rep);
        $existing = $scenario['existing'];
        $model = $scenario['model'];

        $untouched = $existing->refresh()->getAttributes();
        $before = $this->writtenState();

        Livewire::actingAs($manager)
            ->test($scenario['page'])
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('check.csv', self::csv($scenario['headers'], $scenario['rows'])),
                'columnMap' => array_combine($scenario['headers'], $scenario['headers']),
                'duplicate_strategy' => LeadImporter::DUPLICATE_UPDATE,
                ImportExportActions::DRY_RUN_OPTION => true,
            ])
            ->assertHasNoActionErrors();

        // Nothing is written: no record, no tag, no custom-field value, no
        // owner change, no notice to the owner the duplicate row named, no
        // ledger entry — only the run itself and its failed row.
        $this->assertSame($before, $this->writtenState(), 'a check-only run writes nothing but its own history row');
        $this->assertFalse($model::query()->where($scenario['created'])->exists(), 'the valid new row created nothing');
        $this->assertSame($untouched, $existing->refresh()->getAttributes(), 'the duplicate keeps every attribute, its owner and its updated_at');
        $this->assertSame(0, DB::table('taggables')->count());
        $this->assertSame(0, DB::table('custom_field_values')->count());
        $this->assertSame(0, DatabaseNotification::query()->where('notifiable_id', $rep->getKey())->count(), 'the would-be new owner is not told');

        if ($scenario['primary'] instanceof Contact) {
            // The one write ContactImporter::afterSave() alone guards: the primary rule (D-6) stood down.
            $this->assertTrue($scenario['primary']->refresh()->is_primary, 'the account\'s primary contact was not demoted for the row that asked to be primary');
        }

        // The history records what a real run would have done.
        $import = Import::query()->latest('id')->firstOrFail();
        $this->assertTrue($import->dry_run);
        $this->assertSame(ImportResource::MODE_DRY_RUN, ImportResource::mode($import));
        $this->assertSame(ImportResource::STATUS_COMPLETED, ImportResource::status($import));
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(3, $import->processed_rows);
        $this->assertSame(2, $import->successful_rows);
        $this->assertSame(1, $import->getFailedRowsCount());
        $this->assertSame(__('imports.validation.unknown_lookup', ['value' => 'Nonexistent']), $this->failedImportReason($import, 'tags', 'Nonexistent'));

        // The completion notice says «check only» and that nothing was written
        // (a toast on the sync connection), in the request's locale.
        $completed = $this->sessionNotification(__('imports.notifications.dry_run.title'));
        $this->assertSame($this->checkOnlyCompletionBody(app()->getLocale()), $completed['body'] ?? null);

        // A check-only run cannot be undone, whoever asks (D-29): not its owner,
        // who may delete the entity's records, and not an administrator.
        $this->assertFalse($manager->can('undo', $import));
        $this->assertFalse($this->superAdmin()->can('undo', $import));

        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertCanSeeTableRecords([$import])
            ->assertSee(__('imports.modes.dry_run'))
            ->assertSee(__('imports.statuses.completed'))
            ->assertDontSeeHtml('wire:poll.');

        Livewire::actingAs($manager)
            ->test(ViewImport::class, ['record' => $import->getRouteKey()])
            ->assertSee(__('imports.modes.dry_run'))
            ->assertSee(__('imports.validation.unknown_lookup', ['value' => 'Nonexistent']));
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function importers(): array
    {
        return [
            'leads' => [LeadImporter::class],
            'contacts' => [ContactImporter::class],
            'accounts' => [AccountImporter::class],
            'deals' => [DealImporter::class],
        ];
    }

    /**
     * Production's path (D-1, A-7): the panel action queues the rows on the
     * `database` connection and the scheduler drains them a minute later in a
     * console process with no panel, no user and the default locale.
     */
    #[Test]
    public function a_queued_check_only_run_is_marked_in_the_request_and_writes_nothing_when_the_scheduler_drains_it(): void
    {
        config()->set('queue.default', 'database');

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $rep->forceFill(['email' => 'rep@example.com'])->save();
        Tag::factory()->create(['name_en' => 'VIP', 'name_ar' => 'كبار العملاء']);
        $scenario = $this->scenario(LeadImporter::class, $manager, $rep);
        $existing = $scenario['existing'];

        $untouched = $existing->refresh()->getAttributes();
        $before = $this->writtenState();

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('check.csv', self::csv($scenario['headers'], $scenario['rows'])),
                'columnMap' => array_combine($scenario['headers'], $scenario['headers']),
                'duplicate_strategy' => LeadImporter::DUPLICATE_UPDATE,
                ImportExportActions::DRY_RUN_OPTION => true,
            ])
            ->assertHasNoActionErrors();

        $requestLocale = app()->getLocale();
        $import = Import::query()->latest('id')->firstOrFail();

        // The flag is on the row before any job runs: the listener ran in the request.
        $this->assertTrue($import->dry_run, 'stamped by StampCheckOnlyImport in the request, before the drain');
        $this->assertSame(ImportResource::MODE_DRY_RUN, ImportResource::mode($import));
        $this->assertSame(0, $import->processed_rows);
        $this->assertNull($import->completed_at);
        $this->assertSame(1, DB::table('jobs')->count(), 'three rows fit one chunk');
        $this->assertSame($before, $this->writtenState(), 'nothing is written in the request');

        // The «started» toast says it is a check-only run and keeps the history button.
        $started = $this->sessionNotification(__('filament-actions::import.notifications.started.title'));
        $this->assertStringContainsString(trans_choice('filament-actions::import.notifications.started.body', 3, ['count' => '3']), (string) $started['body'], 'Filament\'s own sentence stays');
        $this->assertStringContainsString(__('imports.notifications.dry_run.started_body'), (string) $started['body']);
        $this->assertStringNotContainsString(__('imports.notifications.started.body'), (string) $started['body'], 'the real import\'s sentence is replaced, not joined');
        $this->assertSame(__('imports.actions.track_progress'), $started['actions'][0]['label'] ?? null);
        $this->assertSame(ImportResource::getUrl(), $started['actions'][0]['url'] ?? null);

        // The drain runs in the application's default locale, not the importer's.
        $output = $this->drainQueueLikeTheScheduler($requestLocale === 'ar' ? 'en' : 'ar');

        $this->assertSame(0, DB::table('failed_jobs')->count(), $output);
        $this->assertSame(0, DB::table('jobs')->count(), $output);
        $this->assertSame(0, DB::table('job_batches')->whereNull('finished_at')->count(), $output);

        $import->refresh();
        $this->assertTrue($import->dry_run);
        $this->assertSame(3, $import->processed_rows);
        $this->assertSame(2, $import->successful_rows);
        $this->assertSame(1, $import->getFailedRowsCount());
        $this->assertNotNull($import->completed_at);
        $this->assertSame(ImportResource::STATUS_COMPLETED, ImportResource::status($import));

        // Nothing but the completion notice to the importer was written by the worker.
        $after = $this->writtenState();
        $this->assertSame(Arr::except($before, 'notifications'), Arr::except($after, 'notifications'), 'the worker wrote no record, tag, value or ledger entry');
        $this->assertSame($before['notifications'] + 1, $after['notifications'], 'one bell notice: the completion, to the importer');
        $this->assertSame($untouched, $existing->refresh()->getAttributes());
        $this->assertSame(0, DatabaseNotification::query()->where('notifiable_id', $rep->getKey())->count(), 'the would-be new owner is not told');

        // The bell notice says «check only» and that nothing was written, in the run's locale (D-5).
        $completed = $this->databaseNotification($manager, __('imports.notifications.dry_run.title', [], $requestLocale));
        $this->assertSame($this->checkOnlyCompletionBody($requestLocale), $completed->data['body'] ?? null);
        $this->assertCount(1, $completed->data['actions'] ?? [], 'the refused row is offered for download');

        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertSee(__('imports.modes.dry_run'))
            ->assertDontSeeHtml('wire:poll.');
    }

    /**
     * The control: the same modal without the option writes what the check-only
     * run withheld, and every record the run created remembers it (`import_id`)
     * while an updated duplicate does not — the undo (D-29) deletes only what a
     * run created. QueuedRunsTest proves the rest of the real path.
     */
    #[Test]
    public function a_real_run_through_the_same_modal_writes_the_records_and_stamps_the_run_on_what_it_created(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $rep->forceFill(['email' => 'rep@example.com'])->save();
        Tag::factory()->create(['name_en' => 'VIP', 'name_ar' => 'كبار العملاء']);
        $scenario = $this->scenario(LeadImporter::class, $manager, $rep);
        $existing = $scenario['existing'];

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('leads.csv', self::csv($scenario['headers'], $scenario['rows'])),
                'columnMap' => array_combine($scenario['headers'], $scenario['headers']),
                'duplicate_strategy' => LeadImporter::DUPLICATE_UPDATE,
            ])
            ->assertHasNoActionErrors();

        $import = Import::query()->latest('id')->firstOrFail();
        $this->assertFalse($import->dry_run);
        $this->assertSame(ImportResource::MODE_IMPORT, ImportResource::mode($import));
        $this->assertSame(2, $import->successful_rows);
        $this->assertSame(1, $import->getFailedRowsCount());

        $created = Lead::query()->where($scenario['created'])->firstOrFail();
        $this->assertSame($import->getKey(), $created->getAttribute('import_id'), 'a created record remembers the run');

        $duplicate = Lead::query()->whereKey($existing->getKey())->firstOrFail();
        $this->assertNull($duplicate->getAttribute('import_id'), 'an updated duplicate is not marked as created by the run');
        $this->assertSame('Changed', $duplicate->job_title, 'the duplicate was updated for real');
        $this->assertSame($rep->getKey(), $duplicate->owner_id, 'and reassigned to the owner the row named');

        // What the check-only run withheld is here now.
        $this->assertSame(2, DB::table('taggables')->count(), 'the tag on the created record and on the duplicate');
        $this->assertSame(2, DB::table('custom_field_values')->count(), 'the custom-field value on both');
        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $rep->getKey())->count(), 'the new owner is told (D-4)');
        $this->assertTrue($manager->can('undo', $import), 'a real run can be undone by its owner, who holds lead.delete');
    }

    /**
     * The contact control: the one write ContactImporter::afterSave() alone
     * guards is the primary rule (D-6), which demotes the account's previous
     * primary contact in a real run — so the check-only run above proves
     * something by leaving it in place.
     */
    #[Test]
    public function a_real_contact_run_through_the_same_modal_applies_the_primary_rule_the_check_only_run_withheld(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $rep->forceFill(['email' => 'rep@example.com'])->save();
        Tag::factory()->create(['name_en' => 'VIP', 'name_ar' => 'كبار العملاء']);
        $scenario = $this->scenario(ContactImporter::class, $manager, $rep);
        $previousPrimary = $scenario['primary'];
        $this->assertInstanceOf(Contact::class, $previousPrimary);

        Livewire::actingAs($manager)
            ->test(ListContacts::class)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('contacts.csv', self::csv($scenario['headers'], $scenario['rows'])),
                'columnMap' => array_combine($scenario['headers'], $scenario['headers']),
                'duplicate_strategy' => LeadImporter::DUPLICATE_UPDATE,
            ])
            ->assertHasNoActionErrors();

        $created = Contact::query()->where($scenario['created'])->firstOrFail();
        $this->assertSame($previousPrimary->account_id, $created->account_id, 'the row named the account');
        $this->assertTrue($created->is_primary, 'and asked to be its primary contact');
        $this->assertFalse($previousPrimary->refresh()->is_primary, 'one primary contact per account (D-6): the previous one was demoted');
    }

    /**
     * The three rows every check-only run is fed, with the page the run starts
     * from, the record a duplicate row targets, the record the valid row would
     * have created and, for contacts, the account's primary contact the valid
     * row would have demoted.
     *
     * - the first row is valid and new: owned by the importer, one tag, one
     *   custom-field value (a contact also names an account and asks to be
     *   its primary contact, the one write ContactImporter::afterSave() alone
     *   guards);
     * - the second duplicates `existing` (the importer's own record) and, under
     *   the «update» strategy, would change one attribute, reassign it to the
     *   rep within the importer's reach (D-4) and tag it;
     * - the third is refused for an unknown tag.
     *
     * @param  class-string  $importer
     * @return array{page: class-string, model: class-string<Model>, existing: Model, primary: ?Contact, headers: list<string>, rows: list<list<string>>, created: array<string, string>}
     */
    private function scenario(string $importer, User $manager, User $rep): array
    {
        $owner = (string) $manager->email;
        $newOwner = (string) $rep->email;
        $custom = CustomFieldsSchema::NAME_PREFIX.self::CUSTOM_FIELD_KEY;

        switch ($importer) {
            case LeadImporter::class:
                $this->defineCustomField(CustomFieldEntity::Lead);

                return [
                    'page' => ListLeads::class,
                    'model' => Lead::class,
                    'existing' => Lead::factory()->create(['owner_id' => $manager->getKey(), 'email' => 'dup.lead@example.com', 'job_title' => 'Original']),
                    'primary' => null,
                    'headers' => ['first_name', 'last_name', 'email', 'job_title', 'owner', 'tags', $custom],
                    'rows' => [
                        ['Fresh', 'Lead', 'fresh.lead@example.com', 'Buyer', $owner, 'VIP', 'Big'],
                        ['Dup', 'Lead', 'dup.lead@example.com', 'Changed', $newOwner, 'VIP', 'Big'],
                        ['Bad', 'Lead', 'bad.lead@example.com', 'Buyer', $owner, 'Nonexistent', 'Big'],
                    ],
                    'created' => ['email_normalized' => 'fresh.lead@example.com'],
                ];

            case ContactImporter::class:
                $this->defineCustomField(CustomFieldEntity::Contact);
                $account = Account::factory()->create(['owner_id' => $manager->getKey(), 'name' => 'Horizon Trading']);

                return [
                    'page' => ListContacts::class,
                    'model' => Contact::class,
                    'existing' => Contact::factory()->create(['owner_id' => $manager->getKey(), 'email' => 'dup.contact@example.com', 'job_title' => 'Original']),
                    'primary' => Contact::factory()->create(['owner_id' => $manager->getKey(), 'account_id' => $account->getKey(), 'is_primary' => true]),
                    'headers' => ['first_name', 'last_name', 'email', 'job_title', 'account', 'is_primary', 'owner', 'tags', $custom],
                    'rows' => [
                        ['Fresh', 'Contact', 'fresh.contact@example.com', 'Buyer', 'Horizon Trading', 'yes', $owner, 'VIP', 'Big'],
                        ['Dup', 'Contact', 'dup.contact@example.com', 'Changed', '', '', $newOwner, 'VIP', 'Big'],
                        ['Bad', 'Contact', 'bad.contact@example.com', 'Buyer', '', '', $owner, 'Nonexistent', 'Big'],
                    ],
                    'created' => ['email_normalized' => 'fresh.contact@example.com'],
                ];

            case AccountImporter::class:
                $this->defineCustomField(CustomFieldEntity::Account);

                return [
                    'page' => ListAccounts::class,
                    'model' => Account::class,
                    'existing' => Account::factory()->create(['owner_id' => $manager->getKey(), 'name' => 'Dup Account', 'website' => 'https://original.example.com']),
                    'primary' => null,
                    'headers' => ['name', 'website', 'owner', 'tags', $custom],
                    'rows' => [
                        ['Fresh Account', 'https://fresh.example.com', $owner, 'VIP', 'Big'],
                        ['Dup Account', 'https://changed.example.com', $newOwner, 'VIP', 'Big'],
                        ['Bad Account', 'https://bad.example.com', $owner, 'Nonexistent', 'Big'],
                    ],
                    'created' => ['name' => 'Fresh Account'],
                ];

            case DealImporter::class:
                $this->defineCustomField(CustomFieldEntity::Deal);
                $account = Account::factory()->create(['owner_id' => $manager->getKey(), 'name' => 'Horizon Trading']);

                return [
                    'page' => ListDeals::class,
                    'model' => Deal::class,
                    'existing' => Deal::factory()->create(['owner_id' => $manager->getKey(), 'account_id' => $account->getKey(), 'title' => 'Dup Deal', 'amount' => 50]),
                    'primary' => null,
                    'headers' => ['title', 'account', 'amount', 'owner', 'tags', $custom],
                    'rows' => [
                        ['Fresh Deal', 'Horizon Trading', '100.00', $owner, 'VIP', 'Big'],
                        ['Dup Deal', 'Horizon Trading', '999.00', $newOwner, 'VIP', 'Big'],
                        ['Bad Deal', 'Horizon Trading', '1.00', $owner, 'Nonexistent', 'Big'],
                    ],
                    'created' => ['title' => 'Fresh Deal'],
                ];
        }

        $this->fail("no scenario for {$importer}");
    }

    /** One active short-text definition on the entity (D-9), so every importer carries a custom-field column. */
    private function defineCustomField(CustomFieldEntity $entity): CustomField
    {
        return CustomField::factory()->forEntity($entity)->create([
            'key' => self::CUSTOM_FIELD_KEY,
            'label_ar' => 'الميزانية',
            'label_en' => 'Budget',
        ]);
    }

    /** An Import row as Filament saves it before ImportStarted fires: no rows processed, not completed. */
    private function startedRun(User $user): Import
    {
        $import = new Import;
        $import->user()->associate($user);
        $import->forceFill([
            'file_name' => 'leads.csv',
            'file_path' => 'leads.csv',
            'importer' => LeadImporter::class,
            'total_rows' => 1,
        ])->save();

        return $import;
    }

    /**
     * Everything a real import writes besides its own history row: the four
     * entity tables, the tags pivot, the custom-field values, the bell notices
     * and the audit ledger.
     *
     * @return array<string, int>
     */
    private function writtenState(): array
    {
        return [
            'leads' => Lead::query()->count(),
            'contacts' => Contact::query()->count(),
            'accounts' => Account::query()->count(),
            'deals' => Deal::query()->count(),
            'taggables' => DB::table('taggables')->count(),
            'custom_field_values' => DB::table('custom_field_values')->count(),
            'notifications' => DB::table('notifications')->count(),
            'activity_log' => DB::table('activity_log')->count(),
        ];
    }

    /** The completion body of a check-only run with two valid rows and one refused, in the given locale. */
    private function checkOnlyCompletionBody(string $locale): string
    {
        return trans_choice('imports.notifications.dry_run.valid', 2, ['count' => '2'], $locale)
            .' '.trans_choice('imports.notifications.failed', 1, ['count' => '1'], $locale)
            .' '.__('imports.notifications.dry_run.nothing_written', [], $locale);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private static function csv(array $headers, array $rows): string
    {
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return implode("\n", $lines)."\n";
    }
}
