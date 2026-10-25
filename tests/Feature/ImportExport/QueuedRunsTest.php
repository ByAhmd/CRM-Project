<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Enums\WorkspacePanel;
use App\Filament\Exports\LeadExporter;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Exports\Pages\ListExports;
use App\Filament\Resources\Exports\Tables\ExportsTable;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Filament\Resources\Imports\Tables\ImportsTable;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Support\ImportExportActions;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Export;
use App\Models\Import;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Imports and exports the way production runs them (module 18, decisions D-1,
 * A-7 amendment of 2026-10-25): the panel action queues the work on the
 * `database` connection and nothing is written in the request; the scheduler's
 * `queue:work --stop-when-empty --max-time=50` drains it a minute later, in a
 * console process with no current panel, no signed-in user and the
 * application's default locale.
 *
 * The rest of the suite runs the queue on `sync` (phpunit.xml), where the rows
 * are written inside the request and the job never leaves the panel's context,
 * so only these tests see the payload serialised, the chunk jobs restored by a
 * worker, the batch callbacks fire and the completion notice land in the bell.
 */
final class QueuedRunsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        // Production's connection (D-1); phpunit.xml puts the rest of the suite on sync.
        config()->set('queue.default', 'database');
    }

    #[Test]
    public function an_import_started_from_the_panel_waits_on_the_queue_and_the_scheduler_drain_completes_it(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $rep->forceFill(['email' => 'rep@example.com'])->save();
        Tag::factory()->create(['name_en' => 'VIP', 'name_ar' => 'كبار العملاء']);
        Tag::factory()->create(['name_en' => 'Enterprise', 'name_ar' => 'المنشآت']);
        $csv = (string) file_get_contents(base_path('tests/Fixtures/imports/leads.csv'));

        $this->assertSame(0, DB::table('jobs')->count(), 'precondition: an empty queue');

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('leads.csv', $csv),
                'columnMap' => self::columnMapOf($csv),
                'duplicate_strategy' => 'skip',
            ])
            ->assertHasNoActionErrors();

        $requestLocale = app()->getLocale();
        $import = Import::query()->latest('id')->firstOrFail();

        // Nothing is written in the request: the run and its one chunk wait for the drain (D-1).
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(0, $import->processed_rows);
        $this->assertNull($import->completed_at);
        $this->assertSame(ImportResource::STATUS_PROCESSING, ImportResource::status($import));
        $this->assertSame(0, Lead::query()->count());
        $this->assertSame(1, DB::table('jobs')->count(), 'three rows fit one chunk of '.ImportExportActions::import(LeadImporter::class, Lead::class)->getChunkSize());
        $this->assertSame(1, DB::table('job_batches')->whereNull('finished_at')->count());

        // The «started» notice is said in full: Filament's row count, then when
        // the work begins and where it lands, and a button to the history.
        $started = $this->sessionNotification(__('filament-actions::import.notifications.started.title'));
        $this->assertStringContainsString(
            trans_choice('filament-actions::import.notifications.started.body', 3, ['count' => '3']),
            (string) $started['body'],
            'Filament\'s own sentence stays',
        );
        $this->assertStringContainsString(__('imports.notifications.started.body'), (string) $started['body']);
        $this->assertSame(__('imports.actions.track_progress'), $started['actions'][0]['label'] ?? null);
        $this->assertSame(ImportResource::getUrl(), $started['actions'][0]['url'] ?? null);
        $this->assertUrlInWorkspace(WorkspacePanel::Sales, (string) $started['actions'][0]['url']);

        // The history refreshes itself while the run is processing.
        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertCanSeeTableRecords([$import])
            ->assertSee(__('imports.statuses.processing'))
            ->assertSeeHtml('wire:poll.'.ImportsTable::POLL_INTERVAL);

        // The drain runs in the application's default locale, not the importer's.
        $output = $this->drainQueueLikeTheScheduler($requestLocale === 'ar' ? 'en' : 'ar');

        $this->assertSame(0, DB::table('failed_jobs')->count(), $output);
        $this->assertSame(0, DB::table('jobs')->count(), $output);
        $this->assertSame(0, DB::table('job_batches')->whereNull('finished_at')->count(), $output);

        $import->refresh();
        $this->assertSame(3, $import->processed_rows);
        $this->assertSame(3, $import->successful_rows);
        $this->assertNotNull($import->completed_at);
        $this->assertSame(0, $import->getFailedRowsCount());
        $this->assertSame(ImportResource::STATUS_COMPLETED, ImportResource::status($import));

        $this->assertSame(3, Lead::query()->count());

        $fahad = Lead::query()->where('last_name', 'Alqahtani')->firstOrFail();
        $this->assertSame($manager->getKey(), $fahad->owner_id);
        $this->assertSame($manager->getKey(), $fahad->created_by, 'the job runs as the importing user');
        $this->assertEqualsCanonicalizing(['VIP', 'Enterprise'], $fahad->tags->pluck('name_en')->all());

        $noura = Lead::query()->where('last_name', 'العتيبي')->firstOrFail();
        $this->assertSame($rep->getKey(), $noura->owner_id, 'the owner column is honoured inside the importer\'s reach (D-4)');

        // The completion notice lands in the importer's bell, title and body in the run's locale (D-5).
        $completed = $this->databaseNotification($manager, __('filament-actions::import.notifications.completed.title', [], $requestLocale));
        $this->assertSame(trans_choice('imports.notifications.completed', 3, ['count' => '3'], $requestLocale), $completed->data['body'] ?? null);
        $this->assertSame([], $completed->data['actions'] ?? [], 'no failed rows, so no download');

        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertSee(__('imports.statuses.completed'))
            ->assertDontSeeHtml('wire:poll.');
    }

    /**
     * One row per importer through the payload, the worker and the console
     * context, with the lookups the importers share (ResolvesImportLookups:
     * the owner column resolved within reach, the tags column synced after
     * the save). Each importer's own lookups — accounts, parents, stages,
     * contacts, types — are proven by the sync importer tests; this is the
     * transport.
     *
     * @param  class-string  $page
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $where
     */
    #[Test]
    #[DataProvider('importers')]
    public function every_importer_survives_the_queue_and_the_console_drain(string $page, string $csv, string $model, array $where, bool $needsAccount): void
    {
        $manager = $this->salesManager();
        Tag::factory()->create(['name_en' => 'VIP', 'name_ar' => 'كبار العملاء']);
        $csv = str_replace('{owner}', (string) $manager->email, $csv);

        if ($needsAccount) {
            Account::factory()->create(['owner_id' => $manager->getKey(), 'name' => 'Horizon Trading']);
        }

        Livewire::actingAs($manager)
            ->test($page)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('rows.csv', $csv),
                'columnMap' => self::columnMapOf($csv),
                'duplicate_strategy' => 'skip',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(0, $model::query()->where($where)->count(), 'the row waits for the drain');

        $output = $this->drainQueueLikeTheScheduler();

        $this->assertSame(0, DB::table('failed_jobs')->count(), $output);
        $this->assertSame(1, $model::query()->where($where)->count(), $output);

        $record = $model::query()->where($where)->firstOrFail();
        $this->assertSame($manager->getKey(), $record->getAttribute('owner_id'), 'the owner column named the importer');
        $tags = DB::table('taggables')
            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
            ->where('taggables.taggable_type', $record->getMorphClass())
            ->where('taggables.taggable_id', $record->getKey())
            ->pluck('tags.name_en')
            ->all();
        $this->assertSame(['VIP'], $tags, 'the tags column was synced after the save');

        $import = Import::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(0, $import->getFailedRowsCount());
        $this->assertNotNull($import->completed_at);
    }

    /**
     * `{owner}` stands for the importing user's own email.
     *
     * @return array<string, array{class-string, string, class-string<Model>, array<string, string>, bool}>
     */
    public static function importers(): array
    {
        return [
            'leads' => [ListLeads::class, "first_name,last_name,email,owner,tags\nQueued,Lead,queued.lead@example.com,{owner},VIP\n", Lead::class, ['email' => 'queued.lead@example.com'], false],
            'contacts' => [ListContacts::class, "first_name,last_name,email,owner,tags\nQueued,Contact,queued.contact@example.com,{owner},VIP\n", Contact::class, ['email' => 'queued.contact@example.com'], false],
            'accounts' => [ListAccounts::class, "name,owner,tags\nQueued Account,{owner},VIP\n", Account::class, ['name' => 'Queued Account'], false],
            'deals' => [ListDeals::class, "title,account,owner,tags\nQueued Deal,Horizon Trading,{owner},VIP\n", Deal::class, ['title' => 'Queued Deal'], true],
        ];
    }

    #[Test]
    public function a_run_longer_than_one_drain_shows_its_progress_and_finishes_at_the_next(): void
    {
        $manager = $this->salesManager();
        $lines = ['first_name,last_name,email'];

        for ($row = 1; $row <= 250; $row++) {
            $lines[] = sprintf('Row,Number%d,row%d@example.com', $row, $row);
        }

        $csv = implode("\n", $lines)."\n";

        Livewire::actingAs($manager)
            ->test(ListLeads::class)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('leads.csv', $csv),
                'columnMap' => self::columnMapOf($csv),
                'duplicate_strategy' => 'skip',
            ])
            ->assertHasNoActionErrors();

        $import = Import::query()->latest('id')->firstOrFail();
        $this->assertSame(250, $import->total_rows);
        $this->assertSame(3, DB::table('jobs')->count(), 'chunks of 100');

        // A drain stops after 50 seconds and the next minute's drain carries
        // on; one chunk here stands for everything one cut drain got through.
        $this->enterConsoleContext();
        $this->assertSame(0, Artisan::call('queue:work --once --sleep=0 --memory=1024'), Artisan::output());

        $import->refresh();
        $this->assertSame(100, $import->processed_rows);
        $this->assertSame(100, $import->successful_rows);
        $this->assertNull($import->completed_at);
        $this->assertSame(100, Lead::query()->count());
        $this->assertSame(ImportResource::STATUS_PROCESSING, ImportResource::status($import));

        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertSee(__('imports.statuses.processing'))
            ->assertSeeHtml('wire:poll.'.ImportsTable::POLL_INTERVAL);

        $output = $this->drainQueueLikeTheScheduler();

        $this->assertSame(0, DB::table('failed_jobs')->count(), $output);
        $this->assertSame(0, DB::table('jobs')->count(), $output);

        $import->refresh();
        $this->assertSame(250, $import->processed_rows);
        $this->assertSame(250, $import->successful_rows);
        $this->assertNotNull($import->completed_at);
        $this->assertSame(250, Lead::query()->count());
        $this->assertSame(ImportResource::STATUS_COMPLETED, ImportResource::status($import));

        $this->databaseNotification($manager, __('filament-actions::import.notifications.completed.title'));

        Livewire::actingAs($manager)
            ->test(ListImports::class)
            ->assertDontSeeHtml('wire:poll.');
    }

    #[Test]
    public function an_export_started_from_the_panel_is_written_by_the_scheduler_drain_and_announced_with_its_downloads(): void
    {
        Storage::fake('local');
        $rep = $this->salesRep();
        Lead::factory()->count(2)->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->set('activeTab', 'all')
            ->callAction(TestAction::make('export')->table())
            ->assertHasNoActionErrors()
            ->assertNotified(__('filament-actions::export.notifications.started.title'));

        $requestLocale = app()->getLocale();
        $export = Export::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $export->total_rows);
        $this->assertNull($export->completed_at);
        $this->assertFalse($export->hasFile(), 'nothing is written in the request');

        Livewire::actingAs($rep)
            ->test(ListExports::class)
            ->assertCanSeeTableRecords([$export])
            ->assertSeeHtml('wire:poll.'.ExportsTable::POLL_INTERVAL);

        $output = $this->drainQueueLikeTheScheduler($requestLocale === 'ar' ? 'en' : 'ar');

        $this->assertSame(0, DB::table('failed_jobs')->count(), $output);
        $this->assertSame(0, DB::table('jobs')->count(), $output);

        $export->refresh();
        $this->assertSame(2, $export->successful_rows);
        $this->assertNotNull($export->completed_at);
        $this->assertTrue($export->hasFile());
        $this->assertTrue(Storage::disk('local')->exists($export->getFileDirectory().'/headers.csv'));
        $this->assertTrue(Storage::disk('local')->exists($export->getFileDirectory().'/'.$export->file_name.'.xlsx'));

        // Title and body in the run's locale (D-5), with the downloads.
        $completed = $this->databaseNotification($rep, __('filament-actions::export.notifications.completed.title', [], $requestLocale));
        $this->assertSame(trans_choice('exports.notifications.completed', 2, [], $requestLocale), $completed->data['body'] ?? null);
        $this->assertNotEmpty($completed->data['actions'] ?? [], 'the notice carries the downloads');

        Livewire::actingAs($rep)
            ->test(ListExports::class)
            ->assertDontSeeHtml('wire:poll.');
    }

    /**
     * The drain above builds each completion notice in the process that just
     * ran the rows, which the rows had already switched to the run's locale;
     * the title must not depend on that. An import's finally callback can fire
     * after a chunk that failed before its first row, and an export's
     * ExportCompletion is a job chained after the batch that a drain cut at
     * its 50-second cap hands to the next minute's process, which starts in
     * the application's default locale (A-7 amendment).
     */
    #[Test]
    public function the_completion_notice_title_follows_the_run_locale_whatever_locale_the_process_is_in(): void
    {
        $manager = $this->salesManager();

        foreach (['ar' => 'en', 'en' => 'ar'] as $runLocale => $processLocale) {
            $import = new Import;
            $import->user()->associate($manager);
            $import->file_name = 'leads.csv';
            $import->file_path = 'leads.csv';
            $import->importer = LeadImporter::class;
            $import->total_rows = 1;
            $import->save();
            $import->options([ImportExportActions::LOCALE_OPTION => $runLocale]);

            $export = new Export;
            $export->user()->associate($manager);
            $export->exporter = LeadExporter::class;
            $export->file_disk = ImportExportActions::FILE_DISK;
            $export->total_rows = 1;
            $export->save();
            $export->options([ImportExportActions::LOCALE_OPTION => $runLocale]);

            app()->setLocale($processLocale);
            $this->assertSame(__('filament-actions::import.notifications.completed.title', [], $runLocale), LeadImporter::getCompletedNotificationTitle($import));

            app()->setLocale($processLocale);
            $this->assertSame(__('filament-actions::export.notifications.completed.title', [], $runLocale), LeadExporter::getCompletedNotificationTitle($export));
        }

        $this->assertNotSame(
            __('filament-actions::import.notifications.completed.title', [], 'ar'),
            __('filament-actions::import.notifications.completed.title', [], 'en'),
            'precondition: the two locales are told apart by the title',
        );
    }

    /**
     * Runs the scheduler's drain the way production runs it (routes/console.php,
     * D-1): a console process, so no current panel and no signed-in user, in
     * the locale the application starts with rather than the one the run was
     * started in. `--sleep=0` skips the worker's idle pause and `--memory`
     * lifts the cap the test process itself would trip; nothing else differs
     * from the scheduled line. Returns the worker's output for failure messages.
     */
    private function drainQueueLikeTheScheduler(string $consoleLocale = 'ar'): string
    {
        $this->enterConsoleContext($consoleLocale);

        $exit = Artisan::call('queue:work --stop-when-empty --max-time=50 --sleep=0 --memory=1024');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);

        return $output;
    }

    private function enterConsoleContext(string $locale = 'ar'): void
    {
        Filament::setCurrentPanel(null);
        auth()->forgetGuards();
        app()->setLocale($locale);
    }

    /**
     * Every importer column mapped to the CSV header of the same name.
     *
     * @return array<string, string>
     */
    private static function columnMapOf(string $csv): array
    {
        $headers = str_getcsv(explode("\n", $csv, 2)[0], ',', '"', '\\');
        $headers = array_values(array_filter($headers, static fn (?string $header): bool => $header !== null && $header !== ''));

        return array_combine($headers, $headers);
    }

    /**
     * The toast Filament queued for the browser under this title: still in the
     * session, or already claimed for the response by Filament's render hook.
     *
     * @return array<string, mixed>
     */
    private function sessionNotification(string $title): array
    {
        $notifications = [
            ...(array) session('filament.claimed_notifications', []),
            ...(array) session('filament.notifications', []),
        ];

        foreach ($notifications as $notification) {
            if (is_array($notification) && ($notification['title'] ?? null) === $title) {
                return $notification;
            }
        }

        $this->fail("no toast titled {$title} was sent");
    }

    /** The bell notice stored for the user under this title; fails the test when there is none. */
    private function databaseNotification(User $user, string $title): DatabaseNotification
    {
        $notification = DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->get()
            ->first(static fn (DatabaseNotification $notification): bool => ($notification->data['title'] ?? null) === $title);

        $this->assertInstanceOf(DatabaseNotification::class, $notification, "no bell notice titled {$title} for {$user->email}");

        return $notification;
    }
}
